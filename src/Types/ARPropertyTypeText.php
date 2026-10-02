<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Text property type is the simplest of types: it maps a single field to
 * an object property. Assigned ints, floats and Stringable objects are
 * converted to string, so the value matches what is read back from the
 * database; other values, like arrays and bools, throw an exception.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeText extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		return $values[$prop['fieldnames'][0]];
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (is_string($value))
			return $value;
		if (is_int($value) || is_float($value) || $value instanceof \Stringable)
			return (string)$value;
		throw new \InvalidArgumentException('expected a string, got '.get_debug_type($value));
	}

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value ];
	}
}