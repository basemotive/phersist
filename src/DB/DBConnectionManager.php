<?php

namespace PHersist\DB;

use PDO;

/**
 * Manages the database connections.
 *
 * This class has only static methods and members, which basically makes it a singleton.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class DBConnectionManager {

	/**
	 * Connects to a MySQL or MariaDB instance.
	 *
	 * @param string $id the identifier used in the database property on the
	 *   project node in your XML
	 * @param string $host the hostname to connect to
	 * @param string $username the username to connect as
	 * @param string $password the password for the username
	 * @param string $charset the charset to use for the connection; utf8mb4 is
	 *   recommended
	 * @return PDO the database connection
	 */
	public static function newMySQLConnection(string $id, string $host, string $username, string $password, string $name, string $charset = 'utf8mb4') : PDO {
		$PDO = new PDO (
			"mysql:host={$host};dbname={$name};charset={$charset}",
			$username,
			$password,
			[
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]
		);
		self::$connectionsPDO[$id] = $PDO;
		return $PDO;
	}

	/**
	 * The SQL Server (MSSQL) support is VERY experimental. Don't use it!
	 *
	 * @param string $id the identifier used in the database property on the
	 *   project node in your XML
	 * @param string $host the hostname to connect to
	 * @param string $username the username to connect as
	 * @param string $password the password for the username
	 * @return PDO the database connection
	 */
	public static function newSQLSrvLConnection(string $id, string $host, string $username, string $password, string $name) : PDO {
		$PDO = new MySQLtoMSSQLPDO (
			"sqlsrv:Server={$host};Database={$name};TrustServerCertificate=yes",
			$username,
			$password
		);
		self::$connectionsPDO[$id] = $PDO;
		return $PDO;
	}

	/**
	 * Opens an SQLite file-based database.
	 *
	 * @param string $id the identifier used in the database property on the
	 *   project node in your XML
	 * @param string $filename the name of the file that contains the database
	 * @return PDO the database connection
	 */
	public static function newSQLiteConnection(string $id, string $filename) : PDO {
		$PDO = new PDO ("sqlite:{$filename}");
		self::$connectionsPDO[$id] = $PDO;
		return $PDO;
	}

	/**
	 * Retrieves an existing database connection
	 *
	 * @param string $id the identifier used in the database property on the
	 *   project node in your XML
	 * @return PDO the database connection, or null if it doesn't exit
	 */
	public static function getPDO(string $id) : ?PDO {
		return self::$connectionsPDO[$id] ?? null;
	}

	/** @var array the registered database connections */
	private static array $connectionsPDO = [];
}