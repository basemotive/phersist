<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Int property type is maps a single field to an object property. Valuues
 * should be checked to be of integers.
 * TODO introduce value checking to PHersist
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeInt extends ARPropertyType {
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