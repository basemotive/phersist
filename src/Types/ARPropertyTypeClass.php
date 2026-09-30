<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The Class property type handles the conversion between an (ActiveRecord)
 * object in PHP to an object ID in the database and vice versa. Assigned
 * values must be instances of the class the property refers to (or of a
 * subclass); other values throw an exception.
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
		/** @var class-string<ActiveRecord> $class_name */
		$class_name = $prop['class'];
		$id = $values[$prop['fieldnames'][0]];
		return $id==null ? null : \PHersist\ActiveRecord::fetchObject($class_name, (int)$id);
	}

	public function toDB(array $prop, mixed $value) : array {
		// ActiveRecord::commit() makes sure the related object has been committed already
		return [ $prop['fieldnames'][0] => $value==null ? null : $value->id ];
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (!($value instanceof $prop['class']))
			throw new \InvalidArgumentException('expected '.$prop['class'].', got '.get_debug_type($value));
		return $value;
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