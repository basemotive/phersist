<?php

namespace Core\Model;

use PHersist\ActiveRecord;

/**
 * Class User.
 *
 * @property-read ?int $id id field: id
 * @property string $name
 * @property Group[] $groups relation, read-write
 */
class User extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'users',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'users',
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
			'groups' => [
				'type' => 'NN',
				'class' => 'Core\\Model\\Group',
				'table' => 'user_groups',
				'local_id' => 'user_id',
				'remote_id' => 'group_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => []
	];

}
