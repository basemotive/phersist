<?php

namespace Tests\Support;

use PDO;

/**
 * Plain database operations on the test connection, outside PHersist: what
 * the tests need to set up and inspect the database.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Database {
	public function __construct(private readonly PDO $pdo, private readonly string $dialect) {}

	public static function current() : Database {
		return new Database(Target::current()->pdo(), Target::current()->dialect);
	}

	/**
	 * The names of the tables in the database, sorted.
	 *
	 * @return list<string>
	 */
	public function tableNames() : array {
		$names = $this->dialect === 'sqlite'
			? $this->pdo->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN)
			: $this->pdo->query("show full tables where Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
		sort($names);
		return $names;
	}

	/**
	 * The column names of a table, in table order.
	 *
	 * @return list<string>
	 */
	public function columnNames(string $table) : array {
		if ($this->dialect === 'sqlite')
			return array_column($this->pdo->query('pragma table_info('.$this->quote($table).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
		$stmt = $this->pdo->prepare('select column_name from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position');
		$stmt->execute([ $table ]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Drops every table in the database. Only for test databases!
	 */
	public function dropAllTables() : void {
		foreach ($this->tableNames() as $table)
			$this->pdo->exec('drop table '.$this->quote($table));
	}

	/**
	 * Runs a generated schema, statement by statement, so an error in any of
	 * them is reported (a multi-statement exec() can hide those on MySQL).
	 *
	 * Statements are split on a ';' at the end of a line, which the generated
	 * schemas never have inside a statement.
	 */
	public function loadSchema(string $sql) : void {
		foreach (preg_split('/;[ \t]*(\r?\n|$)/', $sql) ?: [] as $statement) {
			if (trim($statement) === '')
				continue;
			try {
				$this->pdo->exec($statement);
			} catch (\PDOException $e) {
				throw new \RuntimeException("Schema statement failed: {$e->getMessage()}\n{$statement}", 0, $e);
			}
		}
	}

	/**
	 * Quotes a table or column name.
	 */
	public function quote(string $name) : string {
		return $this->dialect === 'sqlite' ? '"'.str_replace('"', '""', $name).'"' : '`'.str_replace('`', '``', $name).'`';
	}
}
