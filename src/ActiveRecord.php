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
 * @property ?int $id the object's primary key
 */
class ActiveRecord implements \ArrayAccess {
	protected static $_meta;

	/**
	 * Creates a new persistent object.
	 * @param ?int $id the object-id for the given type in the database
	 * @param ?array $row some data from the database to set into the properties
	 */
	public function __construct(?int $id = null, ?array $row = null) {
		// This is a basic sanity check; user instantiated the wrong object
		if (static::$_meta == null)
			$this->_error('No metadata available');

		$this->_data[static::$_meta['id']] = $id;

		// Get the database from the pool
		$this->_PDO = DB\DBConnectionManager::getPDO(static::$_meta['database'])
			or $this->_error("No database '".static::$_meta['database']."'");

		// If we already got values for our autoload dataset, then handle them
		if ($row != null) {
			foreach (static::$_meta['datasets'] as $datasetkey => $dataset) if ($dataset['autoload']) {
				$this->_assignDatasetValues($dataset, $row);
				break;
			}
		}
	}

	/**
	 * Magic method for storing the object's data, like into a session.
	 *
	 * @return array<int, string> a numbered array containing the properties to
	 *   serialize
	 */
	public function __sleep() : array {
		return ["\0PHersist\\ActiveRecord\0_data", "\0PHersist\\ActiveRecord\0_changed"];
	}

	/**
	 * Magic method that makes this object whole after restoring it.
	 */
	public function __wakeup() : void {
		$this->relationTypes = array();

		// Get the database from the pool
		$this->_PDO = DB\DBConnectionManager::getPDO(static::$_meta['database'])
			or $this->_error("No database '".static::$_meta['database']."'");
	}

	public function __get(string $key) : mixed {
		if ($key == 'id') return $this->_data[static::$_meta['id']];

		// Determine if the key exists in metadata
		if (!$this->_keyExists($key))
			$this->_error("Property $key does not exist");

		// Restore the key if we don't have its value yet
		if (!array_key_exists($key, $this->_data))
			$this->_restoreKey($key);

		return $this->_data[$key];
	}

	/**
	 * Commits the changes to this object in the database
	 */
	public function commit() : void {
		// Don't bother if nothing has changed, unless this is a new object
		if (count($this->_changed)==0 && $this->id!=null)
			return;

		// Map of form 'tablename' => array('key' => 'prop', ...)
		// We always automatically add our base table here, so it gets processed first for new objects
		$tableUpdates = array(static::$_meta['table'] => array());

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
			if (!isset($tableUpdates[$table])) $tableUpdates[$table] = array();
			$tableUpdates[$table] = array_merge($tableUpdates[$table], $fieldvalues);
		}

		// There may be some automatically updating properties, so we need to process them
		$idfield = static::$_meta['id'];
		foreach (static::$_meta['datasets'] as $dataset)
			foreach ($dataset['props'] as $key => $prop) {
				$type = $this->_getPropertyType($prop['type']);
				if ($type->requiresAutoUpdate($prop)) {
					$fieldvalues = $type->toDB($prop, $this->_data[$key] ?? null);

					// Stuff the values in the $tableUpdates for later when we update the DB
					$table = $dataset['table'];
					if (!isset($tableUpdates[$table])) $tableUpdates[$table] = array();
					$tableUpdates[$table] = array_merge($tableUpdates[$table], $fieldvalues);
				}
			}

		$isNew = $this->id == null;
		$idfield = static::$_meta['id'];
		foreach ($tableUpdates as $table => $updates) {
			$setParts = [];
			if ($this->id == null || $isNew) { // $isNew is for when there are more tables
				// New object, so we insert a new set into the table and retrieve the new id afterwards
				$fields_part = '';
				$values_part = '';
				foreach ($updates as $key => $value) {
					if ($fields_part != '') $fields_part .= ', ';
					if ($values_part != '') $values_part .= ', ';
					$fields_part .= "`$key`";
					$values_part .= ":$key";
				}

				$query = "insert into `$table` ($fields_part) values ($values_part)";

				$stmt = $this->_PDO->prepare($query);
				foreach ($updates as $key => $value) {
					if ($value === null) {
						$stmt->bindValue(':'.$key, null, \PDO::PARAM_NULL);
					} else {
						$stmt->bindValue(':'.$key, $value, \PDO::PARAM_STR);
					}
				}
				$stmt->execute();
				$this->_data[static::$_meta['id']] = $this->_PDO->lastInsertId();
			} elseif (count($updates)>0) { // The check is because we always process our base table
				// Existing object, so update the modified values
				$setpart = '';
				foreach ($updates as $key => $value) {
					if ($setpart != '') $setpart .= ', ';
					$setpart .= "`$key` = :$key";
				}

				$query = "update `$table` set $setpart where `$idfield` = :id";

				$stmt = $this->_PDO->prepare($query);
				foreach ($updates as $key => $value) {
					if ($value === null) {
						$stmt->bindValue(':'.$key, null, \PDO::PARAM_NULL);
					} else {
						$stmt->bindValue(':'.$key, $value, \PDO::PARAM_STR);
					}
				}
				$stmt->bindValue(':id', $this->id, \PDO::PARAM_STR);
				$stmt->execute();
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
	 * Checks if this object exists in the database.
	 * Useful for checking references to objects that may be deleted, though this is typically
	 * a case you want to prevent.
	 *
	 * @return bool if this object exists in the database
	 */
	public function exists() : bool {
		if ($this->id === null)
			return false;

		$exists = false;

		$table = static::$_meta['table'];
		$id = static::$_meta['id'];

		// If we can locate an object with this id, it exists
		$query = "select `$id` from `$table` where `$id` = :id";

		// Account for softdelete situations
		if (static::$_meta['softdelete']) $query .= " and `deleted` = '0'";

		// Perform the check
		$stmt = $this->_PDO->prepare($query);
		$stmt->bindValue(':id', $this->id, \PDO::PARAM_STR);
		$stmt->execute();
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) $exists = true;
		$stmt->closeCursor();

		return $exists;
	}

	/**
	 * Deletes this object from the database.
	 */
	public function delete() : void {
		if ($this->id === null)
			return;

		$table = static::$_meta['table'];
		$id = static::$_meta['id'];

		if (static::$_meta['softdelete']) {
			// We don't delete the relations for a softdelete, in case we need to undelete

			// Softdelete the main record
			$query = "update `$table` set `deleted` = '1' where `$id` = :id";
			$stmt = $this->_PDO->prepare($query);
			$stmt->bindValue(':id', $this->id, \PDO::PARAM_STR);
			$stmt->execute();
		} else {
			// Delete the relations (for which we are table owner)
			foreach (static::$_meta['relations'] as $relname => $relation) if ($relation['table_owner']) {
				$relationType = $this->_getRelationType($relation['type']);
				$relationType->delete($relation);
			}

			foreach (static::$_meta['maps'] as $mapname => $metamap) {
				$map = $this->_data[$mapname];
				$map->delete();
			}

			// Delete the main record
			$query = "delete from `$table` where `$id` = :id";
			$stmt = $this->_PDO->prepare($query);
			$stmt->bindValue(':id', $this->id, \PDO::PARAM_STR);
			$stmt->execute();
		}

		$this->_data[static::$_meta['id']] = null;
	}

	/**
	 * Fetches a specific ActiveRecord instance.
	 *
	 * Currently, this simply creates a new object, but in the future this could use some
	 * form of caching (ideally, when PHP supports weak references).
	 *
	 * Returns an object even if it doesn't actually exist in the database. This
	 * makes this action much faster but somewhat unreliable. If the existence of
	 * the object is not ensured, the exists() method may be used to make sure.
	 *
	 * Return type should be ActiveRecord, but is instead object, so PHPStan
	 * does not find fault when we access named properties on such an object.
	 *
	 * @param string $class the className
	 * @param int $id the id (should be numeric)
	 * @return ?object the ActiveRecord instance, or null if no $id given
	 */
	public static function fetchObject(string $class, ?int $id) : ?object {
		if ($id === null)
			return null;

		$object = self::getCachedObject($class, $id);
		if ($object == null) {
			$object = new $class($id);
			self::cacheObject($object);
		}
		return $object;
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
			if ($this->id == null) { // Don't try to restore anything for new objects
				// TODO It may be a good idea to have default values defined in the XML, for when they
				//      should not be empty strings
				// TODO Also check the field type and decide what default value to set
				// TODO Maybe also set default values for new objects
				$this->_data[$key] = '';
			} else
				$this->_restoreDataset($dataset);
			return;
		}

		// If the key is for a relation, fetch that relation
		if (isset(static::$_meta['relations'][$key])) {
			if ($this->id == null) { // Don't try to restore anything for new objects
				$objects = array();
			} else {
				$relation = static::$_meta['relations'][$key];
				$relationType = $this->_getRelationType($relation['type']);
				$objects = $relationType->restore($relation);
			}
			$this->_data[$key] = $objects;
		}

		// If the key is for a map, fetch that map
		if (isset(static::$_meta['maps'][$key])) {
			$map = new \PHersist\Types\ARPropertyMap($this->_PDO, $this, static::$_meta['maps'][$key]);
			$this->_data[$key] = $map->getArrayAccess();
		}
	}

	/**
	 * Retrieves the dataset that has the requested key.
	 *
	 * @param $key the key we want the dataset for
	 * @return ?array the dataset, if it exists
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
	 * @param array $dataset the dataset to restore
	 */
	private function _restoreDataset(array $dataset) : void {
		$table = $dataset['table'];
		$idfield = static::$_meta['id'];

		// Determine which properties we need to fetch
		$fieldnames = array();
		foreach ($dataset['props'] as $prop)
			$fieldnames = array_merge($fieldnames, $prop['fieldnames']);
		$fieldnames = array_unique($fieldnames);

		// Get the data
		$query = "select `".implode('`,`', $fieldnames)."` from `$table` where `$idfield` = :id";

		$stmt = $this->_PDO->prepare($query);
		$stmt->bindValue(':id', $this->id, \PDO::PARAM_STR);
		$stmt->execute();
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$this->_assignDatasetValues($dataset, $row);
		} else {
			$this->_error('No results for dataset');
		}
		$stmt->closeCursor();
	}

	/**
	 * Puts each value for the dataset into the local data store.
	 *
	 * @param array $dataset the dataset definition
	 * @param array $row the values retrieved from the database
	 * @return void
	 */
	private function _assignDatasetValues(array $dataset, array $row) : void {
		foreach ($dataset['props'] as $propname => $prop) {
			// Retrieve the field values for this property
			// (although, we could just supply the $row instead! TODO: decide)
			$fieldvalues = [];
			foreach ($prop['fieldnames'] as $fieldname)
				$fieldvalues[$fieldname] = $row[$fieldname];

			// Convert the retrieved data according to its type
			$type = $this->_getPropertyType($prop['type']);
			$value = $type->fromDB($prop, $fieldvalues);

			// Set the value (unless it was already locally changed)
			if (!in_array($propname, $this->_changed))
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
		// TODO Check for read-only relations (derived relations)

		if (isset(static::$_meta['maps'][$key])) {
			$this->_error("Property $key is a map and cannot be set");
		}

		if ($this->_keyExists($key)) {
			if ($value === null && $this->_isRequired($key))
				$this->_error("Property $key is required and cannot be set to null");

			// Only update if the new value is not exactly the same as the old one
			// (TODO maybe check for objects with the same ID as well)
			if (!(isset($this->_data[$key]) && $this->_data[$key] === $value)) {
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
	 * Registers a property as changed, so we know to save it when committing to
	 * the database later.
	 *
	 * @param string $key the key to mark as changed (or not)
	 * @param bool $changed whether to mark or unmark it as changed
	 */
	public function setChanged(string $key, bool $changed = true) : void {
		if ($changed) {
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

	private $relationTypes = [];

	/**
	 * Retrieves the metadata for a specific class.
	 *
	 * @param $className the name of the class
	 * @return array the meta for that class
	 */
	public static function _getMeta($className) : array {
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

	/* ---------- caching ----------- */

	/**
	 * Enables or disables the object cache for ALL ActiveRecord instances.
	 *
	 * @param bool $useCache whether or not to cache ActiveRecord instances
	 */
	public static function setUseCache(bool $useCache) : void {
		if ($useCache && self::$_cache == null) self::$_cache = new ObjectCache();
		elseif (!$useCache) self::$_cache = null;
	}

	/**
	 * Retrieves a cached object.
	 *
	 * @param string $objectClass the class of the object
	 * @param mixed $objectId the ID of the object (should be a number)
	 * @return ?ActiveRecord the object instance if found
	 */
	public static function getCachedObject(string $objectClass, mixed $objectId) : ?ActiveRecord {
		$object = self::$_cache != null ? self::$_cache->get($objectClass, $objectId) : null;
		if ($object == null) self::$_cache_misses++; else self::$_cache_hits++;
		return $object;
	}

	/**
	 * Puts an object in the cache.
	 *
	 * @param ActiveRecord $object the object to cache
	 */
	public static function cacheObject(ActiveRecord $object) : void{
		if (self::$_cache != null) self::$_cache->put($object);
	}

	public static $_cache_hits = 0;
	public static $_cache_misses = 0;

	/* ---------- the ArrayAccess methods ----------- */

	function offsetExists($key) : bool { return $key == 'id' || $this->_keyExists($key); }
	function offsetGet($key) : mixed { return $this->__get($key); }
	function offsetSet($key, $value) : void { $this->__set($key, $value); }
	function offsetUnset($offset) : void { $this->_error("Cannot unset property on ActiveRecord"); }

	/** Contains the data for this object */
	private $_data = [];

	/** Contains the list of fields that have changed */
	private $_changed = [];

	/** The PDO database connection */
	protected ?\PDO $_PDO;

	/** The cache */
	protected static $_cache;

	// temporary
	public function getPDO() : ?\PDO { return $this->_PDO; }
}