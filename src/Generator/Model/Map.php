<?php

namespace PHersist\Generator\Model;

/**
 * A <map> of a class: nested keys with values, stored as rows in a table.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Map {
	/**
	 * @param string $name the map name
	 * @param string $table the table
	 * @param string $idColumn the column with the id of the object that has the map
	 * @param ?string $typeColumn the column with the class name of that object, or null
	 * @param non-empty-list<string> $keys the key columns, outermost first
	 * @param string $value the value column
	 * @param bool $useNamespace whether the type column stores the class name with its namespace
	 * @param int $line the line of the element in the XML
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $table,
		public readonly string $idColumn,
		public readonly ?string $typeColumn,
		public readonly array $keys,
		public readonly string $value,
		public readonly bool $useNamespace,
		public readonly int $line,
	) {}
}
