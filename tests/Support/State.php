<?php

namespace Tests\Support;

use PDO;
use PHersist\Generator\Schema\Column;
use PHersist\Generator\Schema\ColumnType;
use PHersist\Generator\Schema\Table;

/**
 * The before-state and expected after-state of the runtime tests: the
 * contents of the tables of the loaded models. See tests/README.md.
 *
 * - given() empties every table, inserts the given rows with plain SQL, and
 *   remembers the result as the before-state.
 * - expectState() checks the listed tables exactly; a column left out of an
 *   expected row must have its before-state value; tables that aren't listed
 *   must be unchanged.
 * - expectUnchanged() checks that no table changed.
 *
 * Values are normalized by column type before comparing, since every database
 * driver returns them differently.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class State {
	/** @var array<string, list<array<string, mixed>>> the before-state, normalized and sorted, by table */
	private static array $before = [];

	private static int $testStart = 0;

	/**
	 * Empties all tables of the loaded models; the before-state is then empty.
	 * Runs before every runtime test.
	 */
	public static function reset() : void {
		self::$testStart = time();
		$database = Database::current();
		$pdo = Target::current()->pdo();
		foreach (Models::tables() as $name => $table) {
			$quoted = $database->quote($name);
			if (Target::current()->dialect === 'sqlite') {
				$pdo->exec("delete from {$quoted}");
				if ($pdo->query("select 1 from sqlite_master where name = 'sqlite_sequence'")->fetchColumn())
					$pdo->prepare('delete from sqlite_sequence where name = ?')->execute([ $name ]);
			} else
				$pdo->exec("truncate table {$quoted}");
		}
		self::$before = array_map(fn () => [], Models::tables());
	}

	/**
	 * When the current test started, as a Unix timestamp.
	 */
	public static function testStart() : int {
		return self::$testStart;
	}

	/**
	 * Sets up the before-state: empties every table, then inserts the given
	 * rows. Columns left out of a row get their column default.
	 *
	 * @param array<string, list<array<string, mixed>>> $tables rows by table name
	 */
	public static function given(array $tables) : void {
		self::reset();
		$database = Database::current();
		$pdo = Target::current()->pdo();
		foreach ($tables as $name => $rows) {
			$table = Models::table($name);
			foreach ($rows as $row) {
				self::checkColumns($table, $row);
				$columns = array_keys($row);
				$sql = 'insert into '.$database->quote($name)
					.' ('.implode(', ', array_map([ $database, 'quote' ], $columns)).')'
					.' values ('.implode(', ', array_fill(0, count($columns), '?')).')';
				$pdo->prepare($sql)->execute(array_map(fn ($value) => self::toSQL($value), array_values($row)));
			}
		}
		self::$before = self::read();
	}

	/**
	 * Checks the listed tables exactly, and that the other tables are unchanged.
	 *
	 * @param array<string, list<array<string, mixed>>> $tables expected rows by table name
	 */
	public static function expect(array $tables) : void {
		if ($tables === [])
			throw new \LogicException("expectState([]) is ambiguous: use expectUnchanged() for 'nothing changed', or list a table with no rows ('table' => []) for an empty table");

		foreach (array_keys($tables) as $name)
			Models::table($name); // throws for unknown tables

		$actual = self::read();
		foreach ($actual as $name => $rows) {
			if (array_key_exists($name, $tables))
				self::expectTable(Models::table($name), $tables[$name], $rows);
			else
				expect($rows)->toBe(self::$before[$name], "Table '{$name}' should be unchanged");
		}
	}

	/**
	 * Checks that no table changed.
	 */
	public static function expectUnchanged() : void {
		foreach (self::read() as $name => $rows)
			expect($rows)->toBe(self::$before[$name], "Table '{$name}' should be unchanged");
	}

	/**
	 * Checks the rows of one table.
	 *
	 * @param list<array<string, mixed>> $expectedRows as given in the test
	 * @param list<array<string, mixed>> $actualRows normalized and sorted
	 */
	private static function expectTable(Table $table, array $expectedRows, array $actualRows) : void {
		$key = $table->primaryKey;
		$beforeByKey = [];
		$actualByKey = [];
		if ($key !== null) {
			foreach (self::$before[$table->name] as $row)
				$beforeByKey[$row[$key]] = $row;
			foreach ($actualRows as $row)
				$actualByKey[$row[$key]] = $row;
		}

		$expected = [];
		$projected = [];
		foreach ($expectedRows as $row) {
			self::checkColumns($table, $row);
			if ($key !== null && !isset($row[$key]))
				throw new \LogicException("Expected rows of table '{$table->name}' need their '{$key}'");

			if ($key === null) {
				$missing = array_diff(array_map(fn (Column $column) => $column->name, $table->columns), array_keys($row));
				if ($missing)
					throw new \LogicException("Expected rows of table '{$table->name}' (which has no primary key) need every column; missing: ".implode(', ', $missing));
				foreach ($row as $value)
					if ($value instanceof Matcher)
						throw new \LogicException("Expected rows of table '{$table->name}' (which has no primary key) can't use matchers");
			}

			$normalized = [];
			foreach ($table->columns as $column) {
				if (array_key_exists($column->name, $row))
					$normalized[$column->name] = $row[$column->name] instanceof Matcher
						? $row[$column->name]
						: self::normalize($row[$column->name], $column);
				elseif (isset($beforeByKey[self::normalize($row[$key], $table->column($key))]))
					// A left-out column keeps its value
					$normalized[$column->name] = $beforeByKey[self::normalize($row[$key], $table->column($key))][$column->name];
			}
			$expected[] = $normalized;
		}
		$expected = self::sort($table, $expected);

		// Resolve matchers against the actual row with the same key, and only
		// compare the columns the expected rows have (new rows may leave some out)
		foreach ($expected as $i => $row) {
			$actualRow = $key !== null ? ($actualByKey[$row[$key]] ?? null) : null;
			foreach ($row as $name => $value)
				if ($value instanceof Matcher)
					$expected[$i][$name] = ($actualRow !== null && $value->matches($actualRow[$name], $table->column($name)->type))
						? $actualRow[$name]
						: "<{$value->description}>";
		}
		foreach ($actualRows as $row) {
			$expectedRow = null;
			foreach ($expected as $candidate)
				if ($key !== null && $candidate[$key] === $row[$key])
					$expectedRow = $candidate;
			$projected[] = $expectedRow !== null ? array_intersect_key($row, $expectedRow) : $row;
		}

		expect($projected)->toBe($expected, "Table '{$table->name}' should have the expected rows");
	}

	/**
	 * Reads all tables of the loaded models.
	 *
	 * @return array<string, list<array<string, mixed>>> normalized and sorted rows, by table
	 */
	private static function read() : array {
		$database = Database::current();
		$pdo = Target::current()->pdo();
		$state = [];
		foreach (Models::tables() as $name => $table) {
			$rows = [];
			foreach ($pdo->query('select * from '.$database->quote($name))->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$normalized = [];
				foreach ($table->columns as $column)
					$normalized[$column->name] = self::normalize($row[$column->name], $column);
				$rows[] = $normalized;
			}
			$state[$name] = self::sort($table, $rows);
		}
		return $state;
	}

	/**
	 * Sorts rows by primary key, or by all columns for tables without one.
	 *
	 * @param list<array<string, mixed>> $rows normalized rows
	 * @return list<array<string, mixed>>
	 */
	private static function sort(Table $table, array $rows) : array {
		$key = $table->primaryKey;
		usort($rows, $key !== null
			? fn ($a, $b) => $a[$key] <=> $b[$key]
			: fn ($a, $b) => array_map(fn ($v) => $v === null ? [ 0, '' ] : [ 1, (string)$v ], array_values($a))
				<=> array_map(fn ($v) => $v === null ? [ 0, '' ] : [ 1, (string)$v ], array_values($b)));
		return $rows;
	}

	/**
	 * Normalizes a value by column type, so values from every database (and
	 * from the tests) compare equal when they mean the same.
	 */
	public static function normalize(mixed $value, Column $column) : mixed {
		if ($value === null)
			return null;
		if ($value instanceof \DateTimeInterface)
			return $value->format($column->type === ColumnType::Date ? 'Y-m-d' : 'Y-m-d H:i:s');

		switch ($column->type) {
			case ColumnType::Id:
			case ColumnType::Reference:
			case ColumnType::Int:
			case ColumnType::UnsignedInt:
			case ColumnType::Bool:
				if (is_bool($value))
					return (int)$value;
				if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)))
					return (int)$value;
				return $value; // left alone, so the difference shows

			case ColumnType::Float:
				return is_numeric($value) ? (float)sprintf('%.12g', (float)$value) : $value;

			case ColumnType::Decimal:
				return self::normalizeDecimal($value, $column->scale ?? 0);

			default:
				return is_bool($value) || is_int($value) || is_float($value) ? (string)$value : $value;
		}
	}

	/**
	 * A decimal as a string with exactly $scale decimals, without going
	 * through a float for strings.
	 */
	private static function normalizeDecimal(mixed $value, int $scale) : mixed {
		if (is_int($value) || is_float($value))
			return sprintf("%.{$scale}F", $value);
		if (!is_string($value) || !preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $value, $m))
			return $value;
		$integer = ltrim($m[2], '0') ?: '0';
		$fraction = substr(str_pad($m[3] ?? '', $scale, '0'), 0, $scale);
		$sign = ($m[1] === '-' && trim($integer.$fraction, '0') !== '') ? '-' : '';
		return $sign.$integer.($scale > 0 ? ".{$fraction}" : '');
	}

	/**
	 * Converts a value from a test to a value for an insert.
	 */
	private static function toSQL(mixed $value) : mixed {
		if (is_bool($value))
			return (int)$value;
		if ($value instanceof \DateTimeInterface)
			return $value->format('Y-m-d H:i:s');
		if ($value instanceof Matcher)
			throw new \LogicException('Matchers can only be used in expected states');
		return $value;
	}

	/**
	 * Throws for columns the table doesn't have, to catch typos in tests.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function checkColumns(Table $table, array $row) : void {
		foreach (array_keys($row) as $name)
			if ($table->column($name) === null)
				throw new \InvalidArgumentException("Table '{$table->name}' has no column '{$name}'; it has: "
					.implode(', ', array_map(fn (Column $column) => $column->name, $table->columns)));
	}
}
