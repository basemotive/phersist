<?php

namespace PHersist\Generator\Model;

/**
 * An <index> of a class: an index on one of its tables, with the columns of
 * its <field>s resolved.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Index {
	/**
	 * @param string $name the index name, unique in its table
	 * @param string $table the table, one of the class's tables
	 * @param non-empty-list<string> $columns the column names, in order; both
	 *   columns for a DynamicClass property
	 * @param array<string, int> $lengths the prefix lengths, by column name,
	 *   for the columns that have one
	 * @param bool $unique whether the combination of values must be unique
	 * @param int $line the line of the element in the XML
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $table,
		public readonly array $columns,
		public readonly array $lengths,
		public readonly bool $unique,
		public readonly int $line,
	) {}
}
