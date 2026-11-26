<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class Invitation.
 *
 * @property-read ?int $id id field: id
 * @property ?Survey $survey
 * @property ?User $sender
 * @property ?User $receiver
 */
class Invitation extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'invitations',
		'softdelete' => true,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'invitations',
				'props' => [
					'survey' => [
						'type' => 'Class',
						'fieldnames' => [
							'survey_id'
						],
						'class' => 'Fixture\\ForeignKeys\\Survey',
						'required' => false,
						'on_remote_delete' => 'cascade'
					],
					'sender' => [
						'type' => 'Class',
						'fieldnames' => [
							'sender_id'
						],
						'class' => 'Fixture\\ForeignKeys\\User',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'receiver' => [
						'type' => 'Class',
						'fieldnames' => [
							'receiver_id'
						],
						'class' => 'Fixture\\ForeignKeys\\User',
						'required' => false,
						'on_remote_delete' => 'restrict'
					]
				]
			]
		],
		'relations' => [],
		'maps' => []
	];

}
