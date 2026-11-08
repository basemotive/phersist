<?php

namespace Tests\Support;

/**
 * Records the SQL statements PHersist runs, through PDO::ATTR_STATEMENT_CLASS
 * on the test connection. PHersist uses prepare() for everything except
 * savepoints (exec()), so those aren't recorded.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class QueryLog {
	/** @var ?list<string> the statements recorded so far, or null when not recording */
	private static ?array $log = null;

	public static function install(\PDO $pdo) : void {
		$pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ LoggingStatement::class ]);
	}

	/**
	 * Runs a function and returns the statements it executed.
	 *
	 * @return list<string> the SQL of each executed statement, with whitespace collapsed
	 */
	public static function record(callable $fn) : array {
		if (self::$log !== null)
			throw new \LogicException('queries() calls can\'t be nested');
		self::$log = [];
		try {
			$fn();
			return self::$log;
		} finally {
			self::$log = null;
		}
	}

	public static function add(string $sql) : void {
		if (self::$log !== null)
			self::$log[] = trim((string)preg_replace('/\s+/', ' ', $sql));
	}
}
