<?php

namespace Fixture\Types;

use PHersist\ActiveRecord;

/**
 * Class Item.
 *
 * @property-read ?int $id id field: id
 * @property string $title default 'It\'s a \\ \'test\' *\/'
 * @property ?string $notes
 * @property ?int $count default -3
 * @property int $stock default 0
 * @property-read ?float $ratio default 1.5
 * @property-write int|float|string|null $ratio
 * @property-read ?string $price default '9.9900' decimal(12,4)
 * @property-write int|float|string|null $price
 * @property-read ?string $amount decimal(10,2)
 * @property-write int|float|string|null $amount
 * @property bool $active default true
 * @property ?bool $hidden default false
 * @property-read ?\DateTimeImmutable $birthday
 * @property-write \DateTimeInterface|string|null $birthday
 * @property-read \DateTimeImmutable $createdAt updates on create
 * @property-write \DateTimeInterface|string $createdAt
 * @property-read ?\DateTimeImmutable $modifiedAt updates on modify
 * @property-write \DateTimeInterface|string|null $modifiedAt
 * @property ?string $stamp timestamp, updates on modify
 * @property ?string $customStamp timestamp, date format: d-m-Y
 * @property ?Owner $owner
 * @property Owner $creator
 * @property ?\PHersist\ActiveRecord $subject
 * @property ?\PHersist\ActiveRecord $target
 * @property ?\Other\Lib\Thing $external
 */
class Item extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'id',
		'database' => 'main',
		'table' => 'items',
		'softdelete' => false,
		'datasets' => [
			[
				'name' => null,
				'autoload' => true,
				'table' => 'items',
				'props' => [
					'title' => [
						'type' => 'Text',
						'fieldnames' => [
							'title'
						],
						'required' => true,
						'default' => 'It\'s a \\ \'test\' */'
					],
					'notes' => [
						'type' => 'Text',
						'fieldnames' => [
							'notes'
						],
						'required' => false
					],
					'count' => [
						'type' => 'Int',
						'fieldnames' => [
							'count'
						],
						'signed' => true,
						'required' => false,
						'default' => -3
					],
					'stock' => [
						'type' => 'Int',
						'fieldnames' => [
							'stock'
						],
						'signed' => false,
						'required' => true,
						'default' => 0
					],
					'ratio' => [
						'type' => 'Float',
						'fieldnames' => [
							'ratio'
						],
						'required' => false,
						'default' => 1.5
					],
					'price' => [
						'type' => 'Decimal',
						'fieldnames' => [
							'price'
						],
						'precision' => 12,
						'scale' => 4,
						'required' => false,
						'default' => '9.9900'
					],
					'amount' => [
						'type' => 'Decimal',
						'fieldnames' => [
							'amount'
						],
						'precision' => 10,
						'scale' => 2,
						'required' => false
					],
					'active' => [
						'type' => 'Bool',
						'fieldnames' => [
							'active'
						],
						'required' => true,
						'default' => true
					],
					'hidden' => [
						'type' => 'Bool',
						'fieldnames' => [
							'hidden'
						],
						'required' => false,
						'default' => false
					],
					'birthday' => [
						'type' => 'Date',
						'fieldnames' => [
							'birthday'
						],
						'required' => false
					],
					'createdAt' => [
						'type' => 'DateTime',
						'fieldnames' => [
							'created_at'
						],
						'update_on' => 'create',
						'required' => true
					],
					'modifiedAt' => [
						'type' => 'DateTime',
						'fieldnames' => [
							'modified_at'
						],
						'update_on' => 'modify',
						'required' => false
					],
					'stamp' => [
						'type' => 'TimestampText',
						'fieldnames' => [
							'stamp'
						],
						'update_on' => 'modify',
						'required' => false
					],
					'customStamp' => [
						'type' => 'TimestampText',
						'fieldnames' => [
							'custom_stamp'
						],
						'date_format' => 'd-m-Y',
						'required' => false
					],
					'owner' => [
						'type' => 'Class',
						'fieldnames' => [
							'owner_ref'
						],
						'class' => 'Fixture\\Types\\Owner',
						'required' => false,
						'on_remote_delete' => 'null'
					],
					'creator' => [
						'type' => 'Class',
						'fieldnames' => [
							'creator_id'
						],
						'class' => 'Fixture\\Types\\Owner',
						'required' => true,
						'on_remote_delete' => 'cascade'
					],
					'subject' => [
						'type' => 'DynamicClass',
						'fieldnames' => [
							'subject_type',
							'subject_id'
						],
						'required' => false,
						'on_remote_delete' => 'restrict',
						'use_namespace' => false,
						'namespace' => 'Fixture\\Types'
					],
					'target' => [
						'type' => 'DynamicClass',
						'fieldnames' => [
							'target_class',
							'target_ref'
						],
						'required' => false,
						'on_remote_delete' => 'null',
						'use_namespace' => true
					],
					'external' => [
						'type' => 'Class',
						'fieldnames' => [
							'external_id'
						],
						'class' => 'Other\\Lib\\Thing',
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
