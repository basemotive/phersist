<?php

namespace Fixture\Relations;

use PHersist\ActiveRecord;

/**
 * Class Comment.
 *
 * @property-read ?int $id id field: id
 * @property ?string $body
 * @property \PHersist\ActiveRecord $subject
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
					'body' => [
						'type' => 'Text',
						'fieldnames' => [
							'body'
						],
						'required' => false
					],
					'subject' => [
						'type' => 'DynamicClass',
						'fieldnames' => [
							'subject_type',
							'subject_id'
						],
						'required' => true,
						'on_remote_delete' => 'cascade',
						'use_namespace' => false,
						'namespace' => 'Fixture\\Relations'
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
