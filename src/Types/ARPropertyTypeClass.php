<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Class property type handles the conversion between an (ActiveRecord)
 * object in PHP to an object ID in the database and vice versa.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeClass extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$class_name = $prop['class'];
		$id = $values[$prop['fieldnames'][0]];
		return $id==null ? null : \PHersist\ActiveRecord::fetchObject($class_name, $id);
	}

	public function toDB(array $prop, mixed $value) : array {
		// This will go wrong if the related object hasn't been committed already
		return [ $prop['fieldnames'][0] => $value==null ? null : $value->id ];
	}

	public function dereference(array $prop, $sourceTable) : array|false {
		$className = $prop['class'];
		$meta = \PHersist\ActiveRecord::_getMeta($className);
		return [
			'target_table' => $meta['table'],
			'on' => '`{$source_table}`.`'.$prop['fieldnames'][0].'` = `{$target_table}`.`'.$meta['id'].'`',
			'class_name' => $className,
		];
	}
}