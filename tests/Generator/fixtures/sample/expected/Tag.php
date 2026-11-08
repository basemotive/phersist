<?php

namespace Babble\Model;

use PHersist\ActiveRecord;

/**
 * Class Tag.
 *
 * @property-read ?int $id id field: id
 * @property ?string $tagName
 */
class Tag extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'babble',
		'table' => 'tags',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'tags',
				'props' => [
					'tagName' => [
						'type' => 'Text',
						'fieldnames' => [
							'tag_name'
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
