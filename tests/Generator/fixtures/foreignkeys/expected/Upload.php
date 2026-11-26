<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Upload.
 *
 * @property-read ?int $id id field: id
 * @property ?\PHersist\ActiveRecord $subject
 * @property User[] $likers relation, read-write
 * @property Group[] $tags relation, read-write
 */
class Upload extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'uploads',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'uploads',
				'props' => [
					'subject' => [
						'type' => 'DynamicClass',
						'fieldnames' => [
							'subject_type',
							'subject_id'
						],
						'required' => false,
						'on_remote_delete' => 'cascade',
						'use_namespace' => false,
						'namespace' => 'Fixture\\ForeignKeys'
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
			],
			'tags' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\Group',
				'table' => 'taggings',
				'local_id' => 'item_id',
				'remote_id' => 'tagged_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false,
				'local_type' => 'item_type',
				'use_namespace' => false
			]
		],
		'maps' => []
	];

}
