<?php

namespace Fixture\Indexes;

use PHersist\ActiveRecord;

/**
 * Class BenchmarkValue.
 *
 * @property-read ?int $id id field: id
 * @property ?User $survey
 * @property ?SurveyInvitation $surveyInvitation
 * @property ?string $name
 * @property ?\PHersist\ActiveRecord $subject
 */
class BenchmarkValue extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'benchmark_values',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => false,
				'table' => 'benchmark_values',
				'props' => [
					'survey' => [
						'type' => 'Class',
						'fieldnames' => [
							'survey_id'
						],
						'class' => 'Fixture\\Indexes\\User',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'surveyInvitation' => [
						'type' => 'Class',
						'fieldnames' => [
							'survey_invitation_id'
						],
						'class' => 'Fixture\\Indexes\\SurveyInvitation',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'name' => [
						'type' => 'Text',
						'fieldnames' => [
							'name'
						],
						'required' => false
					],
					'subject' => [
						'type' => 'DynamicClass',
						'fieldnames' => [
							'subject_type',
							'subject_id'
						],
						'required' => false,
						'on_remote_delete' => 'null',
						'use_namespace' => false,
						'namespace' => 'Fixture\\Indexes'
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
