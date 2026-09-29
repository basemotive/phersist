<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Bool property type maps a single field to an object property, storing
 * 1 for true and 0 for false. Values read from the database are returned as
 * bool. Assigned ints 0 and 1 and strings '0' and '1' are converted to bool;
 * other values throw an exception, so mistakes like assigning 'false' (which
 * PHP considers true) don't go unnoticed.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeBool extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$value = $values[$prop['fieldnames'][0]];
		return $value === null ? null : $value != 0;
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (is_bool($value))
			return $value;
		if ($value === 0 || $value === '0')
			return false;
		if ($value === 1 || $value === '1')
			return true;
		throw new \InvalidArgumentException('expected a bool, got '.(is_string($value) ? "'$value'" : get_debug_type($value)));
	}

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value === null ? null : ($value ? 1 : 0) ];
	}
}
