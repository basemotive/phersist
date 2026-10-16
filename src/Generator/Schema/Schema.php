<?php

namespace PHersist\Generator\Schema;

/**
 * The tables that a model needs, independent of the database. SchemaBuilder
 * builds it, and the schema generators write it as SQL.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Schema {
	/**
	 * Returns a table, adding it if the schema doesn't have it yet.
	 *
	 * @param string $name the table name
	 */
	public function table(string $name) : Table {
		return $this->tables[$name] ??= new Table($name);
	}

	/**
	 * Returns the tables, in the order they were added.
	 *
	 * @return list<Table>
	 */
	public function tables() : array {
		return array_values($this->tables);
	}

	/** @var array<string, Table> */
	private array $tables = [];
}
