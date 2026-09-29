<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Date property type maps a single DATE field to an object property
 * holding a DateTimeImmutable at midnight in PHP's default timezone.
 *
 * Accepts the same values as the DateTime type. Only the date part is used,
 * as it is in the value's own timezone: '2026-01-01T23:30:00-05:00' becomes
 * 2026-01-01, even if that moment falls on another day in the default
 * timezone.
 *
 * With 'update_on' set to 'create' or 'modify', the current date is stored
 * automatically when the object is first stored or on every commit.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeDate extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		return $value === null ? null : new \DateTimeImmutable($value);
	}

	public function normalize(array $prop, mixed $value) : mixed {
		return new \DateTimeImmutable(ARPropertyTypeDateTime::parse($value)->format('Y-m-d'));
	}

	public function toDB(array $prop, mixed $value) : array {
		if ($this->requiresAutoUpdate($prop))
			$value = new \DateTimeImmutable();
		return [ $prop['fieldnames'][0] => $value === null ? null : $this->normalize($prop, $value)->format('Y-m-d') ];
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
}