<?php

namespace PHersist\Types;

/**
 * Sets an automatic timestamp (for text fields).
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeTimestampText extends ARPropertyType {
	/** Format used when no date_format is given; matches a MySQL DATETIME column */
	public const DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct($activeRecord=null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		return $values[$prop['fieldnames'][0]];
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (!is_string($value))
			throw new \InvalidArgumentException('expected a string, got '.get_debug_type($value));

		// The value must be exactly what date() produces for some moment, which also
		// rejects impossible dates like '2026-02-30' that PHP would roll over
		$dateFormat = isset($prop['date_format']) ? $prop['date_format'] : self::DEFAULT_DATE_FORMAT;
		$dateTime = \DateTimeImmutable::createFromFormat('!'.$dateFormat, $value);
		if ($dateTime === false || $dateTime->format($dateFormat) !== $value)
			throw new \InvalidArgumentException("'$value' does not match the date format '$dateFormat'");

		return $value;
	}

	public function toDB(array $prop, mixed $value) : array {
		// We may need to update the date field because of creation or modification of the object.
		if ($this->requiresAutoUpdate($prop)) {
			// The date format may be specified in the metadata, otherwise use default
			$dateFormat = isset($prop['date_format']) ? $prop['date_format'] : self::DEFAULT_DATE_FORMAT;
			$value = date($dateFormat);
		}
		return [ $prop['fieldnames'][0] => $value ];
	}

	public function requiresAutoUpdate(array $prop) : bool {
		return $this->_updateOnApplies($prop);
	}

}