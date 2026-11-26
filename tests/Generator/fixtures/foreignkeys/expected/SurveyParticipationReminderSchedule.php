<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class SurveyParticipationReminderSchedule.
 *
 * @property-read ?int $id id field: id
 * @property ?SurveySet $originatingSurveySetTemplate
 */
class SurveyParticipationReminderSchedule extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'survey_participation_reminder_schedules',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'survey_participation_reminder_schedules',
				'props' => [
					'originatingSurveySetTemplate' => [
						'type' => 'Class',
						'fieldnames' => [
							'originating_survey_set_template_id'
						],
						'class' => 'Fixture\\ForeignKeys\\SurveySet',
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
