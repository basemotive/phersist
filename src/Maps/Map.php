<?php

namespace PHersist\Maps;

/**
 * A map, or a submap of it, with array-style access. This is what a map
 * property on an ActiveRecord returns; the data itself is in MapStorage.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 *
 * @implements \ArrayAccess<string,mixed>
 * @implements \IteratorAggregate<string,mixed>
 */
class Map implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable {
	/**
	 * @param MapStorage $mapObject
	 * @param list<mixed> $keys
	 */
	public function __construct(MapStorage $mapObject, array $keys = []) {
		$this->mapObject = $mapObject;
		$this->keys = $keys;
	}

	function offsetExists(mixed $key) : bool {
		$keys = $this->keys;
		$keys[] = $key;
		return $this->mapObject->has($keys);
	}

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

	/**
	 * Replaces the contents of this map or submap.
	 *
	 * @param array<mixed>|self $value the new contents, nested as deep as the remaining keys
	 * @throws \InvalidArgumentException if the value doesn't fit the key structure
	 */
	public function set(array|self $value) : void {
		$this->mapObject->setForArray($this->keys, $value);
	}

	/**
	 * Returns this map or submap as a plain nested array.
	 *
	 * @return array<mixed>
	 */
	public function toArray() : array {
		return $this->mapObject->toArray($this->keys);
	}

	/**
	 * @return \ArrayIterator<string, mixed>
	 */
	public function getIterator() : \ArrayIterator {
		return new \ArrayIterator($this->toArray());
	}

	/**
	 * Returns the number of entries directly in this map or submap. For a map
	 * with more than one key, that is the number of submaps.
	 */
	public function count() : int {
		return count($this->toArray());
	}

	/**
	 * Makes json_encode() output this map or submap as (nested) JSON objects,
	 * also when it's empty or has numeric keys.
	 */
	public function jsonSerialize() : mixed {
		return self::_toObject($this->toArray());
	}

	/**
	 * @param array<mixed> $arr a (sub)map
	 * @return \stdClass the same (sub)map, with objects instead of arrays
	 */
	private static function _toObject(array $arr) : \stdClass {
		foreach ($arr as $key => $value)
			if (is_array($value))
				$arr[$key] = self::_toObject($value);
		return (object)$arr;
	}

	public function commit() : void {
		$this->mapObject->commit();
	}

	public function delete() : void {
		$this->mapObject->delete();
	}

	/**
	 * @internal used by ActiveRecord for transactions
	 * @return callable(): void a function that restores the current state of the map
	 */
	public function _snapshot() : callable {
		return $this->mapObject->_snapshot();
	}

	/**
	 * @deprecated use toArray(), or pass the map to json_encode() directly
	 * @return array<mixed>
	 */
	public function getJSONData() : array {
		return $this->toArray();
	}

	private MapStorage $mapObject;
	/** @var list<mixed> */
	private array $keys;
}

?>
