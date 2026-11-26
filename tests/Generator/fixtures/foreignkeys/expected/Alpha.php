<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Alpha.
 *
 * @property-read ?int $id id field: id
 * @property ?User $user
 */
class Alpha extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'a_b',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'a_b',
				'props' => [
					'user' => [
						'type' => 'Class',
						'fieldnames' => [
							'c'
						],
						'class' => 'Fixture\\ForeignKeys\\User',
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
