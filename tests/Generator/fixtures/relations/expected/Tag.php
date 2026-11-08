<?php

namespace Fixture\Relations;

use PHersist\ActiveRecord;

/**
 * Class Tag.
 *
 * @property-read ?int $id id field: id
 * @property ?string $name
 * @property Article[] $articles relation, read-only
 */
class Tag extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'tags',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'tags',
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
		'relations' => [
			'articles' => [
				'type' => 'NN',
				'class' => 'Fixture\\Relations\\Article',
				'table' => 'article_tags',
				'local_id' => 'tag_id',
				'remote_id' => 'article_id',
				'table_owner' => false,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => []
	];

}
