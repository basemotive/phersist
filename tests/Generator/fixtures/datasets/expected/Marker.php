<?php

use PHersist\ActiveRecord;

/**
 * Class Marker.
 *
 * @property-read ?int $id id field: marker_id
 */
class Marker extends ActiveRecord {
	/** @var ?array<string, mixed> $_meta */
	protected static ?array $_meta = [
		'id' => 'marker_id',
		'database' => 'main',
		'table' => 'markers',
		'softdelete' => false,
		'datasets' => [],
		'relations' => [],
		'maps' => []
	];

}
