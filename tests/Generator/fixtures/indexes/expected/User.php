<?php

namespace Fixture\Indexes;

use PHersist\ActiveRecord;

/**
 * Class User.
 *
 * @property-read ?int $id id field: id
 * @property ?string $role
 * @property ?string $userType
 * @property ?string $email
 * @property ?string $city
 * @property ?User $manager
 * @property ?string $stamp timestamp, date format: d-m-Y
 */
class User extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'users',
		'softdelete' => true,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'users',
				'props' => [
					'role' => [
						'type' => 'Text',
						'fieldnames' => [
							'role'
						],
						'required' => false
					],
					'userType' => [
						'type' => 'Text',
						'fieldnames' => [
							'user_type'
						],
						'required' => false
					],
					'email' => [
						'type' => 'Text',
						'fieldnames' => [
							'email'
						],
						'required' => false
					]
				]
			],
			[
				'name' => null,
				'autoload' => false,
				'table' => 'user_profiles',
				'props' => [
					'city' => [
						'type' => 'Text',
						'fieldnames' => [
							'city'
						],
						'required' => false
					],
					'manager' => [
						'type' => 'Class',
						'fieldnames' => [
							'manager_id'
						],
						'class' => 'Fixture\\Indexes\\User',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'stamp' => [
						'type' => 'TimestampText',
						'fieldnames' => [
							'stamp'
						],
						'date_format' => 'd-m-Y',
						'required' => false
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
