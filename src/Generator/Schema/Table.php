<?php

namespace PHersist\Generator\Schema;

/**
 * A table in the schema.
 *
 * A table can be shared, like a join table that is defined from both sides,
 * or a map table of several classes. Each one adds the columns and indexes
 * that it needs, and those that the table already has are left as they are.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Table {
	/**
	 * @param string $name the table name
	 */
	public function __construct(
		public readonly string $name,
	) {}

	/**
	 * Adds a column, unless the table already has a column with that name.
	 *
	 * @param Column $column the column
	 * @param bool $primaryKey whether the column is the primary key
	 */
	public function addColumn(Column $column, bool $primaryKey = false) : void {
		foreach ($this->columns as $existing)
			if ($existing->name === $column->name)
				return;
		$this->columns[] = $column;
		if ($primaryKey)
			$this->primaryKey = $column->name;
	}

	/**
	 * Adds an index, unless the table already has an index with that name.
	 *
	 * @param Index $index the index
	 */
	public function addIndex(Index $index) : void {
		foreach ($this->indexes as $existing)
			if ($existing->name === $index->name)
				return;
		$this->indexes[] = $index;
	}

	/**
	 * Returns a column of the table.
	 *
	 * @param string $name the column name
	 * @return ?Column the column, or null if the table doesn't have it
	 */
	public function column(string $name) : ?Column {
		foreach ($this->columns as $column)
			if ($column->name === $name)
				return $column;
		return null;
	}

	/** @var list<Column> the columns, in the order they were added */
	public array $columns = [];

	/** @var ?string the primary key column, or null if the table has none */
	public ?string $primaryKey = null;

	/** @var list<Index> the indexes, in the order they were added */
	public array $indexes = [];
}
