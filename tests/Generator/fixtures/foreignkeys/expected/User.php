<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class User.
 *
 * @property-read ?int $id id field: id
 * @property ?string $name
 * @property ?SurveySet $surveySet
 * @property ?string $bio
 * @property ?Upload $avatar
 * @property Group[] $groups relation, read-write
 * @property Survey[] $surveys relation, read-only
 * @property User[] $friends relation, read-write
 * @property \PHersist\Maps\Map $settings map
 * @property \PHersist\Maps\Map $notes map
 */
class User extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'users',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'users',
				'props' => [
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => false
					],
					'surveySet' => [
						'type' => 'Class',
						'fieldnames' => [
							'survey_set_id'
						],
						'class' => 'Fixture\\ForeignKeys\\SurveySet',
						'required' => false,
						'on_remote_delete' => 'null'
					]
				]
			],
			[
				'name' => null,
				'autoload' => false,
				'table' => 'user_profiles',
				'props' => [
					'bio' => [
						'type' => 'Text',
						'fieldnames' => [
							'bio'
						],
						'required' => false
					],
					'avatar' => [
						'type' => 'Class',
						'fieldnames' => [
							'avatar_id'
						],
						'class' => 'Fixture\\ForeignKeys\\Upload',
						'required' => false,
						'on_remote_delete' => 'null'
					]
				]
			]
		],
		'relations' => [
			'groups' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\Group',
				'table' => 'user_groups',
				'local_id' => 'user_id',
				'remote_id' => 'group_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false
			],
			'surveys' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\Survey',
				'table' => 'surveys',
				'local_id' => 'owner_id',
				'remote_id' => 'id',
				'table_owner' => false,
				'load_objects' => false,
				'cascade_delete' => false
			],
			'friends' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\User',
				'table' => 'friendships',
				'local_id' => 'user_id',
				'remote_id' => 'friend_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => [
			'settings' => [
				'table' => 'user_settings',
				'id' => 'user_id',
				'type' => false,
				'activeRecordKey' => 'settings',
				'keys' => [
					'name'
				],
				'value' => 'value',
				'use_namespace' => false
			],
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
