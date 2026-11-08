<?php

use PHersist\ActiveRecord;

/**
 * Class Person.
 *
 * @property-read ?int $id id field: person_id
 * @property string $name
 * @property ?string $bio
 * @property ?int $age
 * @property ?string $nickname
 */
class Person extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'person_id',
		'database' => 'main',
		'table' => 'persons',
		'softdelete' => true,
		'datasets' => [
			[
				'name' => 'core',
				'autoload' => true,
				'table' => 'persons',
				'props' => [
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => true
					]
				]
			],
			[
				'name' => 'details',
				'autoload' => false,
				'table' => 'person_details',
				'props' => [
					'bio' => [
						'type' => 'Text',
						'fieldnames' => [
							'bio'
						],
						'required' => false
					],
					'age' => [
						'type' => 'Int',
						'fieldnames' => [
							'age'
						],
						'signed' => true,
						'required' => false
					]
				]
			],
			[
				'name' => null,
				'autoload' => false,
				'table' => 'person_extras',
				'props' => [
					'nickname' => [
						'type' => 'Text',
						'fieldnames' => [
							'nickname'
						],
						'required' => false
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
