<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Text property type is the simplest of types: it maps a single field to
 * an object property. Assigned ints, floats and Stringable objects are
 * converted to string, so the value matches what is read back from the
 * database; other values, like arrays and bools, throw an exception.
 *
 * With a 'length' entry in the metadata the column is a VARCHAR of that
 * length, and longer values are rejected. Like the database, it counts
 * characters (Unicode code points), not bytes: see length().
 *
 * Without it, the column is a TEXT, or with a 'size' entry a TINYTEXT,
 * MEDIUMTEXT or LONGTEXT. Those hold a number of bytes, see SIZES, and values
 * with more bytes are rejected.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeText extends ARPropertyType {
	/**
	 * The longest VARCHAR that MySQL allows with 4 bytes per character
	 * (utf8mb4); longer text needs a property without a length.
	 */
	const MAX_LENGTH = 16383;

	/**
	 * Per size of a property without a length: the SQL type, and the number
	 * of bytes it holds.
	 */
	const SIZES = [
		'tiny' => [ 'type' => 'TINYTEXT', 'bytes' => 255 ],
		'normal' => [ 'type' => 'TEXT', 'bytes' => 65535 ],
		'medium' => [ 'type' => 'MEDIUMTEXT', 'bytes' => 16777215 ],
		'long' => [ 'type' => 'LONGTEXT', 'bytes' => 4294967295 ],
	];

	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		return $values[$prop['fieldnames'][0]];
	}

	public function supportsLike() : bool {
		return true;
	}

	public function normalize(array $prop, mixed $value) : mixed {
		$value = $this->_toString($value);
		if (isset($prop['length'])) {
			// Only counted when it could be too long: a string never has more characters than bytes
			if (strlen($value) > $prop['length']) {
				$length = self::length($value);
				if ($length > $prop['length'])
					throw new \InvalidArgumentException("the value has $length characters, the maximum is {$prop['length']}");
			}
			return $value;
		}

		// Only a size other than 'normal' is in the metadata
		$size = self::SIZES[$prop['size'] ?? 'normal'];
		if (strlen($value) > $size['bytes'])
			throw new \InvalidArgumentException('the value is '.strlen($value)." bytes, the maximum for a {$size['type']} column is {$size['bytes']}");
		return $value;
	}

	public function normalizeSearch(array $prop, string $operator, mixed $value) : mixed {
		// A value that is too long to be stored can still be compared with
		if ($operator == 'LIKE' || $operator == 'NOT LIKE')
			return parent::normalizeSearch($prop, $operator, $value);
		return $this->_toString($value);
	}

	/**
	 * Counts the characters of a string the way the database does for the
	 * length of a VARCHAR column: the Unicode code points of a UTF-8 string.
	 * So 'é' is one character, though two bytes, and an emoji is one too;
	 * but a character that is built from several code points, like an 'e'
	 * followed by a combining accent or a family emoji, counts for each of
	 * them. A string that isn't valid UTF-8 is in a single-byte charset, like
	 * latin1, or binary: every byte counts.
	 *
	 * @param string $value the string
	 * @return int the number of characters
	 */
	public static function length(string $value) : int {
		// Every code point has one byte that isn't a continuation byte (10xxxxxx)
		if (preg_match('//u', $value) === 1)
			return strlen($value) - (int)preg_match_all('/[\x80-\xBF]/', $value);
		return strlen($value);
	}

	/**
	 * Converts a value to string, without checking its length.
	 *
	 * @param mixed $value the assigned value
	 * @return string the value as string
	 * @throws \InvalidArgumentException if the value isn't a string, an int, a float or Stringable
	 */
	private function _toString(mixed $value) : string {
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