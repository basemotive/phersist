<?php

namespace Blog\Model;

use PHersist\ActiveRecord;

/**
 * Class Post.
 *
 * @property-read ?int $id id field: id
 * @property ?string $title
 * @property \Core\Model\User $author
 * @property ?\Core\Model\User $editor
 * @property \Core\Model\User[] $likers relation, read-write
 * @property Comment[] $comments relation, read-only
 */
class Post extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'posts',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'posts',
				'props' => [
					'title' => [
						'type' => 'Text',
						'fieldnames' => [
							'title'
						],
						'required' => false
					],
					'author' => [
						'type' => 'Class',
						'fieldnames' => [
							'author_id'
						],
						'class' => 'Core\\Model\\User',
						'required' => true,
						'on_remote_delete' => 'restrict'
					],
					'editor' => [
						'type' => 'Class',
						'fieldnames' => [
							'editor_id'
						],
						'class' => 'Core\\Model\\User',
						'required' => false,
						'on_remote_delete' => 'cascade'
					]
				]
			]
		],
		'relations' => [
			'likers' => [
				'type' => 'NN',
				'class' => 'Core\\Model\\User',
				'table' => 'post_likes',
				'local_id' => 'post_id',
				'remote_id' => 'user_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false
			],
			'comments' => [
				'type' => 'NN',
				'class' => 'Blog\\Model\\Comment',
				'table' => 'comments',
				'local_id' => 'post_id',
				'remote_id' => 'id',
				'table_owner' => false,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => []
	];

}
