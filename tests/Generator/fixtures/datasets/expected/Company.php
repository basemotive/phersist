<?php

use PHersist\ActiveRecord;

/**
 * Class Company.
 *
 * @property-read ?int $id id field: firm_no
 * @property ?string $title
 * @property ?Person $boss
 */
class Company extends ActiveRecord {
	use Some\CompanyTrait;

	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'firm_no',
		'database' => 'other',
		'table' => 'firms',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'firms',
				'props' => [
					'title' => [
						'type' => 'Text',
						'fieldnames' => [
							'title'
						],
						'required' => false
					],
					'boss' => [
						'type' => 'Class',
						'fieldnames' => [
							'boss_id'
						],
						'class' => 'Person',
						'required' => false,
						'on_remote_delete' => 'null'
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
