<?php

namespace Babble\Model;

use PHersist\ActiveRecord;

/**
 * Class Forum.
 *
 * @property-read ?int $id id field: id
 * @property string $name
 * @property ?string $description
 * @property ForumMessage[] $messages relation, ordered by created_at, read-only
 */
class Forum extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'babble',
		'table' => 'forums',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'forums',
				'props' => [
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => true
					],
					'description' => [
						'type' => 'Text',
						'fieldnames' => [
							'description'
						],
						'required' => false
					]
				]
			]
		],
		'relations' => [
			'messages' => [
				'type' => 'NN',
				'class' => 'Babble\\Model\\ForumMessage',
				'table' => 'forum_messages',
				'local_id' => 'forum_id',
				'remote_id' => 'id',
				'table_owner' => false,
				'load_objects' => true,
				'cascade_delete' => false,
				'order_field' => 'created_at'
			]
		],
		'maps' => []
	];

}
