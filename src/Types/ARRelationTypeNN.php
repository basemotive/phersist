<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;
use PHersist\ReferenceCleaner;

/**
 * Handles N-N and 1-N relations.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARRelationTypeNN extends ARRelationType {
	public function __construct(\PDO $PDO, ?ActiveRecord $activeRecord = null) {
		parent::__construct($PDO, $activeRecord);
	}

	/**
	 * @param array<string, mixed> $rel
	 * @return list<object>
	 */
	public function restore(array $rel) : array {
		$objects = [];

		/** @var class-string<ActiveRecord> $className */
		$className = $rel['class']; // The related type class name
		$meta = ActiveRecord::_getMeta($className);
		$baseTable = $meta['table']; // The base table for the related type
		$idField = $meta['id']; // The id field for the related type

		// We also want to load the data for the related objects immediately
		$extraFields = '';
		$datasetTable = $baseTable;
		if ($rel['load_objects'])
			foreach ($meta['datasets'] as $dataset) if ($dataset['autoload']) {
				$extraFieldList = [];
				foreach ($dataset['props'] as $prop)
					$extraFieldList = array_merge($extraFieldList, $prop['fieldnames']);
				// The dataset may live in its own table, which we then join
				$datasetTable = $dataset['table'];
				foreach (array_unique($extraFieldList) as $extraField)
					$extraFields .= ", `$datasetTable`.`$extraField`";
				break;
			}

		// Build the query
		$query = "select `{$baseTable}`.`{$idField}` $extraFields from `{$rel['table']}`";
		if ($baseTable != $rel['table']) // Join the related object so we can make sure the it is not deleted
			$query .= " inner join `$baseTable` on `{$rel['table']}`.`{$rel['remote_id']}` = `$baseTable`.`$idField`";
		// The relation table may itself be the dataset table (a 1-N relation
		// through a property in that dataset), in which case it's already there
		if ($datasetTable != $baseTable && $datasetTable != $rel['table'])
			$query .= " left join `$datasetTable` on `$datasetTable`.`$idField` = `$baseTable`.`$idField`";
		$query .= " where `{$rel['table']}`.`{$rel['local_id']}` = :id";
		$myClass = $this->_localType($rel);
		if ($myClass !== null)
			$query .= " and `{$rel['table']}`.`{$rel['local_type']}` = :myClass";
		if ($meta['softdelete']) // Account for softdelete
			$query .= " and `$baseTable`.`deleted` = '0'";
		if (isset($rel['order_field']))
			$query .= " order by `{$rel['table']}`.`{$rel['order_field']}`";

		$stmt = $this->PDO->prepare($query);
		$stmt->bindValue(':id', $this->activeRecord->id, \PDO::PARAM_INT);
		if ($myClass !== null)
			$stmt->bindValue(':myClass', $myClass, \PDO::PARAM_STR);

		$stmt->execute();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			// We use the fetchObject method instead of the constructor so the
			// ActiveRecord can handle the caching. If we're restoring the complete
			// objects, the row holds the autoload dataset for it to assign.
			$objects[] = ActiveRecord::fetchObject(
				$className,
				(int)$row[$idField],
				$rel['load_objects'] ? $row : null
			);
		}
		$stmt->closeCursor();

		return $objects;
	}

	/**
	 * @param array<string, mixed> $rel
	 * @param list<object> $objects
	 */
	public function store(array $rel, array $objects) : void {
		$className = $rel['class']; // The related type class name
		$meta = ActiveRecord::_getMeta($className);
		$baseTable = $meta['table']; // The base table for the related type

		if (!$rel['table_owner'])
			// would have been nice if we could report the property name here
			$this->activeRecord->_error("Cannot store relation because we are not table owner");
		if ($baseTable==$rel['table'])
			// would have been nice if we could report the property name here
			$this->activeRecord->_error("Cannot store relation because we cannot update its base table");
			// Theoretically, we could. We could just update our field on the related items
			// (we set our id for items we have, and remove it for items we don't have in the array)
			// and we could also update the order fields if we wanted to.
			// This behaviour would make working with relations like that a little easier.

		// First, we delete the old relation
		$this->_deleteRows($rel);

		// We don't need to re-insert anything if we have no values
		if (count($objects) == 0)
			return;

		// Now we re-insert again
		$myClass = $this->_localType($rel);
		$query = "insert into `{$rel['table']}` (`{$rel['local_id']}`, `{$rel['remote_id']}`";
		if ($myClass !== null)
			$query .= ", `{$rel['local_type']}`";
		if (isset($rel['order_field']))
			$query .= ", `{$rel['order_field']}`";
		$query .= ") values (:{$rel['local_id']}, :{$rel['remote_id']}";
		if ($myClass !== null)
			$query .= ", :{$rel['local_type']}";
		if (isset($rel['order_field']))
			$query .= ", :{$rel['order_field']}";
		$query .= ")";

		$stmt = $this->PDO->prepare($query);

		$counter = 0;
		foreach ($objects as $object) {
			$stmt->bindValue(":{$rel['local_id']}", $this->activeRecord->id, \PDO::PARAM_INT);
			$stmt->bindValue(":{$rel['remote_id']}", $object->id, \PDO::PARAM_INT);
			if ($myClass !== null)
				$stmt->bindValue(":{$rel['local_type']}", $myClass, \PDO::PARAM_STR);
			if (isset($rel['order_field'])) {
				$stmt->bindValue(":{$rel['order_field']}", $counter, \PDO::PARAM_STR);
				$counter++;
			}
			$stmt->execute();
		}
	}

	/**
	 * Cleans up this relation when the object is deleted.
	 *
	 * In a join table, the rows of this object are deleted, whether we own the
	 * table or not; with cascade_delete, the related objects are deleted first.
	 * In a derived relation, where the table is one of the related class's own
	 * tables, the references to this object are cleaned up according to the
	 * on_remote_delete of the property that holds them, or deleted along with
	 * cascade_delete.
	 *
	 * @param array<string, mixed> $rel
	 */
	public function delete(array $rel) : void {
		/** @var class-string<ActiveRecord> $className */
		$className = $rel['class'];
		$meta = ActiveRecord::_getMeta($className);
		$classTables = array_merge([$meta['table']], array_column($meta['datasets'], 'table'));

		if (!in_array($rel['table'], $classTables)) {
			if ($rel['cascade_delete'])
				foreach ($this->restore($rel) as $object) $object->delete();
			$this->_deleteRows($rel);
		} else
			$this->_clearReferences($rel, $meta);
	}

	/**
	 * Deletes the rows of this object from the relation table.
	 *
	 * @param array<string, mixed> $rel
	 */
	private function _deleteRows(array $rel) : void {
		$query = "delete from `{$rel['table']}` where `{$rel['local_id']}` = :id";
		$myClass = $this->_localType($rel);
		if ($myClass !== null)
			$query .= " and `{$rel['local_type']}` = :myClass";

		$stmt = $this->PDO->prepare($query);
		$stmt->bindValue(':id', $this->activeRecord->id, \PDO::PARAM_INT);
		if ($myClass !== null)
			$stmt->bindValue(':myClass', $myClass, \PDO::PARAM_STR);
		$stmt->execute();
	}

	/**
	 * Cleans up the references to this object in a derived relation.
	 *
	 * The policy is that of the property in the related class that holds the
	 * reference, unless the relation has cascade_delete. Without such a
	 * property, the references are set to NULL.
	 *
	 * @param array<string, mixed> $rel
	 * @param array<string, mixed> $meta the metadata of the related class
	 */
	private function _clearReferences(array $rel, array $meta) : void {
		$match = [ $rel['local_id'] => $this->activeRecord->id ];
		$myClass = $this->_localType($rel);
		if ($myClass !== null)
			$match[$rel['local_type']] = $myClass;

		// The property in the related class that holds the reference
		$propName = null;
		$policy = 'null';
		foreach ($meta['datasets'] as $dataset) if ($dataset['table'] == $rel['table'])
			foreach ($dataset['props'] as $key => $prop)
				if (in_array($rel['local_id'], $prop['fieldnames'])) {
					$propName = $key;
					if ($prop['type'] == 'Class' || $prop['type'] == 'DynamicClass')
						$policy = ReferenceCleaner::policyFor($prop);
					elseif ($prop['required'])
						$policy = 'restrict';
				}

		if ($rel['cascade_delete'])
			$policy = 'cascade';

		(new ReferenceCleaner($this->activeRecord))->clear($rel['class'], $rel['table'], $match, $policy, $propName);
	}

	/**
	 * Returns the value that identifies this object's class in the local_type
	 * column of the relation table.
	 *
	 * @param array<string, mixed> $rel
	 * @return ?string the class name, or null if the relation has no local_type
	 */
	private function _localType(array $rel) : ?string {
		if (!isset($rel['local_type']) || $rel['local_type'] == '')
			return null;
		return $rel['use_namespace']
			? get_class($this->activeRecord)
			: (new \ReflectionClass($this->activeRecord))->getShortName();
	}
}
