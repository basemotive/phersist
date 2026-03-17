<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The DynamicClass property type handles the conversion between an
 * (ActiveRecord) object in PHP to an object ID and its classname
 * in the database and vice versa.
 *
 * Contrary to the Class property, this object reference is not
 * statically typed, but stored in the database next to the id.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeDynamicClass extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$class_name = $values[$prop['fieldnames'][0]];
		$id = $values[$prop['fieldnames'][1]];
		return $id==null ? null : new $class_name($id);
	}

	public function toDB(array $prop, mixed $value) : array {
		// This will go wrong if the related object hasn't been committed already
		$class_name = $value==null ? null : get_class($value);
		$id = $value==null ? null : $value->id;
		// Note / TODO:
		//
		// References to null are not handled very graciously. They are currently simply stored
		// as empty strings, but it would be neater if they were actual database NULLs.
		// However, the underlying query builders never assume that, and basically just add
		// the quotes and do the escaping, making null checks problematic.
		//
		// Shifting quoting and escaping to the ARPropertyType classes could fix this, but
		// may add other problems.
		// The query builders could also be modified to handle null values as actuall NULLs, but
		// this may introduce side problems.
		return [
			$prop['fieldnames'][0] => $class_name,
			$prop['fieldnames'][1] => $id
		];
	}

	// This class lacks the dereference method, because you'd have to dynamically
	// join in all the possible tables.
	// Just building your own SQL query and feeding the results into the ORM system is probably
	// the best here.
}