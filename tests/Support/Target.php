<?php

namespace Tests\Support;

use PDO;
use PHersist\DB\DBConnectionManager;

/**
 * A database to run the tests on: a server, and the schema dialect PHersist
 * generates for it.
 *
 * The target is chosen with PHERSIST_TEST_TARGET (default: sqlite). Docker
 * targets are reached by their service name inside the compose network
 * (PHERSIST_TEST_IN_DOCKER=1, set by the runner container), and on their
 * published port on localhost otherwise. See tests/docker/.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Target {
	/** The password of the root user in the database containers */
	public const DOCKER_PASSWORD = 'phersist';

	/**
	 * The Docker targets: service in tests/docker/compose.yml, port published
	 * on localhost, dialect, database, and what the server version starts with.
	 *
	 * @var array<string, array{service: string, port: int, dialect: string, database: string, version: string}>
	 */
	private const DOCKER_TARGETS = [
		'mysql-8.0' => [ 'service' => 'mysql-8.0', 'port' => 33080, 'dialect' => 'mysql', 'database' => 'phersist', 'version' => '8.0.' ],
		'mysql-8.4' => [ 'service' => 'mysql-8.4', 'port' => 33084, 'dialect' => 'mysql', 'database' => 'phersist', 'version' => '8.4.' ],
		'mysql-9' => [ 'service' => 'mysql-9', 'port' => 33090, 'dialect' => 'mysql', 'database' => 'phersist', 'version' => '9.' ],
		'mariadb-10.6' => [ 'service' => 'mariadb-10.6', 'port' => 33106, 'dialect' => 'mariadb', 'database' => 'phersist', 'version' => '10.6.' ],
		'mariadb-10.11' => [ 'service' => 'mariadb-10.11', 'port' => 33111, 'dialect' => 'mariadb', 'database' => 'phersist', 'version' => '10.11.' ],
		'mariadb-11.4' => [ 'service' => 'mariadb-11.4', 'port' => 33114, 'dialect' => 'mariadb', 'database' => 'phersist', 'version' => '11.4.' ],
		'mariadb-11.8' => [ 'service' => 'mariadb-11.8', 'port' => 33118, 'dialect' => 'mariadb', 'database' => 'phersist', 'version' => '11.8.' ],
		// MariaDB 11.4.5+ has utf8mb4_0900_bin, so the MySQL schema should work too
		'mariadb-11.8-mysql' => [ 'service' => 'mariadb-11.8', 'port' => 33118, 'dialect' => 'mysql', 'database' => 'phersist_mysql', 'version' => '11.8.' ],
	];

	private static ?Target $current = null;

	private ?PDO $pdo = null;

	/**
	 * @param string $name the name used in PHERSIST_TEST_TARGET
	 * @param string $dialect the schema dialect: mysql, mariadb or sqlite
	 * @param ?string $expectedVersion what the server version must start with,
	 *   so a test run can't silently use another server than intended
	 * @param ?array{host: string, user: string, password: string, database: string, create: bool} $server
	 *   the MySQL/MariaDB server; null for SQLite
	 */
	private function __construct(
		public readonly string $name,
		public readonly string $dialect,
		public readonly ?string $expectedVersion,
		private readonly ?array $server,
	) {}

	/**
	 * The names of all targets.
	 *
	 * @return list<string>
	 */
	public static function names() : array {
		return [ 'sqlite', ...array_keys(self::DOCKER_TARGETS), 'local' ];
	}

	/**
	 * The target of this test run.
	 */
	public static function current() : Target {
		return self::$current ??= self::byName(getenv('PHERSIST_TEST_TARGET') ?: 'sqlite');
	}

	/**
	 * Whether this run uses ObjectCache (PHERSIST_TEST_CACHE=on).
	 */
	public static function cacheEnabled() : bool {
		return getenv('PHERSIST_TEST_CACHE') === 'on';
	}

	public static function byName(string $name) : Target {
		if ($name === 'sqlite')
			return new Target('sqlite', 'sqlite', null, null);

		if ($name === 'local') {
			// A server outside Docker, like the MariaDB on z820 (see AGENTS.local.md).
			// Every table in its database gets dropped, so it must be a throwaway one.
			$database = getenv('PHERSIST_TEST_LOCAL_DATABASE');
			$user = getenv('PHERSIST_TEST_LOCAL_USER');
			if (!$database || !$user)
				throw new \RuntimeException('The local target needs PHERSIST_TEST_LOCAL_USER and PHERSIST_TEST_LOCAL_DATABASE (and optionally _HOST, _PASSWORD, _DIALECT)');
			return new Target('local', getenv('PHERSIST_TEST_LOCAL_DIALECT') ?: 'mariadb', null, [
				'host' => getenv('PHERSIST_TEST_LOCAL_HOST') ?: 'localhost',
				'user' => $user,
				'password' => getenv('PHERSIST_TEST_LOCAL_PASSWORD') ?: '',
				'database' => $database,
				'create' => false,
			]);
		}

		$target = self::DOCKER_TARGETS[$name] ?? throw new \RuntimeException(
			"Unknown test target '{$name}'; known targets: ".implode(', ', self::names()));
		// DBConnectionManager::newMySQLConnection() has no port parameter, so the
		// port goes into the host part of the DSN
		$host = getenv('PHERSIST_TEST_IN_DOCKER') ? $target['service'] : "127.0.0.1;port={$target['port']}";
		return new Target($name, $target['dialect'], $target['version'], [
			'host' => $host,
			'user' => 'root',
			'password' => self::DOCKER_PASSWORD,
			'database' => $target['database'],
			'create' => true,
		]);
	}

	/**
	 * The test connection, registered under the given id the first time.
	 *
	 * The connection is made with DBConnectionManager, so its PDO options are
	 * part of what's tested. Its statements go through QueryLog.
	 */
	public function pdo(string $id = 'main') : PDO {
		if ($this->pdo !== null)
			return $this->pdo;

		if ($this->server === null) {
			// In the system's temp dir: the repository may be on a slow (network) disk
			$file = sys_get_temp_dir().'/phersist-tests-'.getmypid().'.sqlite';
			if (file_exists($file))
				unlink($file);
			register_shutdown_function(fn () => file_exists($file) && unlink($file));
			$this->pdo = DBConnectionManager::newSQLiteConnection($id, $file);
			QueryLog::install($this->pdo);
			return $this->pdo;
		}

		if ($this->server['create']) {
			$pdo = new PDO("mysql:host={$this->server['host']}", $this->server['user'], $this->server['password'],
				[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);
			$pdo->exec("create database if not exists `{$this->server['database']}` character set utf8mb4");
		}
		$this->pdo = DBConnectionManager::newMySQLConnection($id,
			$this->server['host'], $this->server['user'], $this->server['password'], $this->server['database']);
		QueryLog::install($this->pdo);
		return $this->pdo;
	}

	/**
	 * A connection to a second, empty database on the same server, registered
	 * under the given id, for classes with their own database. Null for the
	 * local target, which has only the one database.
	 */
	public function otherPdo(string $id) : ?PDO {
		if ($this->server === null) {
			$file = sys_get_temp_dir().'/phersist-tests-'.getmypid().'-'.$id.'.sqlite';
			if (file_exists($file))
				unlink($file);
			register_shutdown_function(fn () => file_exists($file) && unlink($file));
			return DBConnectionManager::newSQLiteConnection($id, $file);
		}
		if (!$this->server['create'])
			return null;

		$database = "{$this->server['database']}_{$id}";
		$pdo = new PDO("mysql:host={$this->server['host']}", $this->server['user'], $this->server['password'],
			[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);
		$pdo->exec("drop database if exists `{$database}`");
		$pdo->exec("create database `{$database}` character set utf8mb4");
		return DBConnectionManager::newMySQLConnection($id, $this->server['host'], $this->server['user'], $this->server['password'], $database);
	}

	/**
	 * The version the server reports.
	 */
	public function serverVersion() : string {
		return (string)$this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
	}

	/**
	 * Where generated classes and schemas go, kept after
	 * the run for inspection.
	 */
	public static function generatedDir() : string {
		return dirname(__DIR__).'/.generated';
	}
}
