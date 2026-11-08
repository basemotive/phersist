<?php

namespace Fixture\Types;

use PHersist\ActiveRecord;

/**
 * Class Owner.
 *
 * @property-read ?int $id id field: id
 * @property ?string $name
 */
class Owner extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'owners',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'owners',
				'props' => [
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
		'maps' => []
	];

}
