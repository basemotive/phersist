<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Group.
 *
 * @property-read ?int $id id field: id
 * @property ?string $name
 * @property ?Group $parent
 * @property User[] $users relation, read-write
 * @property \PHersist\Maps\Map $notes map
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
				'autoload' => true,
				'table' => 'groups',
				'props' => [
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => false
					],
					'parent' => [
						'type' => 'Class',
						'fieldnames' => [
							'parent_id'
						],
						'class' => 'Fixture\\ForeignKeys\\Group',
						'required' => false,
						'on_remote_delete' => 'null'
					]
				]
			]
		],
		'relations' => [
			'users' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\User',
				'table' => 'user_groups',
				'local_id' => 'group_id',
				'remote_id' => 'user_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => [
			'notes' => [
				'table' => 'notes',
				'id' => 'owner_id',
				'type' => 'owner_type',
				'activeRecordKey' => 'notes',
				'keys' => [
					'name'
				],
				'value' => 'value',
				'use_namespace' => false
			]
		]
	];

}
