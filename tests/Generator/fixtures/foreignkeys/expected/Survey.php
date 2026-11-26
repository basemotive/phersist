<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Survey.
 *
 * @property-read ?int $id id field: id
 * @property User $owner
 * @property ?User $reviewer
 * @property ?string $status
 * @property User[] $likers relation, read-write
 */
class Survey extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'surveys',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'surveys',
				'props' => [
					'owner' => [
						'type' => 'Class',
						'fieldnames' => [
							'owner_id'
						],
						'class' => 'Fixture\\ForeignKeys\\User',
						'required' => true,
						'on_remote_delete' => 'restrict'
					],
					'reviewer' => [
						'type' => 'Class',
						'fieldnames' => [
							'reviewer_id'
						],
						'class' => 'Fixture\\ForeignKeys\\User',
						'required' => false,
						'on_remote_delete' => 'restrict'
					],
					'status' => [
						'type' => 'Text',
						'fieldnames' => [
							'status'
						],
						'required' => false
					]
				]
			]
		],
		'relations' => [
			'likers' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\User',
				'table' => 'likes',
				'local_id' => 'liked_id',
				'remote_id' => 'user_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false,
				'local_type' => 'liked_type',
				'use_namespace' => false
			]
		],
		'maps' => []
	];

}
