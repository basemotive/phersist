<?php

namespace PHersist\Generator;

use PHersist\Generator\Model\Project;
use PHersist\Generator\Schema\Column;
use PHersist\Generator\Schema\Index;
use PHersist\Generator\Schema\SchemaBuilder;
use PHersist\Generator\Schema\Table;

/**
 * Generates the SQL schema of a model for one database. SchemaBuilder works
 * out the tables, so a subclass only has to write them in its SQL dialect.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
abstract class SchemaGenerator {
	/**
	 * @param Project $project the model to generate the schema for
	 */
	public function __construct(protected Project $project) {}

	/**
	 * Generates the schema.
	 *
	 * @return string the SQL that (re)creates the tables
	 */
	public function generate() : string {
		$result = '';
		foreach (SchemaBuilder::build($this->project)->tables() as $table)
			$result .= $this->createTable($table, $this->indexes($table));
		return $result;
	}

	/**
	 * Returns the indexes to create for a table: the optional ones only if this
	 * database supports them, and without the automatic ones that another
	 * index already covers, because their columns (with the same prefix
	 * lengths) are the start of its columns. The indexes from the XML are
	 * always created.
	 *
	 * @param Table $table the table
	 * @return list<Index> the indexes
	 */
	protected function indexes(Table $table) : array {
		$indexes = array_values(array_filter($table->indexes,
			fn(Index $index) => !$index->optional || $this->supportsIndex($index, $table)));

		return array_values(array_filter($indexes, function(Index $index) use ($indexes) : bool {
			if ($index->unique || $index->explicit)
				return true;
			$key = self::indexKey($index);
			foreach ($indexes as $other)
				if ($other !== $index && count($other->columns) > count($index->columns)
						&& array_slice(self::indexKey($other), 0, count($key)) === $key)
					return false;
			return true;
		}));
	}

	/**
	 * Returns the parts of an index key, to compare indexes: the column
	 * names, with their prefix lengths, like 'status(10)'.
	 *
	 * @param Index $index the index
	 * @return list<string> the parts
	 */
	private static function indexKey(Index $index) : array {
		return array_map(fn(string $column) => isset($index->lengths[$column]) ? "{$column}({$index->lengths[$column]})" : $column, $index->columns);
	}

	/**
	 * Returns whether this database can create an optional index, like a
	 * unique index whose key may be too long.
	 *
	 * @param Index $index the index, which is optional
	 * @param Table $table the table of the index
	 */
	abstract protected function supportsIndex(Index $index, Table $table) : bool;

	/**
	 * Writes the SQL that (re)creates a table.
	 *
	 * @param Table $table the table
	 * @param list<Index> $indexes the indexes to create, see indexes()
	 * @return string the SQL
	 */
	abstract protected function createTable(Table $table, array $indexes) : string;

	/**
	 * Returns the SQL type of a column.
	 *
	 * @param Column $column the column
	 */
	abstract protected function columnType(Column $column) : string;

	/**
	 * Quotes a table, column or index name.
	 *
	 * @param string $name the name
	 */
	abstract protected function quote(string $name) : string;

	/**
	 * Writes a value as an SQL literal, for a column default.
	 *
	 * @param string|int|float|bool $value the value
	 * @param Column $column the column it's for
	 */
	abstract protected function literal(string|int|float|bool $value, Column $column) : string;
}
