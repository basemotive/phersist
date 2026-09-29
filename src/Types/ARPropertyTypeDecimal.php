<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Decimal property type maps a single DECIMAL field to an object property,
 * for exact values like amounts of money.
 *
 * PHP has no exact decimal type (bcmath is an optional extension), so values
 * are kept as numeric strings with exactly 'scale' decimals, like '12.50'.
 * Ints, numeric strings and floats can be assigned. Strings and ints must fit
 * exactly; values with too many decimals are rejected instead of being rounded
 * silently. Floats are inexact anyway, so they are rounded to the scale.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeDecimal extends ARPropertyType {
	const DEFAULT_PRECISION = 10;
	const DEFAULT_SCALE = 2;

	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		return $value === null ? null : $this->normalize($prop, $value);
	}

	public function normalize(array $prop, mixed $value) : mixed {
		return self::normalizeValue($value, (int)$prop['precision'], (int)$prop['scale']);
	}

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value === null ? null : $this->normalize($prop, $value) ];
	}

	public function toDBSearch(array $prop, mixed $value) : array {
		// Strings are passed as-is, so patterns for LIKE keep working and
		// comparisons with values of a different scale are still possible
		if (is_int($value) || is_float($value))
			$value = var_export($value, true);
		return [ $prop['fieldnames'][0] => $value ];
	}

	/**
	 * Converts a value into a decimal string with exactly $scale decimals.
	 *
	 * @param mixed $value an int, float or numeric string
	 * @param int $precision the total number of digits
	 * @param int $scale the number of digits after the decimal point
	 * @return string the value, like '-12.50'
	 * @throws \InvalidArgumentException if the value is invalid or doesn't fit
	 */
	public static function normalizeValue(mixed $value, int $precision, int $scale) : string {
		if (is_float($value)) {
			if (!is_finite($value))
				throw new \InvalidArgumentException("expected a decimal, got $value");
			$value = sprintf("%.{$scale}F", $value);
		} elseif (is_int($value)) {
			$value = (string)$value;
		} elseif (!is_string($value)) {
			throw new \InvalidArgumentException('expected a decimal, got '.get_debug_type($value));
		}

		if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $matches) || !preg_match('/\d/', $value))
			throw new \InvalidArgumentException("'$value' is not a valid decimal");

		$intPart = ltrim($matches[2], '0');
		$fracPart = rtrim($matches[3] ?? '', '0');
		if (strlen($fracPart) > $scale)
			throw new \InvalidArgumentException("'$value' has more than $scale decimals");
		if (strlen($intPart) > $precision - $scale)
			throw new \InvalidArgumentException("'$value' is out of range for DECIMAL($precision,$scale)");

		$result = ($intPart === '' ? '0' : $intPart);
		if ($scale > 0)
			$result .= '.'.str_pad($fracPart, $scale, '0');

		// Only negative if it's not zero, so we never produce '-0.00'
		if ($matches[1] == '-' && ($intPart !== '' || $fracPart !== ''))
			$result = "-$result";

		return $result;
	}

	/**
	 * Validates the precision and scale attributes from the XML model.
	 *
	 * @param string $precision the total number of digits
	 * @param string $scale the number of digits after the decimal point
	 * @return array{int, int} the precision and scale
	 * @throws \InvalidArgumentException if they are not valid for MySQL
	 */
	public static function parseSize(string $precision, string $scale) : array {
		if (!ctype_digit($precision) || (int)$precision < 1 || (int)$precision > 65)
			throw new \InvalidArgumentException("precision must be between 1 and 65, got '$precision'");
		if (!ctype_digit($scale) || (int)$scale > 30 || (int)$scale > (int)$precision)
			throw new \InvalidArgumentException("scale must be between 0 and 30 and not exceed the precision, got '$scale'");
		return [ (int)$precision, (int)$scale ];
	}
}