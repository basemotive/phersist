<?php

namespace Fixture\Relations;

use PHersist\ActiveRecord;

/**
 * Class Author.
 *
 * @property-read ?int $id id field: id
 * @property ?string $name
 * @property Article[] $articles relation, read-only
 * @property Author[] $friends relation, read-write
 */
class Author extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'authors',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'authors',
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
				'table' => 'articles',
				'local_id' => 'author_id',
				'remote_id' => 'id',
				'table_owner' => false,
				'load_objects' => false,
				'cascade_delete' => false
			],
			'friends' => [
				'type' => 'NN',
				'class' => 'Fixture\\Relations\\Author',
				'table' => 'friendships',
				'local_id' => 'author_id',
				'remote_id' => 'friend_id',
				'table_owner' => true,
				'load_objects' => false,
				'cascade_delete' => false
			]
		],
		'maps' => []
	];

}
