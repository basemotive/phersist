<?php

namespace Tests\Support;

use PHersist\ActiveRecord;
use PHersist\ObjectCache;
use PHersist\ReferenceRegistry;
use PHersist\Transaction;

/**
 * PHersist's static state, reset and checked through reflection, so src/
 * doesn't need anything just for the tests.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Internals {
	/**
	 * Resets the state that's meant to last for a request: the ObjectCache
	 * (emptied, and enabled per PHERSIST_TEST_CACHE) and the namespaces
	 * registered with ReferenceRegistry.
	 */
	public static function reset() : void {
		self::set(ObjectCache::class, 'cache', []);
		ObjectCache::setEnabled(Target::cacheEnabled());
		self::set(ReferenceRegistry::class, 'namespaces', []);
		self::set(ReferenceRegistry::class, 'references', []);
	}

	/**
	 * Describes how PHersist isn't at rest after a test: an open transaction,
	 * a flag that wasn't reset, a changed error mode. Then puts it at rest, so
	 * the next tests aren't affected.
	 *
	 * @return list<string> the problems; empty when at rest
	 */
	public static function problemsAtRest(\PDO $pdo) : array {
		$problems = [];

		$stacks = self::get(Transaction::class, '_stacks');
		$stack = $stacks[$pdo] ?? [];
		if ($stack !== []) {
			$problems[] = count($stack).' PHersist transaction(s) still open';
			unset($stacks[$pdo]);
		}
		if ($pdo->inTransaction()) {
			$problems[] = 'a database transaction is still open';
			$pdo->rollBack();
		}
		if (self::get(ActiveRecord::class, '_fetching') !== false) {
			$problems[] = 'ActiveRecord::$_fetching is still set';
			self::set(ActiveRecord::class, '_fetching', false);
		}
		if (self::get(ActiveRecord::class, '_deleting') !== []) {
			$problems[] = 'ActiveRecord::$_deleting still has '.implode(', ', array_keys(self::get(ActiveRecord::class, '_deleting')));
			self::set(ActiveRecord::class, '_deleting', []);
		}
		if ($pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
			$problems[] = 'the PDO error mode was changed';
			$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		}

		return $problems;
	}

	/**
	 * Reads a static property, private or not.
	 */
	public static function get(string $class, string $property) : mixed {
		return (new \ReflectionProperty($class, $property))->getValue();
	}

	/**
	 * Sets a static property, private or not.
	 */
	public static function set(string $class, string $property, mixed $value) : void {
		(new \ReflectionProperty($class, $property))->setValue(null, $value);
	}
}
