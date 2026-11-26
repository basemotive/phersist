<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Report.
 *
 * @property-read ?int $id id field: id
 * @property User[] $tags relation, read-write
 */
class Report extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'reports',
		'softdelete' => false,
		'datasets' => [],
		'relations' => [
			'tags' => [
				'type' => 'NN',
				'class' => 'Fixture\\ForeignKeys\\User',
				'table' => 'taggings',
				'local_id' => 'item_id',
				'remote_id' => 'tagged_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false,
				'local_type' => 'item_type',
				'use_namespace' => false
			]
		],
		'maps' => []
	];

}
