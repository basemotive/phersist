<?php

namespace Fixture\Maps;

use PHersist\ActiveRecord;

/**
 * Class Account.
 *
 * @property-read ?int $id id field: account_id
 * @property ?string $name
 * @property \PHersist\Maps\Map $settings map
 * @property \PHersist\Maps\Map $matrix map
 * @property \PHersist\Maps\Map $flags map
 * @property \PHersist\Maps\Map $labels map
 */
class Account extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'account_id',
		'database' => 'main',
		'table' => 'accounts',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'accounts',
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
		'maps' => [
			'settings' => [
				'table' => 'settings',
				'id' => 'owner_id',
				'type' => 'owner_type',
				'activeRecordKey' => 'settings',
				'keys' => [
					'section',
					'name'
				],
				'value' => 'value',
				'use_namespace' => false
			],
			'matrix' => [
				'table' => 'account_matrix',
				'id' => 'acc',
				'type' => false,
				'activeRecordKey' => 'matrix',
				'keys' => [
					'a',
					'b',
					'c',
					'd',
					'e'
				],
				'value' => 'v',
				'use_namespace' => false
			],
			'flags' => [
				'table' => 'account_flags',
				'id' => 'account_id',
				'type' => false,
				'activeRecordKey' => 'flags',
				'keys' => [
					'flag'
				],
				'value' => 'state',
				'use_namespace' => false
			],
			'labels' => [
				'table' => 'account_labels',
				'id' => 'account_id',
				'type' => 'owner_type',
				'activeRecordKey' => 'labels',
				'keys' => [
					'a',
					'b',
					'c',
					'd'
				],
				'value' => 'label',
				'use_namespace' => false
			]
		]
	];

}
