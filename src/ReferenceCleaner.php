<?php

namespace PHersist;

/**
 * Cleans up the references to an object that is being deleted, so no rows
 * keep referring to it.
 *
 * A reference is held by a Class or DynamicClass property of another object,
 * or by a row in a join table. What happens to a property reference depends
 * on its on_remote_delete policy: 'null' sets it to NULL, 'restrict' refuses
 * the deletion while it exists, and 'cascade' deletes the referring object.
 * Join table rows are simply deleted.
 *
 * This is used by ActiveRecord::delete() and the relation types; it isn't
 * meant to be used directly.
 *
 * @internal
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ReferenceCleaner {
	/**
	 * @param ActiveRecord $activeRecord the object that is being deleted
	 */
	public function __construct(ActiveRecord $activeRecord) {
		$this->activeRecord = $activeRecord;
	}

	/**
	 * Cleans up the references to the deleted object, from the
	 * PHersistReferences of its namespace and the registered ones (see
	 * ReferenceRegistry). References in a table that one of the object's own
	 * relations already cleans up are skipped.
	 *
	 * Those with on_remote_delete="restrict" are checked last, so a reference
	 * from an object that another reference cascades to doesn't stop the
	 * deletion.
	 */
	public function cleanUpReferences() : void {
		$class = get_class($this->activeRecord);
		$covered = [];
		foreach (ActiveRecord::_getMeta($class)['relations'] as $relation)
			$covered[] = "{$relation['table']}.{$relation['local_id']}";

		$references = [];
		$restricting = [];
		foreach (ReferenceRegistry::referencesTo($class) as $reference) {
			$column = isset($reference['relation']) ? $reference['remote_id'] : end($reference['fieldnames']);
			if (in_array("{$reference['table']}.{$column}", $covered))
				continue;
			if (($reference['policy'] ?? null) == 'restrict')
				$restricting[] = $reference;
			else
				$references[] = $reference;
		}

		foreach (array_merge($references, $restricting) as $reference)
			$this->cleanUp($reference);
	}

	/**
	 * Cleans up one reference to the deleted object.
	 *
	 * @param array<string, mixed> $reference the reference, from the REFERENCES of
	 *   a PHersistReferences class: the referring 'class', its 'database', the
	 *   'table' that holds the reference, and either the join table
	 *   'relation' with its 'remote_id' and 'local_type' column and value, or the
	 *   'property' with its 'type', 'fieldnames' and 'policy', and the 'id',
	 *   'base_table' and 'softdelete' of the referring class
	 */
	private function cleanUp(array $reference) : void {
		if (isset($reference['relation'])) {
			$this->_deleteJoinRows($reference);
			return;
		}

		// A DynamicClass property also stores the class name, in the form its
		// type writes it
		if ($reference['type'] == 'DynamicClass')
			$match = (new Types\ARPropertyTypeDynamicClass())->toDB($reference, $this->activeRecord);
		else
			$match = [ $reference['fieldnames'][0] => $this->activeRecord->id ];

		$this->clear($reference, $reference['table'], $match, $reference['policy'], $reference['property']);
	}

	/**
	 * Cleans up the rows of a class that refer to the deleted object.
	 *
	 * The rows include those of softdeleted objects. With 'null', their
	 * references are set to NULL as well, so they don't refer to a missing
	 * object when they are undeleted, and with 'restrict' they count too. With
	 * 'cascade', softdeleted objects aren't deleted again; objects of a class
	 * with softdelete are softdeleted, and keep their reference.
	 *
	 * Only 'cascade' loads the class, if there are objects to delete.
	 *
	 * @param array{class: class-string<ActiveRecord>, database: string, id: string, base_table: string, softdelete: bool, ...} $referrer
	 *   the class that refers to the deleted object: its name, database, id
	 *   column, base table and whether it has softdelete
	 * @param string $table the table of that class that holds the references
	 * @param array<string, mixed> $match the column values that make up the reference
	 * @param string $policy 'null', 'restrict' or 'cascade'
	 * @param ?string $propName the property that holds the reference, for the error message
	 */
	public function clear(array $referrer, string $table, array $match, string $policy, ?string $propName) : void {
		$class = $referrer['class'];
		$PDO = $this->_getPDO($referrer['database']);

		// Numbered placeholders, as column names can contain characters that
		// PDO doesn't allow in a placeholder
		$columns = array_keys($match);
		$where = implode(' and ', array_map(fn($index) => "`$table`.`{$columns[$index]}` = :p$index", array_keys($columns)));
		$bind = function(\PDOStatement $stmt) use ($match) : void {
			foreach (array_values($match) as $index => $value)
				$stmt->bindValue(":p$index", $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
		};

		if ($policy == 'cascade') {
			$baseTable = $referrer['base_table'];
			$idField = $referrer['id'];

			$query = "select `$table`.`$idField` from `$table`";
			if ($referrer['softdelete']) {
				if ($table != $baseTable)
					$query .= " inner join `$baseTable` on `$baseTable`.`$idField` = `$table`.`$idField`";
				$where .= " and `$baseTable`.`deleted` = '0'";
			}
			$stmt = $PDO->prepare("$query where $where");
			$bind($stmt);
			$stmt->execute();
			$ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
			$stmt->closeCursor();

			if ($ids && !class_exists($class))
				$this->activeRecord->_error("Cannot cascade the deletion to {$class} objects through the property '{$propName}': class {$class} doesn't exist."
					.' Make sure the classes of every installed model XML can be autoloaded');
			foreach ($ids as $id)
				ActiveRecord::fetchObject($class, (int)$id)?->delete();
		} elseif ($policy == 'restrict') {
			$stmt = $PDO->prepare("select count(*) from `$table` where $where");
			$bind($stmt);
			$stmt->execute();
			$count = (int)$stmt->fetchColumn();
			$stmt->closeCursor();

			if ($count > 0) {
				$pos = strrpos($class, '\\');
				$shortName = $pos === false ? $class : substr($class, $pos + 1);
				$this->activeRecord->_error("Cannot delete, because {$count} {$shortName}"
					." object".($count == 1 ? '' : 's')." still refer".($count == 1 ? 's' : '')
					." to it through the property '{$propName}'; delete them first, or set on_remote_delete=\"cascade\" on that property");
			}
		} else {
			$set = implode(', ', array_map(fn($column) => "`$column` = NULL", array_keys($match)));
			$stmt = $PDO->prepare("update `{$table}` set {$set} where {$where}");
			$bind($stmt);
			$stmt->execute();
		}
	}

	/**
	 * Deletes the rows in a join table that refer to the deleted object as the
	 * related object of a relation.
	 *
	 * @param array<string, mixed> $reference the reference, see cleanUp()
	 */
	private function _deleteJoinRows(array $reference) : void {
		$query = "delete from `{$reference['table']}` where `{$reference['remote_id']}` = :id";
		// The local_type column holds the class of the relation's owner
		if ($reference['local_type'] !== null)
			$query .= " and `{$reference['local_type']}` = :localType";

		$stmt = $this->_getPDO($reference['database'])->prepare($query);
		$stmt->bindValue(':id', $this->activeRecord->id, \PDO::PARAM_INT);
		if ($reference['local_type'] !== null)
			$stmt->bindValue(':localType', $reference['local_type_value'], \PDO::PARAM_STR);
		$stmt->execute();
	}

	/**
	 * Returns a database connection.
	 *
	 * @param string $database the database (connection id)
	 */
	private function _getPDO(string $database) : \PDO {
		return DB\DBConnectionManager::getPDO($database)
			?? throw new \Exception("No database '{$database}'");
	}

	private ActiveRecord $activeRecord;
}
