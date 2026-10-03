<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * Defines the behaviour for a property type for the ActiveRecord.
 * TODO: Maybe these classes could be static, which could save a bit on memory consumption!
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
abstract class ARPropertyType {
	/**
	 * When an ARPropertyType instance is created from the ActiveRecord, the
	 * object itself is provided to the constructor. This way methods could access
	 * the object to do some extra checks or processing. However, when searching
	 * for objects, the object will not be available.
	 *
	 * @param ActiveRecord $activeRecord the object this property is on
	 */
	public function __construct(?ActiveRecord $activeRecord = null) {
		$this->activeRecord = $activeRecord;
	}

	/**
	 * Translates a value or multiple values from the database to a property on
	 * an ActiveRecord instance.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param array<string, mixed> $values the database values to construct the property from
	 * @return mixed the value that was translated from the database
	 */
	public abstract function fromDB(array $prop, array $values) : mixed;

	/**
	 * Translates a property on an ActiveRecord instance into one or more column
	 * values for in the database table.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param mixed $value the value from the object
	 * @return array<string, mixed> the values to put in the database
	 */
	public abstract function toDB(array $prop, mixed $value) : array;

	/**
	 * Converts a value that is assigned to a property into the form the
	 * property has in PHP, so it matches the values read from the database.
	 * For example, a date string is turned into a DateTimeImmutable object.
	 * This is never called with null.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param mixed $value the assigned value
	 * @return mixed the normalized value
	 * @throws \InvalidArgumentException if the value is not valid for this type
	 */
	public function normalize(array $prop, mixed $value) : mixed {
		return $value;
	}

	/**
	 * Checks if LIKE and NOT LIKE can be used to search for this property. Only
	 * text columns support them: other columns would first be converted to text
	 * in a way that differs between databases, and can't use an index.
	 *
	 * @return bool if this property can be searched with LIKE
	 */
	public function supportsLike() : bool {
		return false;
	}

	/**
	 * Checks a value that is used to search for this property with
	 * ObjectFinder::where(), and converts it like normalize() does. For LIKE
	 * and NOT LIKE (if supportsLike()) the value is a pattern, so it must be a
	 * string; otherwise it must be a value that could be assigned to the property.
	 * This is never called with null.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param string $operator the (uppercase) operator it is compared with
	 * @param mixed $value the value to search for
	 * @return mixed the normalized value
	 * @throws \InvalidArgumentException if the value is not valid for this type
	 */
	public function normalizeSearch(array $prop, string $operator, mixed $value) : mixed {
		if ($operator == 'LIKE' || $operator == 'NOT LIKE') {
			if (!is_string($value))
				throw new \InvalidArgumentException("expected a string pattern for $operator, got ".get_debug_type($value));
			return $value;
		}
		return $this->normalize($prop, $value);
	}

	/**
	 * Returns if an operator compares by order (<, >, <=, >=), for which values
	 * that could never be stored, like ones out of range, still make sense.
	 */
	protected static function _isOrderOperator(string $operator) : bool {
		return in_array($operator, [ '<', '>', '<=', '>=' ]);
	}

	/**
	 * Translates the value to database fields for a search. Usually this
	 * can be expected to be the same as toDB, but it can be overloaded.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param mixed $value the value from the object
	 * @return array<string, mixed> the values to use for searching the database
	 */
	public function toDBSearch(array $prop, mixed $value) : array {
		return $this->toDB($prop, $value);
	}

	/**
	 * Helps build the query needed to dereference when searching for objects.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param string $sourceTable the table name for this object
	 * @return array<string, mixed>|false data for the ObjectFinder, or false if the property
	 *   cannot be dereferenced
	 */
	public function dereference(array $prop, string $sourceTable) : array|false {
		return false;
	}

	/**
	 * Checks if this property must be auto-updated.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @return bool if this property must be auto-updated.
	 */
	public function requiresAutoUpdate(array $prop) : bool {
		return false;
	}

	/**
	 * Checks the 'update_on' setting of a property: 'create' updates the value
	 * when the object is first stored, 'modify' on every commit that stores it.
	 * Only applies when this type instance belongs to an object.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @return bool if the value must be updated now
	 */
	protected function _updateOnApplies(array $prop) : bool {
		if ($this->activeRecord == null) return false;
		$updateOn = $prop['update_on'] ?? null;
		return ($updateOn == 'create' && $this->activeRecord->id === null) || $updateOn == 'modify';
	}

	protected ?ActiveRecord $activeRecord = null;
}