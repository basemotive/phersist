<?php

namespace Babble\Model;

use PHersist\ActiveRecord;

/**
 * Class ForumMessage.
 *
 * @property-read ?int $id id field: id
 * @property Forum $forum
 * @property ?ForumMessage $parentMessage
 * @property string $title
 * @property string $messageSummary
 * @property string $createdAt timestamp, updates on create
 * @property ?string $modifiedAt timestamp
 * @property User $user
 * @property int $viewCount default 0
 * @property string $messageContent
 * @property ForumMessage[] $replies relation, read-only
 * @property Tag[] $tags relation, read-write
 * @property \PHersist\Maps\Map $prop map
 */
class ForumMessage extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'babble',
		'table' => 'forum_messages',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'forum_messages',
				'props' => [
					'forum' => [
						'type' => 'Class',
						'fieldnames' => [
							'forum_id'
						],
						'class' => 'Babble\\Model\\Forum',
						'required' => true,
						'on_remote_delete' => 'restrict'
					],
					'parentMessage' => [
						'type' => 'Class',
						'fieldnames' => [
							'parent_message_id'
						],
						'class' => 'Babble\\Model\\ForumMessage',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'title' => [
						'type' => 'Text',
						'fieldnames' => [
							'title'
						],
						'required' => true
					],
					'messageSummary' => [
						'type' => 'Text',
						'fieldnames' => [
							'message_summary'
						],
						'required' => true
					],
					'createdAt' => [
						'type' => 'TimestampText',
						'fieldnames' => [
							'created_at'
						],
						'update_on' => 'create',
						'required' => true
					],
					'modifiedAt' => [
						'type' => 'TimestampText',
						'fieldnames' => [
							'modified_at'
						],
						'required' => false
					],
					'user' => [
						'type' => 'Class',
						'fieldnames' => [
							'user_id'
						],
						'class' => 'Babble\\Model\\User',
						'required' => true,
						'on_remote_delete' => 'restrict'
					],
					'viewCount' => [
						'type' => 'Int',
						'fieldnames' => [
							'view_count'
						],
						'signed' => false,
						'required' => true,
						'default' => 0
					]
				]
			],
			[
				'name' => null,
				'autoload' => false,
				'table' => 'forum_messages',
				'props' => [
					'messageContent' => [
						'type' => 'Text',
						'fieldnames' => [
							'message_content'
						],
						'required' => true
					]
				]
			]
		],
		'relations' => [
			'replies' => [
				'type' => 'NN',
				'class' => 'Babble\\Model\\ForumMessage',
				'table' => 'forum_messages',
				'local_id' => 'parent_message_id',
				'remote_id' => 'id',
				'table_owner' => false,
				'load_objects' => true,
				'cascade_delete' => false
			],
			'tags' => [
				'type' => 'NN',
				'class' => 'Babble\\Model\\Tag',
				'table' => 'forum_message_tags',
				'local_id' => 'forum_message_id',
				'remote_id' => 'tag_id',
				'table_owner' => true,
				'load_objects' => true,
				'cascade_delete' => false
			]
		],
		'maps' => [
			'prop' => [
				'table' => 'prop_map',
				'id' => 'id',
				'type' => 'object_type',
				'activeRecordKey' => 'prop',
				'keys' => [
					'key'
				],
				'value' => 'value',
				'use_namespace' => false
			]
		]
	];

}
