<?php

namespace PHersist\Generator;

use PHersist\Generator\Model\Project;
use PHersist\Generator\Schema\Column;
use PHersist\Generator\Schema\ColumnType;
use PHersist\Generator\Schema\Index;
use PHersist\Generator\Schema\Table;

/**
 * Generates the schema for MySQL 8.0 and newer.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class MySQLSchemaGenerator extends SchemaGenerator {
	/**
	 * The collation of map key and value columns: binary, so case-sensitive,
	 * and NO PAD, so trailing spaces count. Requires MySQL 8.0.
	 */
	protected const MAP_COLLATION = 'utf8mb4_0900_bin';

	/**
	 * The maximum size of an InnoDB index key, in bytes.
	 */
	protected const MAX_INDEX_KEY_BYTES = 3072;

	/**
	 * The dialect in <schema dialect="..."> that has the settings for this generator.
	 */
	protected const DIALECT = 'mysql';

	/**
	 * @param Project $project the model to generate the schema for
	 */
	public function __construct(Project $project) {
		parent::__construct($project);

		// XMLLoader has checked the values
		$settings = $project->schemaSettings(static::DIALECT);
		if (isset($settings['charset'])) {
			$this->charset = $settings['charset'];
			// Without an explicit collation, let MySQL use the charset's default
			$this->collate = null;
		}
		if (isset($settings['collate']))
			$this->collate = $settings['collate'];
	}

	protected function supportsIndex(Index $index, Table $table) : bool {
		$bytes = 0;
		foreach ($index->columns as $name) {
			$column = $table->column($name);
			$bytes += match ($column?->type) {
				// VARCHAR(191) at 4 bytes per character
				ColumnType::ClassName, ColumnType::MapKey => 764,
				ColumnType::Id, ColumnType::Reference, ColumnType::Int, ColumnType::UnsignedInt => 4,
				ColumnType::Bool => 1,
				default => self::MAX_INDEX_KEY_BYTES + 1,
			};
		}
		return $bytes <= self::MAX_INDEX_KEY_BYTES;
	}

	protected function createTable(Table $table, array $indexes) : string {
		$result = "DROP TABLE IF EXISTS {$this->quote($table->name)};\n";
		$result .= "CREATE TABLE {$this->quote($table->name)} (\n";
		foreach ($table->columns as $column) {
			$result .= "\t{$this->quote($column->name)} {$this->columnType($column)}";
			$result .= $column->nullable ? ' NULL' : ' NOT NULL';
			if ($column->autoIncrement)
				$result .= ' AUTO_INCREMENT';
			if ($column->default !== null)
				$result .= ' DEFAULT '.$this->literal($column->default, $column);
			$result .= ",\n";

			if ($column->name === $table->primaryKey)
				$result .= "\n";
		}

		// Plain indexes first, then the unique ones
		usort($indexes, fn(Index $a, Index $b) => $a->unique <=> $b->unique);
		foreach ($indexes as $index)
			$result .= "\t".($index->unique ? 'UNIQUE INDEX' : 'INDEX')." {$this->quote($index->name)} ("
				.implode(', ', array_map([ $this, 'quote' ], $index->columns))."),\n";

		if ($table->primaryKey !== null)
			$result .= "\n\tPRIMARY KEY ({$this->quote($table->primaryKey)})\n";
		else
			$result = rtrim($result, ",\n")."\n";

		$result .= ") ENGINE=InnoDB\n";
		$result .= "  DEFAULT CHARSET={$this->charset}";
		if ($this->collate !== null)
			$result .= "\n  COLLATE={$this->collate}";
		$result .= ";\n";
		$result .= "\n";

		return $result;
	}

	protected function columnType(Column $column) : string {
		return match ($column->type) {
			ColumnType::Id, ColumnType::Reference, ColumnType::UnsignedInt => 'INT UNSIGNED',
			ColumnType::Int => 'INT',
			ColumnType::Bool => 'TINYINT UNSIGNED',
			// DOUBLE matches the precision of PHP floats
			ColumnType::Float => 'DOUBLE',
			ColumnType::Decimal => "DECIMAL({$column->precision},{$column->scale})",
			ColumnType::Text => 'TEXT',
			ColumnType::Date => 'DATE',
			ColumnType::DateTime => 'DATETIME',
			// The class name is indexed, which MySQL doesn't allow on a TEXT
			// column without a key length
			ColumnType::ClassName => 'VARCHAR(191)',
			// Keys use a binary NO PAD collation, so that they are as distinct in
			// the database as they are in a PHP array ('Theme' is not 'theme',
			// 'a' is not 'a '). Map keys and values are always UTF-8, whatever
			// the charset of the table.
			ColumnType::MapKey => 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE '.static::MAP_COLLATION,
			ColumnType::MapValue => 'TEXT CHARACTER SET utf8mb4 COLLATE '.static::MAP_COLLATION,
		};
	}

	protected function quote(string $name) : string {
		return "`{$name}`";
	}

	protected function literal(string|int|float|bool $value, Column $column) : string {
		// A Decimal default is a validated number, so it's safe as a numeric literal
		if ($column->type == ColumnType::Decimal)
			return (string)$value;
		if (is_string($value))
			return "('".str_replace(['\\', "'"], ['\\\\', "''"], $value)."')";
		if (is_bool($value))
			return $value ? '1' : '0';
		if (is_float($value))
			return var_export($value, true);
		return (string)$value;
	}

	private string $charset = 'utf8mb4';
	private ?string $collate = 'utf8mb4_unicode_ci';
}
