<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Float property type maps a single field to an object property. Ints and
 * numeric strings are accepted and converted to float.
 *
 * Values are converted to strings explicitly, because PHP's default float to
 * string conversion (used when binding parameters) only keeps 14 significant
 * digits. var_export() uses the shortest representation that round-trips.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeFloat extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		return $value === null ? null : floatval($value);
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))
			throw new \InvalidArgumentException('expected a float, got '.get_debug_type($value));
		$float = floatval($value);
		if (!is_finite($float))
			throw new \InvalidArgumentException('expected a finite float, got '.var_export($value, true));
		return $float;
	}

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value === null ? null : var_export(floatval($value), true) ];
	}
}