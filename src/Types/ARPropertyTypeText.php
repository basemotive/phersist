<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Text property type is the simplest of types, because it always maps
 * a single field to an object property without having to do any checks or
 * conversions.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeText extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		return $values[$prop['fieldnames'][0]];
	}

	public function toDB(array $prop, mixed $value) : array {
		return [ $prop['fieldnames'][0] => $value ];
	}
}