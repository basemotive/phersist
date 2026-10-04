<?php

namespace PHersist;

/**
 * Represents a persistent object.
 *
 * Data objects that are mapped to a database table extend this class.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 *
 * @implements \ArrayAccess<string, mixed>
 * @property-read ?int $id the object's primary key
 */
class ActiveRecord implements \ArrayAccess {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta;

	/** The alias of the deleted column in rows that restore a softdelete object; the # keeps it apart from column names */
	private const _DELETED_ALIAS = 'sd#deleted';

	/** @var bool $_fetching if fetchObject() is creating an instance right now */
	private static bool $_fetching = false;

	/** @var array<string, true> $_deleting the objects (class:id) that delete() is deleting right now */
	private static array $_deleting = [];

	/**
	 * Creates a new persistent object.
	 *
	 * Use this for new objects only. An existing object is retrieved with
	 * fetch() or fetchObject(), so the ObjectCache can hand out the instance
	 * that is already in use; passing an id here throws an exception.
	 *
	 * @param ?int $id for internal use by fetchObject() only
	 */
	public function __construct(?int $id = null) {
		// Only fetchObject() may create an instance for an existing id
		$fetching = self::$_fetching;
		self::$_fetching = false;
		if ($id !== null && !$fetching)
			$this->_error('Cannot construct an object with an id; use '.static::class.'::fetch($id) instead');

		// This is a basic sanity check; user instantiated the wrong class
		if (static::$_meta == null)
			$this->_error('No metadata available');

		// Get the database from the pool
		$this->_PDO = DB\DBConnectionManager::getPDO(static::$_meta['database'])
			or $this->_error("No database '".static::$_meta['database']."'");

		$this->_data[static::$_meta['id']] = $id;

		if ($id !== null)
			ObjectCache::put($this);
		else
			$this->_applyDefaults();
	}

	/**
	 * Sets the default values from the metadata on a new object.
	 *
	 * The defaults are marked as changed, so they are written to the database
	 * explicitly on the first commit, regardless of the column defaults.
	 */
	private function _applyDefaults() : void {
		foreach (static::$_meta['datasets'] as $dataset)
			foreach ($dataset['props'] as $key => $prop)
				if (array_key_exists('default', $prop)) {
					$this->_data[$key] = $prop['default'];
					$this->_changed[] = $key;
				}
	}

	/**
	 * Self-evict this instance from the ObjectCache when it is GC'd.
	 */
	public function __destruct() {
		ObjectCache::evict($this);
	}

	/**
	 * Magic method for storing the object's data, like into a session.
	 *
	 * @return array<int, string> a numbered array containing the properties to
	 *   serialize
	 */
	public function __sleep() : array {
		return ["\0PHersist\\ActiveRecord\0_data", "\0PHersist\\ActiveRecord\0_changed", "\0PHersist\\ActiveRecord\0_deleted"];
	}

	/**
	 * Magic method that makes this object whole after restoring it.
	 */
	public function __wakeup() : void {
		$this->relationTypes = [];

		// Get the database from the pool
		$this->_PDO = DB\DBConnectionManager::getPDO(static::$_meta['database'])
			or $this->_error("No database '".static::$_meta['database']."'");

		if ($this->id !== null)
			ObjectCache::put($this);
	}

	public function __get(string $key) : mixed {
		if ($key == 'id') return $this->_data[static::$_meta['id']] ?? null;

		// Determine if the key exists in metadata
		if (!$this->_keyExists($key))
			$this->_error("Property $key does not exist");

		// Restore the key if we don't have its value yet
		if (!array_key_exists($key, $this->_data))
			$this->_restoreKey($key);

		return $this->_data[$key];
	}

	/**
	 * Makes isset() and empty() work on properties, following PHP semantics:
	 * a property is set if it exists and its value is not null.
	 *
	 * @param string $key the property name
	 * @return bool if the property exists and is not null
	 */
	public function __isset(string $key) : bool {
		if ($key == 'id') return $this->id !== null;

		return $this->_keyExists($key) && $this->__get($key) !== null;
	}

	/**
	 * Commits the changes to this object in the database
	 */
	public function commit() : void {
		// A deleted object's lifecycle has ended, so it must not be stored again
		if ($this->_deleted)
			$this->_error('Cannot commit a deleted object');

		// Don't bother if nothing has changed, unless this is a new object
		if (count($this->_changed)==0 && $this->id !== null)
			return;

		// A new object must have a value for every required property before it's stored
		if ($this->id === null)
			$this->_checkRequired();

		// Referenced objects need an id before we can store a reference to them
		$this->_checkReferences();

		// If this fails, the transaction restores the state from before, so a
		// new object loses the id it got from the rolled back insert
		$this->_transaction(function() {
			// Referenced objects need a row, which may be softdeleted
			$this->_checkReferencedRows();

			$isNew = $this->id === null;
			$this->_store($isNew);

			// Everything is stored now, so nothing has changed anymore
			$this->_changed = [];

			// A new object has an id now, so it can be found in the ObjectCache
			if ($isNew)
				ObjectCache::put($this);
		});
	}

	/**
	 * Writes the changed properties, relations and maps to the database.
	 *
	 * @param bool $isNew if this object has not been stored before
	 */
	private function _store(bool $isNew) : void {
		// Map of form 'tablename' => [ 'key' => 'prop', ... ]
		// We always automatically add our base table here, so it gets processed first for new objects
		$tableUpdates = [ static::$_meta['table'] => [] ];

		// In the first iteration, we process the properties that belong to datasets
		foreach ($this->_changed as $key) {
			// Find the dataset, but skip this key if it's not in one
			$dataset = $this->_getDatasetFor($key);
			if ($dataset == null) continue;

			// Convert the property value to database values
			$prop = $dataset['props'][$key];
			$type = $this->_getPropertyType($prop['type']);
			$fieldvalues = $type->toDB($prop, $this->_data[$key]);

			// Stuff the values in the $tableUpdates for later when we update the DB
			$table = $dataset['table'];
			if (!isset($tableUpdates[$table])) $tableUpdates[$table] = [];
			$tableUpdates[$table] = array_merge($tableUpdates[$table], $fieldvalues);
		}

		// There may be some automatically updating properties, so we need to process them
		$idfield = static::$_meta['id'];
		foreach (static::$_meta['datasets'] as $dataset)
			foreach ($dataset['props'] as $key => $prop) {
				$type = $this->_getPropertyType($prop['type']);
				if ($type->requiresAutoUpdate($prop)) {
					$fieldvalues = $type->toDB($prop, $this->_data[$key] ?? null);

					// Keep the object in sync with the value that gets stored
					$this->_data[$key] = $type->fromDB($prop, $fieldvalues);

					// Stuff the values in the $tableUpdates for later when we update the DB
					$table = $dataset['table'];
					if (!isset($tableUpdates[$table])) $tableUpdates[$table] = [];
					$tableUpdates[$table] = array_merge($tableUpdates[$table], $fieldvalues);
				}
			}

		$baseTable = static::$_meta['table'];

		// A new object gets a row in every dataset table, even if none of that
		// dataset's properties have been set
		if ($isNew)
			foreach (static::$_meta['datasets'] as $dataset)
				if (!isset($tableUpdates[$dataset['table']])) $tableUpdates[$dataset['table']] = [];

		foreach ($tableUpdates as $table => $updates) {
			if ($isNew) {
				// New object, so we insert a new set into the table. The base table is
				// processed first and hands out the id, which the other dataset tables
				// then receive explicitly.
				if ($table != $baseTable)
					$updates = [ $idfield => $this->id ] + $updates;

				// The placeholders are numbered rather than named after the
				// columns, as column names can contain characters that PDO
				// doesn't allow in a placeholder
				$fields_part = '';
				$values_part = '';
				$index = 0;
				foreach ($updates as $key => $value) {
					if ($fields_part != '') $fields_part .= ', ';
					if ($values_part != '') $values_part .= ', ';
					$fields_part .= "`$key`";
					$values_part .= ':p'.$index++;
				}

				// Without any columns (a base table without set properties), MySQL
				// takes an empty column list, but other databases need DEFAULT VALUES
				if ($fields_part == '' && $this->_PDO->getAttribute(\PDO::ATTR_DRIVER_NAME) != 'mysql')
					$query = "insert into `$table` default values";
				else
					$query = "insert into `$table` ($fields_part) values ($values_part)";

				$stmt = $this->_PDO->prepare($query);
				$index = 0;
				foreach ($updates as $value) {
					if ($value === null) {
						$stmt->bindValue(':p'.$index++, null, \PDO::PARAM_NULL);
					} else {
						$stmt->bindValue(':p'.$index++, $value, \PDO::PARAM_STR);
					}
				}
				$stmt->execute();
				if ($table == $baseTable) {
					$this->_data[$idfield] = (int)$this->_PDO->lastInsertId();
					$this->_rowSeen = true;
				}
			} elseif (count($updates)>0) { // The check is because we always process our base table
				// Existing object, so update the modified values
				// Numbered placeholders, see above; they also can't clash with :id
				// when a column is called 'id'
				$setpart = '';
				$index = 0;
				foreach ($updates as $key => $value) {
					if ($setpart != '') $setpart .= ', ';
					$setpart .= "`$key` = :p".$index++;
				}

				$query = "update `$table` set $setpart where `$idfield` = :id";
				// A softdeleted row must not be changed anymore
				if ($table == $baseTable && static::$_meta['softdelete'])
					$query .= " and `deleted` = '0'";

				$stmt = $this->_PDO->prepare($query);
				$index = 0;
				foreach ($updates as $value) {
					if ($value === null) {
						$stmt->bindValue(':p'.$index++, null, \PDO::PARAM_NULL);
					} else {
						$stmt->bindValue(':p'.$index++, $value, \PDO::PARAM_STR);
					}
				}
				$stmt->bindValue(':id', $this->id, \PDO::PARAM_INT);
				$stmt->execute();

				// The row counts as changed even if its values stay the same, as the
				// MySQL connections use PDO::MYSQL_ATTR_FOUND_ROWS. The transaction
				// rolls back what was already written.
				if ($stmt->rowCount() == 0)
					$this->_error($this->_missingRowMessage($table));
				if ($table == $baseTable)
					$this->_rowSeen = true;
			} elseif ($table == $baseTable && !$this->exists()) {
				// Nothing changes in the base table, but the relations and maps must
				// not be stored for a row that doesn't exist
				$this->_error($this->_missingRowMessage($table));
			}
		}

		// In the next iteration, we process the relations
		foreach ($this->_changed as $key) {
			if (isset(static::$_meta['relations'][$key])) {
				$rel = static::$_meta['relations'][$key];
				if ($rel['table_owner']) { // Only store this relationship if we own the table
					$objects = $this->_data[$key];
					// The actual work is delegated to an ARRelationType instance
					$relationType = $this->_getRelationType($rel['type']);
					$relationType->store($rel, $objects);
				}
			}

			if (isset(static::$_meta['maps'][$key])) {
				$map = $this->_data[$key];
				$map->commit();
			}
		}
	}

	/**
	 * Builds the message for a commit() to a row that doesn't exist.
	 *
	 * @param string $table the table that lacks the row
	 * @return string the message
	 */
	private function _missingRowMessage(string $table) : string {
		if ($table == static::$_meta['table'] && static::$_meta['softdelete'])
			return "Cannot commit, because the row in {$table} doesn't exist or has been softdeleted";
		return "Cannot commit, because the row in {$table} doesn't exist";
	}

	/**
	 * Runs the given function in a Transaction, so its statements are stored
	 * either all or not at all.
	 *
	 * The state of this object is recorded first, so it is restored when this
	 * or an outer Transaction is rolled back. If a transaction is already
	 * active, for example one the application started itself or an outer
	 * commit() or delete(), the function runs in a savepoint inside it, and
	 * the caller that started it decides whether it gets committed.
	 *
	 * @param callable(): void $fn the function that performs the statements
	 */
	private function _transaction(callable $fn) : void {
		// A failed statement must throw, even if the application switched the
		// connection to another error mode. Otherwise the failure would go
		// unnoticed and the other statements would still be committed.
		$errorMode = $this->_PDO->getAttribute(\PDO::ATTR_ERRMODE);
		$this->_PDO->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		try {
			Transaction::_run($this->_PDO, function() use ($fn) {
				Transaction::_record($this->_PDO, $this, fn() => $this->_snapshot());
				$fn();
			});
		} finally {
			$this->_PDO->setAttribute(\PDO::ATTR_ERRMODE, $errorMode);
		}
	}

	/**
	 * Captures the state of this object that commit() and delete() change.
	 *
	 * Properties that are loaded after this are dropped again on restore, so
	 * they get reloaded from the database.
	 *
	 * @return callable(): void a function that restores the captured state
	 */
	private function _snapshot() : callable {
		$data = $this->_data;
		$changed = $this->_changed;
		$deleted = $this->_deleted;
		$rowSeen = $this->_rowSeen;
		$cached = $this->id !== null && ObjectCache::get(static::class, $this->id) === $this;

		// Maps keep their data in their own object, which stays in $data
		$maps = [];
		foreach (array_keys(static::$_meta['maps']) as $key)
			if (isset($this->_data[$key]))
				$maps[] = $this->_data[$key]->_snapshot();

		return function() use ($data, $changed, $deleted, $rowSeen, $cached, $maps) : void {
			// Evict the entry for the id we may have gotten after the snapshot
			ObjectCache::evict($this);

			// Maps loaded after the snapshot are dropped, like in reload()
			foreach (array_keys(static::$_meta['maps']) as $key)
				if (isset($this->_data[$key]) && ($data[$key] ?? null) !== $this->_data[$key])
					$this->_data[$key]->_detach();

			$this->_data = $data;
			$this->_changed = $changed;
			$this->_deleted = $deleted;
			$this->_rowSeen = $rowSeen;
			foreach ($maps as $restore)
				$restore();

			if ($cached)
				ObjectCache::put($this);
		};
	}

	/**
	 * Checks that all required properties of a new object have a value.
	 *
	 * Properties that are filled in automatically on commit, like a DateTime
	 * with update_on="create", don't need to be set.
	 */
	private function _checkRequired() : void {
		$missing = [];
		foreach (static::$_meta['datasets'] as $dataset)
			foreach ($dataset['props'] as $key => $prop) {
				if (!$prop['required'] || ($this->_data[$key] ?? null) !== null)
					continue;
				if ($this->_getPropertyType($prop['type'])->requiresAutoUpdate($prop))
					continue;
				$missing[] = $key;
			}

		if (count($missing) > 0)
			$this->_error('Required '.(count($missing) == 1 ? 'property' : 'properties').' not set: '.implode(', ', $missing));
	}

	/**
	 * Checks that the changed properties and relations don't refer to objects
	 * that haven't been committed yet, or have been hard deleted.
	 *
	 * Such objects have no id, so the reference would end up as NULL in the
	 * database. Referenced objects are not committed automatically.
	 */
	private function _checkReferences() : void {
		$uncommitted = [];
		$deleted = [];
		foreach ($this->_referencedObjects() as [$key, $object])
			if ($object->id === null) {
				// A hard deleted object has lost its id as well
				if ($object->_deleted)
					$deleted[$key] = $key;
				else
					$uncommitted[$key] = $key;
			}

		if (count($deleted) > 0)
			$this->_error('Cannot commit a reference to an object that has been deleted: '.implode(', ', $deleted));
		if (count($uncommitted) > 0)
			$this->_error('Cannot commit a reference to an object that has not been committed itself: '.implode(', ', $uncommitted));
	}

	/**
	 * Checks that the objects referred to by the changed properties and
	 * relations have a row in the database. Softdeleted rows count as well.
	 *
	 * Objects whose row has been seen already, because they were inserted or
	 * loaded, are not checked again. The others are checked with one query per
	 * class. Without foreign keys, this can't rule out that another request
	 * hard deletes a row in the meantime.
	 */
	private function _checkReferencedRows() : void {
		// Map of form 'classname' => [ id => [ [key, object], ... ] ]
		$unseen = [];
		foreach ($this->_referencedObjects() as [$key, $object])
			if (!$object->_rowSeen)
				$unseen[get_class($object)][$object->id][] = [$key, $object];

		$missing = [];
		foreach ($unseen as $class => $references) {
			$meta = self::_getMeta($class);
			$table = $meta['table'];
			$idfield = $meta['id'];
			$PDO = reset($references)[0][1]->_PDO;

			// Chunked, as databases limit the number of placeholders in a query
			foreach (array_chunk(array_keys($references), 500) as $ids) {
				$placeholders = implode(', ', array_map(fn($index) => ':p'.$index, array_keys($ids)));
				$stmt = $PDO->prepare("select `$idfield` from `$table` where `$idfield` in ($placeholders)");
				foreach ($ids as $index => $id)
					$stmt->bindValue(':p'.$index, $id, \PDO::PARAM_INT);
				$stmt->execute();
				$found = [];
				while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
					$found[(int)$row[$idfield]] = true;
				$stmt->closeCursor();

				foreach ($ids as $id)
					foreach ($references[$id] as [$key, $object]) {
						if (isset($found[$id]))
							$object->_rowSeen = true;
						else
							$missing[$key] = $key;
					}
			}
		}

		if (count($missing) > 0)
			$this->_error("Cannot commit a reference to an object that doesn't exist: ".implode(', ', $missing));
	}

	/**
	 * Lists the objects that the changed properties and owned relations refer to.
	 *
	 * @return list<array{string, ActiveRecord}> pairs of the key and the object
	 */
	private function _referencedObjects() : array {
		$objects = [];
		foreach ($this->_changed as $key) {
			$value = $this->_data[$key] ?? null;

			if ($this->_getDatasetFor($key) != null) {
				if ($value instanceof ActiveRecord)
					$objects[] = [$key, $value];
			} elseif (isset(static::$_meta['relations'][$key]) && static::$_meta['relations'][$key]['table_owner']) {
				foreach ((array)$value as $object)
					if ($object instanceof ActiveRecord)
						$objects[] = [$key, $object];
			}
		}
		return $objects;
	}

	/**
	 * Checks if this object exists in the database.
	 *
	 * Useful for checking if an object that you want to access by id (for example
	 * because you pass id's in HTTP queries) actually exists in the database.
	 *
	 * This returns false by default for softdeleted objects, unless you pass
	 * true for the $softdeletedExists parameter.
	 *
	 * @param bool $softdeletedExists whether to treat softdeleted objects as still existing
	 * @return bool if this object exists in the database
	 */
	public function exists(bool $softdeletedExists = false) : bool {
		if ($this->id === null)
			return false;

		$exists = false;

		$table = static::$_meta['table'];
		$id = static::$_meta['id'];

		// If we can locate an object with this id, it exists
		$query = "select `$id` from `$table` where `$id` = :id";

		// Account for softdelete situations
		if (static::$_meta['softdelete'] && !$softdeletedExists)
			$query .= " and `deleted` = '0'";

		// Perform the check
		$stmt = $this->_PDO->prepare($query);
		$stmt->bindValue(':id', $this->id, \PDO::PARAM_INT);
		$stmt->execute();
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) $exists = true;
		$stmt->closeCursor();

		if ($exists)
			$this->_rowSeen = true;

		return $exists;
	}

	/**
	 * Checks if this object has been deleted with delete(), after which it
	 * cannot be modified or committed anymore.
	 *
	 * @return bool if this object has been deleted
	 */
	public function isDeleted() : bool {
		return $this->_deleted;
	}

	/**
	 * Tells this object that its base table row has been seen in the database,
	 * so commit() doesn't check that row when another object refers to it.
	 * For internal use by queries that only return existing objects, like
	 * those of ObjectFinder and relations.
	 */
	public function _markRowSeen() : void {
		$this->_rowSeen = true;
	}

	/**
	 * Forgets the loaded properties, relations and maps, so they are loaded
	 * from the database again when they are next accessed.
	 *
	 * @param bool $discard whether to discard uncommitted changes; without it,
	 *   reloading an object with uncommitted changes throws an exception
	 */
	public function reload(bool $discard = false) : void {
		// A softdeleted object can still be read, so it can be reloaded as well
		if ($this->_deleted && !static::$_meta['softdelete'])
			$this->_error('Cannot reload a deleted object');

		// A new object has nothing in the database to reload from
		if ($this->id === null)
			$this->_error('Cannot reload an object that has not been committed');

		if (count($this->_changed) > 0 && !$discard)
			$this->_error('Cannot reload an object with uncommitted changes');

		// A map that the application still holds must not mark this object as
		// changed anymore, because commit() wouldn't find it
		foreach (array_keys(static::$_meta['maps']) as $key)
			if (isset($this->_data[$key]))
				$this->_data[$key]->_detach();

		$this->_data = [static::$_meta['id'] => $this->id];
		$this->_changed = [];
	}

	/**
	 * Deletes this object from the database.
	 *
	 * For a class with softdelete, the object keeps its id and stays in the
	 * ObjectCache, because objects that refer to it keep working: it can still
	 * be read, but not changed or committed anymore.
	 */
	public function delete() : void {
		if ($this->id === null || $this->_deleted)
			return;

		// Cascading deletes may lead back to an object that is being deleted
		// already; that one finishes its own deletion
		$key = static::class.':'.$this->id;
		if (isset(self::$_deleting[$key]))
			return;

		self::$_deleting[$key] = true;
		try {
			// If this or an outer transaction fails, the object is restored
			$this->_transaction(function() {
				$this->_deleteRows();

				// A hard deleted object is gone from the database, so it loses its
				// id and its place in the ObjectCache
				if (!static::$_meta['softdelete']) {
					ObjectCache::evict($this);
					$this->_data[static::$_meta['id']] = null;
					$this->_rowSeen = false;
				}
				$this->_deleted = true;
			});
		} finally {
			unset(self::$_deleting[$key]);
		}
	}

	/**
	 * Deletes (or softdeletes) the rows of this object from the database.
	 */
	private function _deleteRows() : void {
		$table = static::$_meta['table'];
		$id = static::$_meta['id'];

		if (static::$_meta['softdelete']) {
			// We don't delete the relations for a softdelete, in case we need to undelete

			// Softdelete the main record
			$query = "update `$table` set `deleted` = '1' where `$id` = :id";
			$stmt = $this->_PDO->prepare($query);
			$stmt->bindValue(':id', $this->id, \PDO::PARAM_INT);
			$stmt->execute();
		} else {
			// Clean up the relations, so no rows keep referring to us
			foreach (static::$_meta['relations'] as $relname => $relation) {
				$relationType = $this->_getRelationType($relation['type']);
				$relationType->delete($relation);
			}

			// Clean up the references from other classes that aren't covered by
			// one of our relations
			$referenceCleaner = new ReferenceCleaner($this);
			foreach (static::$_meta['references'] ?? [] as $reference)
				$referenceCleaner->cleanUp($reference);

			foreach (static::$_meta['maps'] as $mapname => $metamap) {
				// Use __get(), because the map only exists once it has been accessed
				$map = $this->__get($mapname);
				$map->delete();
			}

			// Delete the rows of the datasets that live in their own table
			$datasetTables = array_unique(array_column(static::$_meta['datasets'], 'table'));
			foreach ($datasetTables as $datasetTable) if ($datasetTable != $table) {
				$query = "delete from `$datasetTable` where `$id` = :id";
				$stmt = $this->_PDO->prepare($query);
				$stmt->bindValue(':id', $this->id, \PDO::PARAM_INT);
				$stmt->execute();
			}

			// Delete the main record
			$query = "delete from `$table` where `$id` = :id";
			$stmt = $this->_PDO->prepare($query);
			$stmt->bindValue(':id', $this->id, \PDO::PARAM_INT);
			$stmt->execute();
		}
	}

	/**
	 * Fetches the instance of this class with the given id.
	 *
	 * This is a shortcut for fetchObject() with the class it is called on, like
	 * User::fetch(123).
	 *
	 * @param ?int $id the id
	 * @return ?static the ActiveRecord instance, or null if no $id given
	 */
	public static function fetch(?int $id) : ?static {
		return self::fetchObject(static::class, $id);
	}

	/**
	 * Fetches a specific ActiveRecord instance.
	 *
	 * With the ObjectCache enabled, the instance that is already in use for this
	 * class and id is returned; otherwise a new one is created.
	 *
	 * Returns an object even if it doesn't actually exist in the database. This
	 * makes this action much faster but somewhat unreliable. If the existence of
	 * the object is not ensured, the exists() method may be used to make sure.
	 * Committing changes to an object without a row throws an exception.
	 *
	 * The return type is templated on $class, so static analysis knows the
	 * concrete class (and its @property declarations) of the returned object.
	 *
	 * @template T of ActiveRecord
	 * @param class-string<T> $class the className
	 * @param ?int $id the id
	 * @param ?array<string, mixed> $row some data from the database to set into
	 *   the properties, and for a softdelete class its deleted column
	 * @return ?T the ActiveRecord instance, or null if no $id given
	 */
	public static function fetchObject(string $class, ?int $id, ?array $row = null) : ?object {
		if ($id === null)
			return null;

		// The cache is keyed on get_class(), which has no leading backslash
		/** @var class-string<T> $class */
		$class = ltrim($class, '\\');
		$object = ObjectCache::get($class, $id);
		if ($object === null) {
			// Tells the constructor that it may accept an id this time
			self::$_fetching = true;
			$object = new $class($id);
		}

		// If we got values for datasets, then handle them. The row holds their
		// columns under the aliases from _datasetAlias(); datasets that aren't in
		// it are restored when they're needed. The whole row is checked before
		// anything is assigned, so a bad row leaves the object as it was.
		if ($row != null) {
			$aliases = [];
			$assignments = [];
			foreach ($class::$_meta['datasets'] as $datasetkey => $dataset) {
				$values = [];
				$missing = [];
				foreach ($dataset['props'] as $prop)
					foreach ($prop['fieldnames'] as $fieldname) {
						$alias = self::_datasetAlias($dataset, $datasetkey, $fieldname);
						$aliases[$alias] = true;
						if (array_key_exists($alias, $row))
							$values[$fieldname] = $row[$alias];
						else
							$missing[] = $alias;
					}
				if (count($values) == 0)
					continue;
				// A dataset is always loaded as a whole
				if (count($missing) > 0)
					$object->_error("The row has only part of a dataset, it lacks ".implode(', ', array_unique($missing)));
				$assignments[] = [$dataset, $values];
			}

			// A ds_ column that isn't one of ours is most likely a typo, which would
			// otherwise go unnoticed
			$unknown = array_filter(array_keys($row), fn($key) => str_starts_with((string) $key, 'ds_') && !isset($aliases[$key]));
			if (count($unknown) > 0)
				$object->_error("The row has columns that don't belong to any of its datasets: ".implode(', ', $unknown));

			foreach ($assignments as [$dataset, $values])
				$object->_assignDatasetValues($dataset, $values);

			// The deleted column of a softdelete class, see _getDeletedColumn()
			if ($class::$_meta['softdelete'] && array_key_exists(self::_DELETED_ALIAS, $row))
				$object->_deleted = (bool)(int)$row[self::_DELETED_ALIAS];
		}

		return $object;
	}

	/**
	 * Lists the columns of all autoload datasets of a class. Queries that
	 * restore full objects select these under their alias, so fetchObject() can
	 * assign them from the resulting row. The alias keeps columns with the same
	 * name in different dataset tables apart.
	 *
	 * @param string $className the name of the class
	 * @return list<array{table: string, field: string, alias: string}> the columns
	 */
	public static function _getAutoloadColumns(string $className) : array {
		$columns = [];
		foreach ($className::$_meta['datasets'] as $datasetkey => $dataset) if ($dataset['autoload']) {
			$fieldnames = [];
			foreach ($dataset['props'] as $prop)
				$fieldnames = array_merge($fieldnames, $prop['fieldnames']);
			foreach (array_unique($fieldnames) as $fieldname)
				$columns[] = [
					'table' => $dataset['table'],
					'field' => $fieldname,
					'alias' => self::_datasetAlias($dataset, $datasetkey, $fieldname),
				];
		}
		return $columns;
	}

	/**
	 * Returns the column that queries which restore objects of a softdelete
	 * class select from its base table, so fetchObject() knows whether the
	 * object has been deleted.
	 *
	 * @param string $className the name of the class
	 * @return ?array{field: string, alias: string} the column, or null if the
	 *   class doesn't use softdelete
	 */
	public static function _getDeletedColumn(string $className) : ?array {
		if (!$className::$_meta['softdelete'])
			return null;
		return ['field' => 'deleted', 'alias' => self::_DELETED_ALIAS];
	}

	/**
	 * Returns the alias under which a column of a dataset is selected:
	 * ds_<name>#<column> for a named dataset, or ds_<position>#<column> with
	 * the position of the dataset in the class (starting at 1) otherwise.
	 *
	 * @param array<string, mixed> $dataset the dataset definition
	 * @param int $datasetkey the index of the dataset in the class's meta
	 * @param string $fieldname the name of the column
	 * @return string the alias
	 */
	private static function _datasetAlias(array $dataset, int $datasetkey, string $fieldname) : string {
		$name = $dataset['name'] ?? $datasetkey + 1;
		return "ds_{$name}#{$fieldname}";
	}

	/**
	 * Restores the requested key. If the key is part of a dataset, the entire
	 * dataset is restored with it.
	 *
	 * @param $key the key to restore
	 */
	private function _restoreKey(string $key) : void {
		// If the key is in a dataset, restore that dataset
		$dataset = $this->_getDatasetFor($key);
		if ($dataset != null) {
			if ($this->id === null) { // Don't try to restore anything for new objects
				// Properties with a default already got it on construction, so this
				// property has no value yet
				$this->_data[$key] = null;
			} else
				$this->_restoreDataset($dataset);
			return;
		}

		// If the key is for a relation, fetch that relation
		if (isset(static::$_meta['relations'][$key])) {
			if ($this->id === null) { // Don't try to restore anything for new objects
				$objects = [];
			} else {
				$relation = static::$_meta['relations'][$key];
				$relationType = $this->_getRelationType($relation['type']);
				$objects = $relationType->restore($relation);
			}
			$this->_data[$key] = $objects;
		}

		// If the key is for a map, fetch that map
		if (isset(static::$_meta['maps'][$key])) {
			$map = new \PHersist\Maps\MapStorage($this, static::$_meta['maps'][$key]);
			$this->_data[$key] = $map->getArrayAccess();
		}
	}

	/**
	 * Retrieves the dataset that has the requested key.
	 *
	 * @param $key the key we want the dataset for
	 * @return ?array<string, mixed> the dataset, if it exists
	 */
	private function _getDatasetFor(string $key) : ?array {
		foreach (static::$_meta['datasets'] as $datasetkey => $dataset)
			if (array_key_exists($key, $dataset['props']))
				return $dataset;
		return null;
	}

	/**
	 * Restores the properties from a specific dataset
	 *
	 * @param array<string, mixed> $dataset the dataset to restore
	 */
	private function _restoreDataset(array $dataset) : void {
		$table = $dataset['table'];
		$idfield = static::$_meta['id'];

		// Determine which properties we need to fetch
		$fieldnames = [];
		foreach ($dataset['props'] as $prop)
			$fieldnames = array_merge($fieldnames, $prop['fieldnames']);
		$fieldnames = array_unique($fieldnames);

		// The base table of a softdelete class also tells us if the object has
		// been deleted
		$loadDeleted = $table == static::$_meta['table'] && static::$_meta['softdelete'];
		$columns = '`'.implode('`,`', $fieldnames).'`';
		if ($loadDeleted)
			$columns .= ", `deleted` as `".self::_DELETED_ALIAS."`";

		// Get the data
		$query = "select $columns from `$table` where `$idfield` = :id";

		$stmt = $this->_PDO->prepare($query);
		$stmt->bindValue(':id', $this->id, \PDO::PARAM_INT);
		$stmt->execute();
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$this->_assignDatasetValues($dataset, $row);
			if ($loadDeleted)
				$this->_deleted = (bool)(int)$row[self::_DELETED_ALIAS];
			if ($table == static::$_meta['table'])
				$this->_rowSeen = true;
		} else {
			$this->_error('No results for dataset');
		}
		$stmt->closeCursor();
	}

	/**
	 * Puts each value for the dataset into the local data store.
	 *
	 * @param array<string, mixed> $dataset the dataset definition
	 * @param array<string, mixed> $row the values retrieved from the database
	 * @return void
	 */
	private function _assignDatasetValues(array $dataset, array $row) : void {
		foreach ($dataset['props'] as $propname => $prop) {
			// Don't do anything with this property if the user has already changed it
			if (in_array($propname, $this->_changed))
				continue;

			// Retrieve the field values for this property
			// (although, we could just supply the $row instead! TODO: decide)
			$fieldvalues = [];
			foreach ($prop['fieldnames'] as $fieldname)
				$fieldvalues[$fieldname] = $row[$fieldname];

			// Convert the retrieved data according to its type
			$type = $this->_getPropertyType($prop['type']);
			$value = $type->fromDB($prop, $fieldvalues);

			// Set the value
			$this->_data[$propname] = $value;
		}
	}

	/**
	 * Sets a property on this object.
	 *
	 * @param string $key the key for the property
	 * @param mixed $value the value
	 */
	public function __set(string $key, mixed $value) : void {
		// A deleted object's lifecycle has ended, so it must not be modified anymore
		if ($this->_deleted)
			$this->_error("Cannot set property $key on a deleted object");

		// Changes to a relation we don't own would never be stored
		if ($this->_isReadOnlyRelation($key))
			$this->_error("Relation $key is read-only (table_owner is false) and cannot be set");

		if (isset(static::$_meta['maps'][$key])) {
			// Replace the contents of the map; the map object itself stays in place
			if (!is_array($value) && !($value instanceof Maps\Map))
				$this->_error("Property $key is a map and can only be set to an array");
			$this->__get($key)->set($value);
			return;
		}

		if (isset(static::$_meta['relations'][$key]))
			$value = $this->_normalizeRelation($key, $value);

		if ($this->_keyExists($key)) {
			if ($value === null && $this->_isRequired($key))
				$this->_error("Property $key is required and cannot be set to null");

			// Let the property type convert the value, like date strings to DateTimeImmutable
			$dataset = $this->_getDatasetFor($key);
			if ($dataset != null && $value !== null) {
				$prop = $dataset['props'][$key];
				try {
					$value = $this->_getPropertyType($prop['type'])->normalize($prop, $value);
				} catch (\InvalidArgumentException $e) {
					$this->_error("Invalid value for property $key: ".$e->getMessage());
				}
			}

			// Only update if the new value is not the same as the old one
			// (TODO maybe check for objects with the same ID as well)
			$oldValue = $this->_data[$key] ?? null;
			$same = array_key_exists($key, $this->_data) && ($oldValue === $value
				|| ($oldValue instanceof \DateTimeInterface && $value instanceof \DateTimeInterface && $oldValue == $value));
			if (!$same) {
				// Set the new value
				$this->_data[$key] = $value;

				// Register the value as changed for the next commit
				if (!in_array($key, $this->_changed))
					$this->_changed[] = $key;
			}
		} else {
			if ($key == 'id')
				$this->_error("Cannot set the id property");
			else
				$this->_error("Property $key does not exist");
		}
	}

	/**
	 * Checks a value assigned to a relation and returns it as a list.
	 *
	 * The value must be an array of instances of the related class. Unless the
	 * relation has an order_field, an object may only occur once; objects are
	 * the same if they have the same id, or are the same instance if they have
	 * no id yet.
	 *
	 * @param string $key the relation name
	 * @param mixed $value the assigned value
	 * @return list<ActiveRecord> the related objects
	 */
	private function _normalizeRelation(string $key, mixed $value) : array {
		$rel = static::$_meta['relations'][$key];
		if (!is_array($value))
			$this->_error("Relation $key can only be set to an array of {$rel['class']} objects");

		$objects = [];
		$seen = [];
		foreach ($value as $object) {
			if (!($object instanceof $rel['class']))
				$this->_error("Relation $key can only contain {$rel['class']} objects, ".get_debug_type($object)." given");

			if (!isset($rel['order_field'])) {
				$identity = $object->id !== null ? 'id:'.$object->id : 'object:'.spl_object_id($object);
				if (isset($seen[$identity]))
					$this->_error("Relation $key cannot contain the same object more than once"
						.($object->id !== null ? " ({$rel['class']} {$object->id})" : ''));
				$seen[$identity] = true;
			}

			$objects[] = $object;
		}
		return $objects;
	}

	/**
	 * Registers a property as changed, so we know to save it when committing to
	 * the database later.
	 *
	 * Public only so MapStorage can mark its map as changed; not meant to be
	 * called from application code.
	 *
	 * @internal
	 * @param string $key the key to mark as changed (or not)
	 * @param bool $changed whether to mark or unmark it as changed
	 */
	public function setChanged(string $key, bool $changed = true) : void {
		if ($changed) {
			if ($this->_deleted)
				$this->_error("Cannot change property $key on a deleted object");

			// Register the value as changed for the next commit
			if (!in_array($key, $this->_changed))
				$this->_changed[] = $key;
		} else {
			// Mark the property as not changed
			if (($index = array_search($key, $this->_changed))!==false)
				unset($this->_changed[$index]);
		}
	}

	/**
	 * Checks if a key is a relation that this class doesn't own (table_owner
	 * is false), like a derived relation. Such relations are never stored.
	 *
	 * @param string $key the key to check
	 * @return bool if the key is a read-only relation
	 */
	private function _isReadOnlyRelation(string $key) : bool {
		return isset(static::$_meta['relations'][$key]) && !static::$_meta['relations'][$key]['table_owner'];
	}

	/**
	 * Checks if a key exists in the metadata
	 *
	 * @param string $key the key to check for
	 * @return bool if the key exists
	 */
	private function _keyExists(string $key) : bool {
		// Check the datasets
		foreach (static::$_meta['datasets'] as $dataset)
			if (array_key_exists($key, $dataset['props'])) return true;

		// Check the relations
		if (isset(static::$_meta['relations'][$key]))
			return true;

		if (isset(static::$_meta['maps'][$key]))
			return true;

		return false;
	}

	/**
	 * Checks if a property is required.
	 *
	 * If it's not required, it can receive the null value.
	 *
	 * @param string $property the property to check for
	 * @return bool if the key exists
	 */
	private function _isRequired(string $property) : bool {
		// Check the datasets
		foreach (static::$_meta['datasets'] as $dataset)
			if (array_key_exists($property, $dataset['props']))
				return $dataset['props'][$property]['required'];

		// relations and maps can never be set to null
		return true;
	}

	/**
	 * Returns an ARPropertyType object for the requested type. This method
	 * caches them, so that only one instance of each type is created every time.
	 *
	 * @param string $type the type name, like Text, Class, etc.
	 * @return Types\ARPropertyType the type instance
	 */
	private function _getPropertyType(string $type) : Types\ARPropertyType {
		$fullType = __NAMESPACE__."\\Types\\ARPropertyType$type";
		if (!isset($this->propertyTypes[$fullType]))
			$this->propertyTypes[$fullType] = new $fullType($this);
		return $this->propertyTypes[$fullType];
	}

	/** @var array<string, Types\ARPropertyType> */
	private array $propertyTypes = [];

	/**
	 * Returns an ARRelationType object for the requested type. This method
	 * caches them, so that only one instance of each type is created every time.
	 *
	 * @param string $type the type name, like NN
	 * @return Types\ARRelationType the type instance
	 */
	private function _getRelationType(string $type) : Types\ARRelationType {
		$fullType = __NAMESPACE__."\\Types\\ARRelationType$type";
		if (!isset($this->relationTypes[$fullType]))
			$this->relationTypes[$fullType] = new $fullType($this->_PDO, $this);
		return $this->relationTypes[$fullType];
	}

	/**
	 * Retrieves the metadata for a specific class.
	 *
	 * @param string $className the name of the class
	 * @return array<string, mixed> the meta for that class
	 */
	public static function _getMeta(string $className) : array {
		return $className::$_meta;
	}

	/**
	 * Throws an Exception when an unrecoverable error occurs.
	 *
	 * @param string $message the error message
	 */
	public function _error(string $message) : void {
		throw new \Exception(get_class($this) .':'.$this->id .': ' . $message);
	}

	/* ---------- the ArrayAccess methods ----------- */

	function offsetExists($key) : bool { return $this->__isset($key); }
	function offsetGet($key) : mixed { return $this->__get($key); }
	function offsetSet($key, $value) : void { $this->__set($key, $value); }
	function offsetUnset($offset) : void { $this->_error("Cannot unset property on ActiveRecord"); }

	/** @var array<string, Types\ARRelationType> */
	private array $relationTypes = [];

	/** @var array<string, mixed> */
	private array $_data = [];

	/** @var list<string> */
	private array $_changed = [];

	/** If this object has been deleted, after which it cannot be modified or committed again */
	private bool $_deleted = false;

	/**
	 * If this object's base table row has been seen in the database, so a
	 * reference to it doesn't need to be checked on commit. Not serialized,
	 * as the row may be gone in a later request.
	 */
	private bool $_rowSeen = false;

	/** The PDO database connection */
	protected ?\PDO $_PDO;
}