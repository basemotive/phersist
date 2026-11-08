<?php

namespace Fixture\Maps;

use PHersist\ActiveRecord;

/**
 * Class Team.
 *
 * @property-read ?int $id id field: team_id
 * @property \PHersist\Maps\Map $settings map
 */
class Team extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'team_id',
		'database' => 'main',
		'table' => 'teams',
		'softdelete' => false,
		'datasets' => [],
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
				'use_namespace' => true
			]
		]
	];

}
