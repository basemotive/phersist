<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class SurveySet.
 *
 * @property-read ?int $id id field: id
 * @property ?Survey $survey
 */
class SurveySet extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'survey_sets',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'survey_sets',
				'props' => [
					'survey' => [
						'type' => 'Class',
						'fieldnames' => [
							'survey_id'
						],
						'class' => 'Fixture\\ForeignKeys\\Survey',
						'required' => false,
						'on_remote_delete' => 'cascade'
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
