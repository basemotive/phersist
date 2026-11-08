<?php

namespace Fixture\Relations;

use PHersist\ActiveRecord;

/**
 * Class Article.
 *
 * @property-read ?int $id id field: id
 * @property ?string $title
 * @property Author $author
 * @property Tag[] $tags relation, ordered by position, read-write
 * @property Comment[] $comments relation, read-only
 * @property Author[] $likers relation, read-write
 */
class Article extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'articles',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'articles',
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
						'class' => 'Fixture\\Relations\\Author',
						'required' => true,
						'on_remote_delete' => 'restrict'
					]
				]
			]
		],
		'relations' => [
			'tags' => [
				'type' => 'NN',
				'class' => 'Fixture\\Relations\\Tag',
				'table' => 'article_tags',
				'local_id' => 'article_id',
				'remote_id' => 'tag_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false,
				'order_field' => 'position'
			],
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
				'load_objects' => false,
				'cascade_delete' => true,
				'local_type' => 'liked_type',
				'use_namespace' => false
			]
		],
		'maps' => []
	];

}
