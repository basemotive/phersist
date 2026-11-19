<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Int property type maps a single field to an object property. Values
 * read from the database are returned as int. Assigned integer strings, like
 * '42', and floats without a fractional part, like 42.0, are converted to int;
 * other values throw an exception. Values outside the range of the column are
 * rejected as well: the 'size' entry in the metadata tells whether it's a
 * SMALLINT, INT or BIGINT, and the 'signed' entry whether it's unsigned. PHP
 * has no unsigned 64-bit int, so an unsigned BIGINT only goes up to
 * PHP_INT_MAX.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeInt extends ARPropertyType {
	/**
	 * Per size: the SQL type, and the range of values when signed and when
	 * unsigned.
	 */
	const SIZES = [
		'small' => [ 'type' => 'SMALLINT', 'signed' => [ -32768, 32767 ], 'unsigned' => [ 0, 65535 ] ],
		'normal' => [ 'type' => 'INT', 'signed' => [ -2147483648, 2147483647 ], 'unsigned' => [ 0, 4294967295 ] ],
		'big' => [ 'type' => 'BIGINT', 'signed' => [ PHP_INT_MIN, PHP_INT_MAX ], 'unsigned' => [ 0, PHP_INT_MAX ] ],
	];

	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		if ($value === null || is_int($value))
			return $value;
		// intval() would silently turn a value beyond PHP_INT_MAX, which only an
		// unsigned BIGINT column can hold, into PHP_INT_MAX
		$int = filter_var($value, FILTER_VALIDATE_INT);
		if ($int === false)
			throw new \UnexpectedValueException("Value '{$value}' of column {$prop['fieldnames'][0]} doesn't fit a PHP int");
		return $int;
	}

	public function normalize(array $prop, mixed $value) : mixed {
		$int = $this->_toInt($value);
		$size = self::SIZES[$prop['size']];
		[$min, $max] = $size[$prop['signed'] ? 'signed' : 'unsigned'];
		if ($int < $min || $int > $max)
			throw new \InvalidArgumentException("$int is out of range for ".($prop['signed'] ? 'a signed ' : 'an unsigned ').$size['type'].' column');
		return $int;
	}

	public function normalizeSearch(array $prop, string $operator, mixed $value) : mixed {
		// Comparing with a value outside the column's range is still meaningful
		if (self::_isOrderOperator($operator))
			return $this->_toInt($value);
		return parent::normalizeSearch($prop, $operator, $value);
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
