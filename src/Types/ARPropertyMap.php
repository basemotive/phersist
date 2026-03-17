<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * A property map.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyMap {
	/**
	 * @param \PDO $PDO the database connection
	 * @param ActiveRecord $activeRecord the object that contains the map
	 * @param array $map the metadata for the map
	 */
	public function __construct(\PDO $PDO, ActiveRecord $activeRecord, array $map) {
		$this->PDO = $PDO;
		$this->activeRecord = $activeRecord;
		$this->map = $map;

		// We don't want to restore stuff for new objects
		if ($this->activeRecord->id === null) {
			$this->isRestored = true;
			$this->data = [];
		}
	}

	/**
	 * Stores this map
	 */
	public function commit() : void {
		$this->delete();

		$table = $this->map['table'];
		$id = $this->map['id'];
		$keys = $this->map['keys'];
		$values = $this->map['values'];

		$query = "insert into `$table` (`$id`";
		if ($this->map['type'] !== false)
			$query .= ",`{$this->map['type']}`";
		foreach ($keys as $key)
			$query .= ",`$key`";
		foreach ($values as $value)
			$query .= ",`$value`";
		$query .= ") values (:$id";
		if ($this->map['type'] !== false)
			$query .= ",:{$this->map['type']}";
		foreach ($keys as $key)
			$query .= ",:$key";
		foreach ($values as $value)
			$query .= ",:$value";
		$query .= ')';

		$sets = [];
		$arr = $this->data;

		$keyList = [];

		$stmt = $this->PDO->prepare($query);

		$querySets = $this->getQuerySets($this->data, $keyList);
		foreach ($querySets as $querySet) {
			$index = 0;

			$stmt->bindValue(":$id", $querySet[$index++], \PDO::PARAM_STR);
			if ($this->map['type'] !== false)
				$stmt->bindValue(":{$this->map['type']}", $querySet[$index++], \PDO::PARAM_STR);
			foreach ($keys as $key)
				$stmt->bindValue(":$key", $querySet[$index++], \PDO::PARAM_STR);
			foreach ($values as $value)
				$stmt->bindValue(":$value", $querySet[$index++], \PDO::PARAM_STR);
			$stmt->execute();
		}
	}

	/**
	 * @param array|string $map
	 * @param array $keyList
	 * @return array
	 */
	private function getQuerySets(array|string $map, array $keyList) : array {
		$result = [];

		//echo "getQuerySets: ".implode(',', $keyList)."\n";

		if (count($keyList) == count($this->map['keys'])) {
			$line = [];

			$line[] = $this->activeRecord->id;
			if ($this->map['type'] !== false)
				$line[] = get_class($this->activeRecord);
			foreach ($keyList as $key)
				$line[] = $key;

			$value = $map;

			if (count($this->map['values']) > 1) {
				// We have multiple values, so use each of them
				foreach ($this->map['values'] as $valueKey) {
					$val = isset($value[$valueKey]) ? $value[$valueKey] : '';
					$line[] = $val;
				}
				$line = rtrim($line, ',').')';
				$result[] = $line;
			} else {
				// Only one value
				$line[] = $value;
				$result[] = $line;
			}
		} else {
			// Traverse submaps
			foreach ($map as $key => $value) {
				$subKeyList = $keyList;
				$subKeyList[] = $key;
				$subResult = $this->getQuerySets($value, $subKeyList);
				$result = array_merge($result, $subResult);
			}
		}

		return $result;
	}

	/**
	 * Deletes the data for this map
	 */
	public function delete() : void {
		$table = $this->map['table'];
		$id = $this->map['id'];

		$values = [];

		$query = "delete from `$table` where `$id` = :id";
		$values['id'] = $this->activeRecord->id;
		if ($this->map['type'] !== false) {
			$query .= " and `{$this->map['type']}` = :className";
			$values['className'] = get_class($this->activeRecord);
		}

		$stmt = $this->PDO->prepare($query);
		foreach ($values as $key => $value) {
			$stmt->bindValue(':'.$key, $value, \PDO::PARAM_STR);
			unset($key, $value);
		}
		$stmt->execute();
	}

	public function restore() : void {
		$this->isRestored = true;

		$table = $this->map['table'];
		$id = $this->map['id'];

		$keys = $this->map['keys'];
		$values = $this->map['values'];

		$this->data = [];

		$qValues = [];
		$query = "select * from `$table` where `$id` = :id";
		$qValues['id'] = $this->activeRecord->id;
		if ($this->map['type'] !== false) {
			$query .= " and `{$this->map['type']}` = :className";
			$qValues['className'] = get_class($this->activeRecord);
		}
		$stmt = $this->PDO->prepare($query);
		foreach ($qValues as $key => $value) {
			$stmt->bindValue(':'.$key, $value, \PDO::PARAM_STR);
			unset($key, $value);
		}
		$stmt->execute();

		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$callKeys = [];
			foreach ($keys as $key)
				$callKeys[] = $row[$key];

			if (count($values) == 1) {
				$value = $row[$values[0]];
			} else {
				$value = [];
				foreach ($values as $val)
					$value[$val] = $row[$val];
			}

			$this->setForArray($callKeys, $value, false);
		}
		$stmt->closeCursor();
	}

	/**
	 * @param array $keys
	 * @param array|string|null $value
	 * @param bool $set_changed
	 */
	public function setForArray(array $keys, array|string|null $value, bool $set_changed = true) : void {
		if (!$this->isRestored) $this->restore();

		if (count($keys) != count($this->map['keys']))
			die("Cannot set incomplete key\n");

		$arr = &$this->data;
		for ($i=0; $i<count($keys); $i++) {
			$key = $keys[$i];
			if ($i < count($keys)-1) {
				// Not the last key; make sure the key-path is available
				if (!isset($arr[$key])) $arr[$key] = array();
				$arr = &$arr[$key];
			} else {
				if ($value === null)
					unset($arr[$key]);
				else
					$arr[$key] = $value;
			}
		}

		if ($set_changed && !$this->isChanged) {
			$this->isChanged = true;
			$this->activeRecord->setChanged($this->map['activeRecordKey']);
		}
	}

	/**
	 * @param array $keys
	 * @return mixed
	 */
	public function getForArray(array $keys) : mixed {
		if (!$this->isRestored) $this->restore();

		//$arr = &$this->data; // STEFAN: does not work because we mess the array up later
		$arr = $this->data;
		for ($i=0; $i<count($keys); $i++) {
			$key = $keys[$i];

			if ($i < count($keys)-1) {
				// Not the last key
				if (isset($arr[$key]))
					$arr = &$arr[$key];
				else
					$arr = [];
			} else {
				if (count($keys) == count($this->map['keys'])) {
					// It's a value
					$result = isset($arr[$key]) ? $arr[$key] : false;
					return $result;
				} else {
					// It's a submap
					//$result = isset($arr[$key]) ? $arr[$key] : array();
					return new ARPropertyMapArrayImpl($this, $keys);
				}
			}
		}

		return false;
	}

	/**
	 * @param array $keys
	 */
	public function getJSONData(array $keys) : ?array {
		if (!$this->isRestored) $this->restore();

		$arr = &$this->data;
		for ($i=0; $i<count($keys); $i++) {
			$key = $keys[$i];

			if ($i < count($keys)-1) {
				// Not the last key
				if (isset($arr[$key]))
					$arr = &$arr[$key];
				else
					$arr = [];
			} else {
				return $arr[$key];
			}
		}

		if (count($keys) == 0)
			return $this->data;
		else
			return null;
	}

	public function getArrayAccess() : ARPropertyMapArrayImpl {
		return new ARPropertyMapArrayImpl($this);
	}

	protected $PDO = null;
	protected ?ActiveRecord $activeRecord = null;
	protected ?array $map = null;

	protected ?array $data = null;

	protected bool $isRestored = false;
	protected bool $isChanged = false; // if false and we have multiple key-levels, a submap may still have changed
}

/**
 * @implements \ArrayAccess<string,string>
 */
class ARPropertyMapArrayImpl implements \ArrayAccess {
	/**
	 * @param ARPropertyMap $mapObject
	 * @param array $keys
	 */
	public function __construct(ARPropertyMap $mapObject, array $keys = []) {
		$this->mapObject = $mapObject;
		$this->keys = $keys;
	}

	function offsetExists(mixed $key) : bool { return true; }
	function offsetGet(mixed $key) : mixed {
		$keys = $this->keys;
		$keys[] = $key;
		return $this->mapObject->getForArray($keys);
	}

	function offsetSet(mixed $key, mixed $value) : void {
		//echo "OFFSETSET\n";
		$keys = $this->keys;
		$keys[] = $key;
		$this->mapObject->setForArray($keys, $value);
	}

	function offsetUnset(mixed $key) : void {
		$keys = $this->keys;
		$keys[] = $key;
		$this->mapObject->setForArray($keys, null);
	}

	public function commit() : void {
		$this->mapObject->commit();
	}

	public function delete() : void {
		$this->mapObject->delete();
	}

	/**
	 * @return array
	 */
	public function getJSONData() : array {
		return $this->mapObject->getJSONData($this->keys);
	}

	private ARPropertyMap $mapObject;
	private array $keys;
}

?>
