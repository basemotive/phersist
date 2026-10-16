<?php

namespace PHersist\Generator\Schema;

/**
 * A column of a table in the schema.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Column {
	/**
	 * @param string $name the column name
	 * @param ColumnType $type what the column holds
	 * @param bool $nullable whether the column allows NULL
	 * @param string|int|float|bool|null $default the column default, or null for none;
	 *   for a Decimal column a normalized number as a string
	 * @param bool $autoIncrement whether the database hands out the values (only for an Id)
	 * @param ?int $precision for a Decimal column: the total number of digits
	 * @param ?int $scale for a Decimal column: the number of digits after the decimal point
	 */
	public function __construct(
		public readonly string $name,
		public readonly ColumnType $type,
		public readonly bool $nullable = false,
		public readonly string|int|float|bool|null $default = null,
		public readonly bool $autoIncrement = false,
		public readonly ?int $precision = null,
		public readonly ?int $scale = null,
	) {}
}
