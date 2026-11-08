<?php

namespace Blog\Model;

use PHersist\ActiveRecord;

/**
 * Class Comment.
 *
 * @property-read ?int $id id field: id
 * @property ?Post $post
 * @property ?\PHersist\ActiveRecord $subject
 */
class Comment extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'comments',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'comments',
				'props' => [
					'post' => [
						'type' => 'Class',
						'fieldnames' => [
							'post_id'
						],
						'class' => 'Blog\\Model\\Post',
						'required' => false,
						'on_remote_delete' => 'cascade'
					],
					'subject' => [
						'type' => 'DynamicClass',
						'fieldnames' => [
							'subject_type',
							'subject_id'
						],
						'required' => false,
						'on_remote_delete' => 'cascade',
						'use_namespace' => true
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
