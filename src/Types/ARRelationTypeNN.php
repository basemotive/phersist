<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

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

	public function restore(array $rel) : array {
		$objects = [];

		$className = $rel['class']; // The related type class name
		$meta = \PHersist\ActiveRecord::_getMeta($className);
		$baseTable = $meta['table']; // The base table for the related type
		$idField = $meta['id']; // The id field for the related type

		// We also want to load the data for the related objects immediately
		$extraFields = '';
		if ($rel['load_objects'])
			foreach ($meta['datasets'] as $dataset) if ($dataset['autoload']) {
				$extraFieldList = array();
				foreach ($dataset['props'] as $prop)
					$extraFieldList = array_merge($extraFieldList, $prop['fieldnames']);
				foreach (array_unique($extraFieldList) as $extraField)
					$extraFields .= ", `$baseTable`.`$extraField`";
				break;
			}

		// Build the query
		$query = "select `{$baseTable}`.`{$idField}` $extraFields from `{$rel['table']}`";
		if ($baseTable != $rel['table']) // Join the related object so we can make sure the it is not deleted
			$query .= " inner join `$baseTable` on `{$rel['table']}`.`{$rel['remote_id']}` = `$baseTable`.`$idField`";
		$query .= " where `{$rel['table']}`.`{$rel['local_id']}` = :id";
		if (isset($rel['local_type']) && $rel['local_type'] != '') {
			$myClass = get_class($this->activeRecord);
			$query .= " and `{$rel['table']}`.`{$rel['local_type']}` = :myClass";
		} else {
			$myClass = false;
		}
		if ($meta['softdelete']) // Account for softdelete
			$query .= " and `$baseTable`.`deleted` = '0'";
		if (isset($rel['order_field']))
			$query .= " order by `{$rel['order_field']}`";

		$stmt = $this->PDO->prepare($query);
		$stmt->bindValue(':id', $this->activeRecord->id, \PDO::PARAM_STR);
		if ($myClass !== false)
			$stmt->bindValue(':myClass', $myClass, \PDO::PARAM_STR);

		$stmt->execute();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			// If we're restoring the complete objects, we have to pass the data to the constructor
			if ($rel['load_objects']) {
				$id = $row[$idField];
				$objects[] = new $className($id, $row);
			} else {
				$id = $row[$idField];
				$objects[] = new $className($id);
			}
		}
		$stmt->closeCursor();

		return $objects;
	}

	public function store($rel, array $objects) : void {
		$className = $rel['class']; // The related type class name
		$meta = \PHersist\ActiveRecord::_getMeta($className);
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
		$query = "delete from `{$rel['table']}` where `{$rel['local_id']}` = :id";
		if (isset($rel['local_type']) && $rel['local_type'] != '') {
			$myClass = get_class($this->activeRecord);
			$query .= " and `{$rel['table']}`.`{$rel['local_type']}` = :myClass";
		} else {
			$myClass = false;
		}

		$stmt = $this->PDO->prepare($query);
		$stmt->bindValue(':id', $this->activeRecord->id, \PDO::PARAM_STR);
		if ($myClass !== false)
			$stmt->bindValue(':myClass', $myClass, \PDO::PARAM_STR);
		$stmt->execute();

		// We don't need to re-insert anything if we have no values
		if (count($objects) == 0)
			return;

		// Now we re-insert again
		$query = "insert into `{$rel['table']}` (`{$rel['local_id']}`, `{$rel['remote_id']}`";
		if (isset($rel['local_type']))
			$query .= ", `{$rel['local_type']}`";
		if (isset($rel['order_field']))
			$query .= ", `{$rel['order_field']}`";
		$query .= ") values (:{$rel['local_id']}, :{$rel['remote_id']}";
		if (isset($rel['local_type']))
			$query .= ", :{$rel['local_type']}";
		if (isset($rel['order_field']))
			$query .= ", :{$rel['order_field']}";
		$query .= ")";

		$stmt = $this->PDO->prepare($query);

		$counter = 0;
		foreach ($objects as $object) {
			$stmt->bindValue(":{$rel['local_id']}", $this->activeRecord->id, \PDO::PARAM_STR);
			$stmt->bindValue(":{$rel['remote_id']}", $object->id, \PDO::PARAM_STR);
			if (isset($rel['local_type']))
				$stmt->bindValue(":{$rel['local_type']}", get_class($this->activeRecord), \PDO::PARAM_STR);
			if (isset($rel['order_field'])) {
				$stmt->bindValue(":{$rel['order_field']}", $counter, \PDO::PARAM_STR);
				$counter++;
			}
			$stmt->execute();
		}
	}

	public function delete(array $rel) : void {
		if ($rel['cascade_delete']) {
			$objects = $this->restore($rel);
			foreach ($objects as $object) $object->delete();
		}

		$this->store($rel, null);
	}
}