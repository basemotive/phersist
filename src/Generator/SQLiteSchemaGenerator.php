<?php

namespace PHersist\Generator;

use PHersist\Generator\Schema\Column;
use PHersist\Generator\Schema\ColumnType;
use PHersist\Generator\Schema\Index;
use PHersist\Generator\Schema\Table;

/**
 * Generates the schema for SQLite 3.
 *
 * SQLite stores dates and times as text, in the formats PHersist writes, and
 * decimals as numbers, which are exact up to about 15 significant digits. Its
 * default collation compares text exactly, as map keys need.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class SQLiteSchemaGenerator extends SchemaGenerator {
	protected function supportsIndex(Index $index, Table $table) : bool {
		return true;
	}

	protected function createTable(Table $table, array $indexes) : string {
		$columns = [];
		foreach ($table->columns as $column) {
			$definition = "\t{$this->quote($column->name)} {$this->columnType($column)} ";
			$definition .= $column->nullable ? 'NULL' : 'NOT NULL';
			if ($column->name === $table->primaryKey)
				$definition .= ' PRIMARY KEY';
			// Without AUTOINCREMENT, SQLite may reuse the ids of deleted rows
			if ($column->autoIncrement)
				$definition .= ' AUTOINCREMENT';
			if ($column->default !== null)
				$definition .= ' DEFAULT '.$this->literal($column->default, $column);
			$columns[] = $definition;
		}

		$result = "DROP TABLE IF EXISTS {$this->quote($table->name)};\n";
		$result .= "CREATE TABLE {$this->quote($table->name)} (\n".implode(",\n", $columns)."\n);\n";

		// Index names are per database in SQLite, so they get the table name
		foreach ($indexes as $index)
			$result .= 'CREATE '.($index->unique ? 'UNIQUE ' : '')."INDEX {$this->quote("{$table->name}_{$index->name}")}"
				." ON {$this->quote($table->name)} (".implode(', ', array_map([ $this, 'quote' ], $index->columns)).");\n";

		return $result."\n";
	}

	protected function columnType(Column $column) : string {
		return match ($column->type) {
			// INTEGER PRIMARY KEY makes the id the rowid, which AUTOINCREMENT needs
			ColumnType::Id, ColumnType::Reference, ColumnType::Int, ColumnType::UnsignedInt, ColumnType::Bool => 'INTEGER',
			ColumnType::Float => 'REAL',
			// Has numeric affinity
			ColumnType::Decimal => "DECIMAL({$column->precision},{$column->scale})",
			ColumnType::Text, ColumnType::Date, ColumnType::DateTime,
				ColumnType::ClassName, ColumnType::MapKey, ColumnType::MapValue => 'TEXT',
		};
	}

	protected function quote(string $name) : string {
		return '"'.str_replace('"', '""', $name).'"';
	}

	protected function literal(string|int|float|bool $value, Column $column) : string {
		// A Decimal default is a validated number, so it's safe as a numeric literal
		if ($column->type == ColumnType::Decimal)
			return (string)$value;
		if (is_string($value))
			return "'".str_replace("'", "''", $value)."'";
		if (is_bool($value))
			return $value ? '1' : '0';
		if (is_float($value))
			return var_export($value, true);
		return (string)$value;
	}
}
