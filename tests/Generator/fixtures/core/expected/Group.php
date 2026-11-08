<?php

namespace Core\Model;

use PHersist\ActiveRecord;

/**
 * Class Group.
 *
 * @property-read ?int $id id field: id
 * @property string $name
 * @property User[] $users relation, read-only
 */
class Group extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'groups',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'groups',
				'props' => [
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => true
					]
				]
			]
		],
		'relations' => [
			'users' => [
				'type' => 'NN',
				'class' => 'Core\\Model\\User',
				'table' => 'user_groups',
				'local_id' => 'group_id',
				'remote_id' => 'user_id',
				'table_owner' => false,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => []
	];

}
