<?php

namespace Babble\Model;

use PHersist\ActiveRecord;

/**
 * Class User.
 *
 * @property-read ?int $id id field: id
 * @property ?string $email
 * @property ?string $password
 * @property ?string $name
 * @property \PHersist\Maps\Map $userSettings map
 */
class User extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'babble',
		'table' => 'users',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => 'main',
				'autoload' => true,
				'table' => 'users',
				'props' => [
					'email' => [
						'type' => 'Text',
						'fieldnames' => [
							'email'
						],
						'required' => false
					],
					'password' => [
						'type' => 'Text',
						'fieldnames' => [
							'password'
						],
						'required' => false
					],
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => false
					]
				]
			]
		],
		'relations' => [],
		'maps' => [
			'userSettings' => [
				'table' => 'prop_map',
				'id' => 'id',
				'type' => 'object_type',
				'activeRecordKey' => 'userSettings',
				'keys' => [
					'key'
				],
				'value' => 'value',
				'use_namespace' => false
			]
		]
	];

}
