<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The DateTime property type maps a single DATETIME field to an object
 * property holding a DateTimeImmutable.
 *
 * Besides DateTimeInterface objects, strings like '2026-01-01',
 * '2026-01-01 12:30:00' and ISO 8601 strings with an offset like
 * '2026-01-01T12:30:00+02:00' or '2026-01-01T10:30:00.000Z' can be assigned.
 *
 * DATETIME columns don't store a timezone, so values are converted to PHP's
 * default timezone (date_default_timezone_get()) and stored in that zone. The
 * moment in time is preserved, the original offset is not. Values are stored
 * with a precision of seconds, so fractions of seconds are dropped.
 *
 * With 'update_on' set to 'create' or 'modify', the current time is stored
 * automatically when the object is first stored or on every commit.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeDateTime extends ARPropertyType {
	/** Dates, optionally followed by a time with optional (fractional) seconds and offset */
	const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		return $value === null ? null : new \DateTimeImmutable($value);
	}

	public function normalize(array $prop, mixed $value) : mixed {
		$dateTime = self::parse($value)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		// Drop the fractions of seconds, which the DATETIME column doesn't store
		return $dateTime->setTime((int)$dateTime->format('G'), (int)$dateTime->format('i'), (int)$dateTime->format('s'));
	}

	public function toDB(array $prop, mixed $value) : array {
		if ($this->requiresAutoUpdate($prop))
			$value = new \DateTimeImmutable();
		return [ $prop['fieldnames'][0] => $value === null ? null : $this->normalize($prop, $value)->format('Y-m-d H:i:s') ];
	}

	public function requiresAutoUpdate(array $prop) : bool {
		return $this->_updateOnApplies($prop);
	}

	public function toDBSearch(array $prop, mixed $value) : array {
		// Values that aren't dates, like patterns for LIKE, are passed as-is
		try {
			return $this->toDB($prop, $value);
		} catch (\InvalidArgumentException $e) {
			return [ $prop['fieldnames'][0] => $value ];
		}
	}

	/**
	 * Parses a date/time value, keeping its own timezone. Strings without an
	 * offset are interpreted in the default timezone.
	 *
	 * Only the formats in PATTERN are accepted, and impossible dates like
	 * '2026-02-30' are rejected, instead of letting PHP roll them over.
	 *
	 * @param mixed $value a DateTimeInterface or a string
	 * @return \DateTimeImmutable the parsed date/time
	 * @throws \InvalidArgumentException if the value is not a valid date/time
	 */
	public static function parse(mixed $value) : \DateTimeImmutable {
		if ($value instanceof \DateTimeInterface)
			return \DateTimeImmutable::createFromInterface($value);

		if (!is_string($value))
			throw new \InvalidArgumentException('expected a date/time, got '.get_debug_type($value));

		if (!preg_match(self::PATTERN, $value, $matches)
				|| !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
				|| (int)($matches[4] ?? 0) > 23 || (int)($matches[5] ?? 0) > 59 || (int)($matches[6] ?? 0) > 59)
			throw new \InvalidArgumentException("'$value' is not a valid date/time");

		return new \DateTimeImmutable($value);
	}
}