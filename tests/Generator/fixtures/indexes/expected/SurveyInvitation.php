<?php

namespace Fixture\Indexes;

use PHersist\ActiveRecord;

/**
 * Class SurveyInvitation.
 *
 * @property-read ?int $id id field: id
 * @property User $receiver
 * @property ?User $surveyCombo
 * @property ?string $status
 * @property ?string $code
 * @property-read ?\DateTimeImmutable $sentAt
 * @property-write \DateTimeInterface|string|null $sentAt
 */
class SurveyInvitation extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'survey_invitations',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'survey_invitations',
				'props' => [
					'receiver' => [
						'type' => 'Class',
						'fieldnames' => [
							'receiver_id'
						],
						'class' => 'Fixture\\Indexes\\User',
						'required' => true,
						'on_remote_delete' => 'restrict'
					],
					'surveyCombo' => [
						'type' => 'Class',
						'fieldnames' => [
							'survey_combo_id'
						],
						'class' => 'Fixture\\Indexes\\User',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'status' => [
						'type' => 'Text',
						'fieldnames' => [
							'status'
						],
						'required' => false
					],
					'code' => [
						'type' => 'Text',
						'fieldnames' => [
							'code'
						],
						'required' => false
					],
					'sentAt' => [
						'type' => 'DateTime',
						'fieldnames' => [
							'sent_at'
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
