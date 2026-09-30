<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The DynamicClass property type handles the conversion between an
 * (ActiveRecord) object in PHP to an object ID and its classname
 * in the database and vice versa.
 *
 * Contrary to the Class property, this object reference is not
 * statically typed, but stored in the database next to the id. Assigned
 * values can be any ActiveRecord object; other values throw an exception.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeDynamicClass extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$class_name = $values[$prop['fieldnames'][0]];
		$id = $values[$prop['fieldnames'][1]];
		return $id==null ? null : new $class_name((int)$id);
	}

	public function toDB(array $prop, mixed $value) : array {
		// ActiveRecord::commit() makes sure the related object has been committed already
		$class_name = $value==null ? null : get_class($value);
		$id = $value==null ? null : $value->id;
		// A reference to null is stored as NULL in both fields.
		//
		// The stored class name is fully qualified here. This is because we would be
		// unable to instantiate it without the namespace.
		return [
			$prop['fieldnames'][0] => $class_name,
			$prop['fieldnames'][1] => $id
		];
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (!($value instanceof ActiveRecord))
			throw new \InvalidArgumentException('expected an ActiveRecord object, got '.get_debug_type($value));
		return $value;
	}

	public function toDBSearch(array $prop, mixed $value) : array {
		// A plain id is not accepted, as it doesn't tell which class is meant
		if ($value !== null && !($value instanceof ActiveRecord))
			throw new \InvalidArgumentException('expected an ActiveRecord object or null, got '.(is_string($value) ? "'$value'" : get_debug_type($value)));
		// Without an id, the search would turn into one for null
		if ($value !== null && $value->id === null)
			throw new \InvalidArgumentException('cannot search for a '.get_class($value).' object that has no id yet');
		return $this->toDB($prop, $value);
	}

	// This class lacks the dereference method, because you'd have to dynamically
	// join in all the possible tables.
	// Just building your own SQL query and feeding the results into the ORM system is probably
	// the best here.
}