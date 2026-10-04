<?php

namespace PHersist\Maps;

use PHersist\ActiveRecord;
use PHersist\DB\DBConnectionManager;

/**
 * Loads, holds and stores the data of a map. Users work with it through Map.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class MapStorage {
	/**
	 * @param ActiveRecord $activeRecord the object that contains the map
	 * @param array<string, mixed> $map the metadata for the map
	 */
	public function __construct(ActiveRecord $activeRecord, array $map) {
		$this->activeRecord = $activeRecord;
		$this->map = $map;

		// We don't want to restore stuff for new objects
		if ($this->activeRecord->id === null) {
			$this->isRestored = true;
			$this->data = [];
		}
	}

	/**
	 * Serializes the map with its owner, including changes that haven't been
	 * committed yet. The metadata is left out; it comes from the owner's class.
	 *
	 * @return array<string, mixed>
	 */
	public function __serialize() : array {
		return [
			'activeRecord' => $this->activeRecord,
			'name' => $this->map['activeRecordKey'],
			'data' => $this->data,
			'isRestored' => $this->isRestored,
			'detached' => $this->detached,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function __unserialize(array $data) : void {
		$this->activeRecord = $data['activeRecord'];
		$this->data = $data['data'];
		$this->isRestored = $data['isRestored'];
		$this->detached = $data['detached'] ?? false;

		$meta = ActiveRecord::_getMeta(get_class($this->activeRecord));
		if (!isset($meta['maps'][$data['name']]))
			throw new \Exception(get_class($this->activeRecord).": Map {$data['name']} does not exist anymore");
		$this->map = $meta['maps'][$data['name']];
	}

	/**
	 * Gets the database connection of the owner. The map doesn't keep it,
	 * because a connection can't be serialized.
	 *
	 * @return \PDO the database connection
	 */
	private function _getPDO() : \PDO {
		$database = ActiveRecord::_getMeta(get_class($this->activeRecord))['database'];
		$PDO = DBConnectionManager::getPDO($database);
		if ($PDO === null)
			throw new \Exception(get_class($this->activeRecord).": No database '$database'");
		return $PDO;
	}

	/**
	 * Stores this map
	 */
	public function commit() : void {
		$this->delete();

		$table = $this->map['table'];
		$id = $this->map['id'];
		$keys = $this->map['keys'];
		$valueField = $this->map['value'];

		$query = "insert into `$table` (`$id`";
		if ($this->map['type'] !== false)
			$query .= ",`{$this->map['type']}`";
		foreach ($keys as $key)
			$query .= ",`$key`";
		$query .= ",`$valueField`";
		// Numbered placeholders, as column names can contain characters that
		// PDO doesn't allow in a placeholder
		$columnCount = 2 + ($this->map['type'] !== false ? 1 : 0) + count($keys);
		$query .= ') values ('.implode(',', array_map(fn($index) => ":p{$index}", range(0, $columnCount - 1))).')';

		$sets = [];
		$arr = $this->data;

		$keyList = [];

		$stmt = $this->_getPDO()->prepare($query);

		$querySets = $this->getQuerySets($this->data, $keyList);
		foreach ($querySets as $querySet) {
			$index = 0;

			$stmt->bindValue(':p'.$index, $querySet[$index++], \PDO::PARAM_INT);
			if ($this->map['type'] !== false)
				$stmt->bindValue(':p'.$index, $querySet[$index++], \PDO::PARAM_STR);
			foreach ($keys as $key)
				$stmt->bindValue(':p'.$index, $querySet[$index++], \PDO::PARAM_STR);
			$stmt->bindValue(':p'.$index, $querySet[$index++], \PDO::PARAM_STR);
			$stmt->execute();
		}
	}

	/**
	 * @param array<mixed>|string $map
	 * @param list<mixed> $keyList
	 * @return list<mixed>
	 */
	private function getQuerySets(array|string $map, array $keyList) : array {
		$result = [];

		//echo "getQuerySets: ".implode(',', $keyList)."\n";

		if (count($keyList) == count($this->map['keys'])) {
			$line = [];

			$line[] = $this->activeRecord->id;
			if ($this->map['type'] !== false) {
				if ($this->map['use_namespace'])
					$line[] = get_class($this->activeRecord);
				else
					$line[] = (new \ReflectionClass($this->activeRecord))->getShortName();
			}
			foreach ($keyList as $key)
				$line[] = $key;

			$line[] = $map;
			$result[] = $line;
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
			if ($this->map['use_namespace'])
				$values['className'] = get_class($this->activeRecord);
			else
				$values['className'] = (new \ReflectionClass($this->activeRecord))->getShortName();
		}

		$stmt = $this->_getPDO()->prepare($query);
		foreach ($values as $key => $value) {
			$stmt->bindValue(':'.$key, $value, $key == 'id' ? \PDO::PARAM_INT : \PDO::PARAM_STR);
			unset($key, $value);
		}
		$stmt->execute();
	}

	/**
	 * Captures the data of this map, so a rolled back transaction can restore it.
	 *
	 * @internal used by ActiveRecord for transactions
	 * @return callable(): void a function that restores the captured state
	 */
	public function _snapshot() : callable {
		$data = $this->data;
		$isRestored = $this->isRestored;
		$detached = $this->detached;
		return function() use ($data, $isRestored, $detached) : void {
			$this->data = $data;
			$this->isRestored = $isRestored;
			$this->detached = $detached;
		};
	}

	/**
	 * Marks this map as no longer part of its object, after the object
	 * dropped it. It can still be read, but changing it throws an exception,
	 * because the change would never be committed.
	 *
	 * @internal used by ActiveRecord::reload() and transaction rollbacks
	 */
	public function _detach() : void {
		$this->detached = true;
	}

	public function restore() : void {
		$this->isRestored = true;

		$table = $this->map['table'];
		$id = $this->map['id'];

		$keys = $this->map['keys'];
		$valueField = $this->map['value'];

		$this->data = [];

		$qValues = [];
		$query = "select * from `$table` where `$id` = :id";
		$qValues['id'] = $this->activeRecord->id;
		if ($this->map['type'] !== false) {
			$query .= " and `{$this->map['type']}` = :className";
			if ($this->map['use_namespace'])
				$qValues['className'] = get_class($this->activeRecord);
			else
				$qValues['className'] = (new \ReflectionClass($this->activeRecord))->getShortName();
		}
		$stmt = $this->_getPDO()->prepare($query);
		foreach ($qValues as $key => $value) {
			$stmt->bindValue(':'.$key, $value, $key == 'id' ? \PDO::PARAM_INT : \PDO::PARAM_STR);
			unset($key, $value);
		}
		$stmt->execute();

		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$callKeys = [];
			foreach ($keys as $key)
				$callKeys[] = $row[$key];

			$this->setForArray($callKeys, $row[$valueField], false);
		}
		$stmt->closeCursor();
	}

	/**
	 * Sets the value at a key path.
	 *
	 * With a complete key path, the value is a single map value. With an
	 * incomplete (or empty) key path, the value is an array that replaces the
	 * whole submap (or map) at that path; it must be nested exactly as deep as
	 * the remaining keys. Null removes the value or submap.
	 *
	 * @param list<mixed> $keys
	 * @param mixed $value
	 * @param bool $set_changed
	 * @throws \InvalidArgumentException if the value doesn't fit the key structure
	 */
	public function setForArray(array $keys, mixed $value, bool $set_changed = true) : void {
		// Check this before touching the data, because the ActiveRecord is only
		// notified after the data has changed
		if ($set_changed && $this->activeRecord->isDeleted())
			$this->activeRecord->_error("Cannot change property {$this->map['activeRecordKey']} on a deleted object");
		if ($set_changed && $this->detached)
			$this->activeRecord->_error("Cannot change map {$this->map['activeRecordKey']}: the object dropped it in reload() or a rolled back transaction, read the property again");

		if (count($keys) > count($this->map['keys']))
			throw new \InvalidArgumentException("Map {$this->map['activeRecordKey']} has only ".count($this->map['keys']).' key(s)');
		$keys = $this->_normalizeKeys($keys);

		// Validate before touching the data, so a bad value leaves the map as it was
		$value = $this->_normalize($value, count($this->map['keys']) - count($keys), $keys);

		if (!$this->isRestored) $this->restore();

		if (count($keys) == 0) {
			$this->data = $value ?? [];
		} elseif ($value === null) {
			$this->_remove($this->data, $keys);
		} else {
			$arr = &$this->data;
			for ($i=0; $i<count($keys); $i++) {
				$key = $keys[$i];
				if ($i < count($keys)-1) {
					// Not the last key; make sure the key-path is available
					if (!isset($arr[$key])) $arr[$key] = [];
					$arr = &$arr[$key];
				} else {
					$arr[$key] = $value;
				}
			}
			unset($arr);
		}

		if ($set_changed)
			$this->activeRecord->setChanged($this->map['activeRecordKey']);
	}

	/**
	 * Returns the value at a complete key path, or a submap for an incomplete one.
	 *
	 * @param list<mixed> $keys
	 * @return mixed the value (null if it doesn't exist), or an Map for a submap
	 * @throws \InvalidArgumentException if a key isn't a string or Stringable
	 */
	public function getForArray(array $keys) : mixed {
		$keys = $this->_normalizeKeys($keys);
		if (count($keys) < count($this->map['keys']))
			return new Map($this, $keys);

		if (!$this->isRestored) $this->restore();

		$arr = $this->data;
		foreach ($keys as $key) {
			if (!is_array($arr) || !isset($arr[$key]))
				return null;
			$arr = $arr[$key];
		}
		return $arr;
	}

	/**
	 * Checks if there is a value at a complete key path, or a non-empty submap
	 * at an incomplete one.
	 *
	 * @param list<mixed> $keys
	 * @return bool
	 * @throws \InvalidArgumentException if a key isn't a string or Stringable
	 */
	public function has(array $keys) : bool {
		$keys = $this->_normalizeKeys($keys);
		if (!$this->isRestored) $this->restore();

		$arr = $this->data;
		foreach ($keys as $key) {
			if (!is_array($arr) || !isset($arr[$key]))
				return false;
			$arr = $arr[$key];
		}
		return !is_array($arr) || count($arr) > 0;
	}

	/**
	 * Removes the value or submap at a key path, and the submaps that become
	 * empty because of it.
	 *
	 * @param array<mixed> $arr the (sub)map to remove from
	 * @param list<mixed> $keys the key path, relative to $arr
	 */
	private function _remove(array &$arr, array $keys) : void {
		$key = array_shift($keys);
		if (!isset($arr[$key]))
			return;

		if (count($keys) > 0) {
			$this->_remove($arr[$key], $keys);
			if (count($arr[$key]) > 0)
				return;
		}
		unset($arr[$key]);
	}

	/**
	 * Checks that a value fits the key structure and converts it for storage.
	 *
	 * @param mixed $value the value to check
	 * @param int $depth the number of key levels the value must still contain
	 * @param list<mixed> $path the key path of the value, for error messages
	 * @return array<mixed>|string|null the value with its leaves converted to strings
	 * @throws \InvalidArgumentException if the value doesn't fit
	 */
	private function _normalize(mixed $value, int $depth, array $path) : array|string|null {
		if ($value === null)
			return null;

		$name = $this->map['activeRecordKey'];
		foreach ($path as $key)
			$name .= "[$key]";

		if ($depth == 0)
			return self::_toString($value, $name);

		if ($value instanceof Map)
			$value = $value->toArray();
		if (!is_array($value))
			throw new \InvalidArgumentException("$name must be an array with $depth key level(s), not ".get_debug_type($value));

		$result = [];
		foreach ($value as $key => $subValue) {
			self::_toString($key, "Key of $name");
			$subPath = $path;
			$subPath[] = $key;
			$subValue = $this->_normalize($subValue, $depth - 1, $subPath);
			if ($subValue !== null)
				$result[$key] = $subValue;
		}
		return $result;
	}

	/**
	 * Checks the keys of a key path and converts them to strings.
	 *
	 * @param list<mixed> $keys the key path
	 * @return list<string>
	 * @throws \InvalidArgumentException if a key isn't string-like or not valid UTF-8
	 */
	private function _normalizeKeys(array $keys) : array {
		$name = $this->map['activeRecordKey'];
		$result = [];
		foreach ($keys as $key) {
			$result[] = self::_toString($key, "Key of $name");
			$name .= "[$key]";
		}
		return $result;
	}

	/**
	 * Converts a map key or value to a string. It must be valid UTF-8, so that
	 * the database stores it unchanged and it can be queried as text.
	 *
	 * @param mixed $value the key or value
	 * @param string $name the name of the key or value, for error messages
	 * @return string
	 * @throws \InvalidArgumentException if it isn't string-like or not valid UTF-8
	 */
	private static function _toString(mixed $value, string $name) : string {
		if (!self::_isStringLike($value))
			throw new \InvalidArgumentException("$name must be a string, not ".get_debug_type($value));
		$string = (string)$value;
		if (preg_match('//u', $string) !== 1)
			throw new \InvalidArgumentException("$name must be valid UTF-8");
		return $string;
	}

	/**
	 * Tells whether a value can be used as a map key or value: a string, or an
	 * int, float or Stringable that is converted to one. Ints are accepted
	 * because PHP turns numeric string array keys into ints.
	 */
	private static function _isStringLike(mixed $value) : bool {
		return is_string($value) || is_int($value) || is_float($value) || $value instanceof \Stringable;
	}

	/**
	 * Returns the map, or the submap at a key path, as a plain nested array.
	 *
	 * @param list<mixed> $keys the (incomplete) key path of the submap; empty for the whole map
	 * @return array<mixed>
	 * @throws \InvalidArgumentException if the key path doesn't lead to a submap
	 */
	public function toArray(array $keys = []) : array {
		if (count($keys) >= count($this->map['keys']))
			throw new \InvalidArgumentException("Key path of map {$this->map['activeRecordKey']} doesn't lead to a submap");

		if (!$this->isRestored) $this->restore();

		$arr = $this->data;
		foreach ($keys as $key) {
			if (!isset($arr[$key]))
				return [];
			$arr = $arr[$key];
		}
		return $arr;
	}

	/**
	 * @deprecated use toArray()
	 * @param list<mixed> $keys
	 * @return array<mixed>|null
	 */
	public function getJSONData(array $keys) : ?array {
		return $this->toArray($keys);
	}

	public function getArrayAccess() : Map {
		return new Map($this);
	}

	protected ?ActiveRecord $activeRecord = null;
	/** @var array<string, mixed>|null */
	protected ?array $map = null;

	/** @var array<mixed>|null */
	protected ?array $data = null;

	protected bool $isRestored = false;

	protected bool $detached = false;
}
