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
	 * @param array $prop the property definition from the metadata
	 * @param array $values the database values to construct the property from
	 * @return mixed the value that was translated from the database
	 */
	public abstract function fromDB(array $prop, array $values) : mixed;

	/**
	 * Translates a property on an ActiveRecord instance into one or more column
	 * values for in the database table.
	 *
	 * @param array $prop the property definition from the metadata
	 * @param mixed $value the value from the object
	 * @return array the values to put in the database
	 */
	public abstract function toDB(array $prop, mixed $value) : array;

	/**
	 * Translates the value to database fields for a search. Usually this
	 * can be expected to be the same as toDB, but it can be overloaded.
	 *
	 * @param array $prop the property definition from the metadata
	 * @param mixed $value the value from the object
	 * @return array the values to use for searching the database
	 */
	public function toDBSearch(array $prop, mixed $value) : array {
		return $this->toDB($prop, $value);
	}

	/**
	 * Helps build the query needed to dereference when searching for objects.
	 *
	 * @param array $prop the property definition from the metadata
	 * @param string $sourceTable the table name for this object
	 * @return array|false data for the ObjectFinder, or false if the property
	 *   cannot be dereferenced
	 */
	public function dereference(array $prop, $sourceTable) : array|false {
		return false;
	}

	/**
	 * Checks if this property must be auto-updated.
	 *
	 * @param array $prop the property definition from the metadata
	 * @return bool if this property must be auto-updated.
	 */
	public function requiresAutoUpdate(array $prop) : bool {
		return false;
	}

	protected ?ActiveRecord $activeRecord = null;
}