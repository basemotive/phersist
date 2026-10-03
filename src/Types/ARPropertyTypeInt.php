<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Int property type maps a single field to an object property. Values
 * read from the database are returned as int. Assigned integer strings, like
 * '42', and floats without a fractional part, like 42.0, are converted to int;
 * other values throw an exception. The column is a 32-bit MySQL INT, so values
 * outside its range are rejected as well: the 'signed' entry in the metadata
 * tells whether it's INT or INT UNSIGNED.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeInt extends ARPropertyType {
	const SIGNED_RANGE = [ -2147483648, 2147483647 ];
	const UNSIGNED_RANGE = [ 0, 4294967295 ];

	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		return $value === null ? null : intval($value);
	}

	public function normalize(array $prop, mixed $value) : mixed {
		$int = $this->_toInt($value);
		[$min, $max] = $prop['signed'] ? self::SIGNED_RANGE : self::UNSIGNED_RANGE;
		if ($int < $min || $int > $max)
			throw new \InvalidArgumentException("$int is out of range for ".($prop['signed'] ? 'a signed' : 'an unsigned').' INT column');
		return $int;
	}

	/**
	 * Converts a value to int, without checking the range of the column.
	 *
	 * @param mixed $value the assigned value
	 * @return int the value as int
	 * @throws \InvalidArgumentException if the value isn't a whole number within the range of a PHP int
	 */
	private function _toInt(mixed $value) : int {
		if (is_int($value))
			return $value;

		// Floats must be whole numbers within the range of int. -PHP_INT_MIN is
		// exactly representable as a float, while PHP_INT_MAX is not.
		if (is_float($value)) {
			if (floor($value) != $value || $value < PHP_INT_MIN || $value >= -(float)PHP_INT_MIN)
				throw new \InvalidArgumentException("expected an int, got float $value");
			return (int)$value;
		}

		if (is_string($value)) {
			if (!preg_match('/^([+-]?)0*(\d+)$/', $value, $matches))
				throw new \InvalidArgumentException("'$value' is not a valid int");
			// filter_var() rejects leading zeros, so they are stripped first
			$int = filter_var($matches[1].$matches[2], FILTER_VALIDATE_INT);
			if ($int === false)
				throw new \InvalidArgumentException("'$value' is out of range for an int");
			return $int;
		}

		throw new \InvalidArgumentException('expected an int, got '.get_debug_type($value));
	}

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value ];
	}
}
