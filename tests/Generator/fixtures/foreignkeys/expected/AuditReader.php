<?php

namespace Fixture\ForeignKeys;

use PHersist\ActiveRecord;

/**
 * Class AuditReader.
 *
 * @property-read ?int $id id field: id
 * @property ?AuditEntry $lastEntry
 */
class AuditReader extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'audit_readers',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'audit_readers',
				'props' => [
					'lastEntry' => [
						'type' => 'Class',
						'fieldnames' => [
							'last_entry_id'
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
