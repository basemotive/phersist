<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Float property type maps a single field to an object property. Values
 * should be checked to be of type float.
 * TODO introduce value checking to PHersist
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

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value === null ? null : var_export(floatval($value), true) ];
	}
}