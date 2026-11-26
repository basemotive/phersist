<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class AuditEntry.
 *
 * @property-read ?int $id id field: id
 * @property ?User $user
 * @property ?AuditEntry $previous
 */
class AuditEntry extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'audit',
		'table' => 'audit_entries',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'audit_entries',
				'props' => [
					'user' => [
						'type' => 'Class',
						'fieldnames' => [
							'user_id'
						],
						'class' => 'Fixture\\ForeignKeys\\User',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'previous' => [
						'type' => 'Class',
						'fieldnames' => [
							'previous_id'
						],
						'class' => 'Fixture\\ForeignKeys\\AuditEntry',
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
