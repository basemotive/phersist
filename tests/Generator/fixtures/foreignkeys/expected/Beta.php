<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Beta.
 *
 * @property-read ?int $id id field: id
 * @property ?User $user
 */
class Beta extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'a',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'a',
				'props' => [
					'user' => [
						'type' => 'Class',
						'fieldnames' => [
							'b_c'
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
