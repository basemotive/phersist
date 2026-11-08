<?php

namespace Fixture\Relations;

use PHersist\ActiveRecord;

/**
 * Class Photo.
 *
 * @property-read ?int $id id field: id
 * @property ?string $url
 * @property Comment[] $comments relation, read-only
 * @property Author[] $likers relation, read-write
 */
class Photo extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'photos',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'photos',
				'props' => [
					'url' => [
						'type' => 'Text',
						'fieldnames' => [
							'url'
						],
						'required' => false
					]
				]
			]
		],
		'relations' => [
			'comments' => [
				'type' => 'NN',
				'class' => 'Fixture\\Relations\\Comment',
				'table' => 'comments',
				'local_id' => 'subject_id',
				'remote_id' => 'id',
				'table_owner' => false,
				'load_objects' => false,
				'cascade_delete' => false,
				'local_type' => 'subject_type',
				'use_namespace' => false
			],
			'likers' => [
				'type' => 'NN',
				'class' => 'Fixture\\Relations\\Author',
				'table' => 'likes',
				'local_id' => 'liked_id',
				'remote_id' => 'author_id',
				'table_owner' => true,
				'load_objects' => true,
				'cascade_delete' => false,
				'local_type' => 'liked_type',
				'use_namespace' => false
			]
		],
		'maps' => []
	];

}
