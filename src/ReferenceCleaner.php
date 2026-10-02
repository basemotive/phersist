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
	 * Returns the on_remote_delete policy for a Class or DynamicClass property.
	 *
	 * Classes generated before on_remote_delete existed don't have it in their
	 * metadata, so it is derived from 'required' the way the generator does.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @return string 'null', 'restrict' or 'cascade'
	 */
	public static function policyFor(array $prop) : string {
		return $prop['on_remote_delete'] ?? ($prop['required'] ? 'restrict' : 'null');
	}

	/**
	 * Cleans up one of the references from the 'references' in the metadata of
	 * the deleted object's class.
	 *
	 * @param array<string, mixed> $ref the reference: the referring 'class', and
	 *   the 'property' or join table 'relation' in it that holds the reference
	 */
	public function cleanUp(array $ref) : void {
		/** @var class-string<ActiveRecord> $class */
		$class = $ref['class'];
		$meta = ActiveRecord::_getMeta($class);

		if (isset($ref['relation'])) {
			$this->_deleteJoinRows($class, $meta['relations'][$ref['relation']]);
			return;
		}

		foreach ($meta['datasets'] as $dataset) if (isset($dataset['props'][$ref['property']])) {
			$prop = $dataset['props'][$ref['property']];

			// A DynamicClass property also stores the class name, in the form its
			// type writes it
			if ($prop['type'] == 'DynamicClass')
				$match = (new Types\ARPropertyTypeDynamicClass())->toDB($prop, $this->activeRecord);
			else
				$match = [ $prop['fieldnames'][0] => $this->activeRecord->id ];

			$this->clear($class, $dataset['table'], $match, self::policyFor($prop), $ref['property']);
			return;
		}
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
	 * @param class-string<ActiveRecord> $class the class that refers to the deleted object
	 * @param string $table the table of that class that holds the references
	 * @param array<string, mixed> $match the column values that make up the reference
	 * @param string $policy 'null', 'restrict' or 'cascade'
	 * @param ?string $propName the property that holds the reference, for the error message
	 */
	public function clear(string $class, string $table, array $match, string $policy, ?string $propName) : void {
		$meta = ActiveRecord::_getMeta($class);
		$PDO = $this->_getPDO($meta);

		$where = implode(' and ', array_map(fn($column) => "`$table`.`$column` = :$column", array_keys($match)));
		$bind = function(\PDOStatement $stmt) use ($match) : void {
			foreach ($match as $column => $value)
				$stmt->bindValue(":$column", $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
		};

		if ($policy == 'cascade') {
			$baseTable = $meta['table'];
			$idField = $meta['id'];

			$query = "select `$table`.`$idField` from `$table`";
			if ($meta['softdelete']) {
				if ($table != $baseTable)
					$query .= " inner join `$baseTable` on `$baseTable`.`$idField` = `$table`.`$idField`";
				$where .= " and `$baseTable`.`deleted` = '0'";
			}
			$stmt = $PDO->prepare("$query where $where");
			$bind($stmt);
			$stmt->execute();
			$ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
			$stmt->closeCursor();

			foreach ($ids as $id)
				ActiveRecord::fetchObject($class, (int)$id)?->delete();
		} elseif ($policy == 'restrict') {
			$stmt = $PDO->prepare("select count(*) from `$table` where $where");
			$bind($stmt);
			$stmt->execute();
			$count = (int)$stmt->fetchColumn();
			$stmt->closeCursor();

			if ($count > 0)
				$this->activeRecord->_error("Cannot delete, because $count ".(new \ReflectionClass($class))->getShortName()
					." object".($count == 1 ? '' : 's')." still refer".($count == 1 ? 's' : '')
					." to it through the property '$propName'; delete them first, or set on_remote_delete=\"cascade\" on that property");
		} else {
			$set = implode(', ', array_map(fn($column) => "`$column` = NULL", array_keys($match)));
			$stmt = $PDO->prepare("update `$table` set $set where $where");
			$bind($stmt);
			$stmt->execute();
		}
	}

	/**
	 * Deletes the rows in a join table that refer to the deleted object as the
	 * related object of a relation.
	 *
	 * @param class-string<ActiveRecord> $class the class that has the relation
	 * @param array<string, mixed> $rel the relation definition from the metadata
	 */
	private function _deleteJoinRows(string $class, array $rel) : void {
		$query = "delete from `{$rel['table']}` where `{$rel['remote_id']}` = :id";
		// The local_type column holds the class of the relation's owner
		$localType = null;
		if (isset($rel['local_type']) && $rel['local_type'] != '') {
			$localType = $rel['use_namespace'] ? $class : (new \ReflectionClass($class))->getShortName();
			$query .= " and `{$rel['local_type']}` = :localType";
		}

		$stmt = $this->_getPDO(ActiveRecord::_getMeta($class))->prepare($query);
		$stmt->bindValue(':id', $this->activeRecord->id, \PDO::PARAM_INT);
		if ($localType !== null)
			$stmt->bindValue(':localType', $localType, \PDO::PARAM_STR);
		$stmt->execute();
	}

	/**
	 * Returns the database connection for a class.
	 *
	 * @param array<string, mixed> $meta the metadata of the class
	 */
	private function _getPDO(array $meta) : \PDO {
		return DB\DBConnectionManager::getPDO($meta['database'])
			?? throw new \Exception("No database '{$meta['database']}'");
	}

	private ActiveRecord $activeRecord;
}
