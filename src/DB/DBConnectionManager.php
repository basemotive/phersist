<?php

namespace PHersist\DB;

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

	public static function newMySQLConnection(string $id, string $host, string $username, string $password, string $name, string $encoding='UTF8') : \PDO {
		$PDO = new \PDO (
			'mysql:host='.$host.';dbname='.$name.'',
			$username,
			$password,
			[
				//\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \''.$encoding.'\'',
				//\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET \''.$encoding.'\'',
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
			]
		);
		$PDO->exec("SET NAMES '{$encoding}'");
		$PDO->exec("SET CHARACTER SET '{$encoding}'");
		self::$connectionsPDO[$id] = $PDO;
		return $PDO;
	}

	public static function newSQLSrvLConnection(string $id, string $host, string $username, string $password, string $name, string $encoding='UTF8') : \PDO {
		$PDO = new MySQLtoMSSQLPDO (
			'sqlsrv:Server='.$host.';Database='.$name.';TrustServerCertificate=yes',
			$username,
			$password
		);
		self::$connectionsPDO[$id] = $PDO;
		return $PDO;
	}

	public static function newSQLiteConnection(string $id, string $filename) : \PDO {
		$PDO = new \PDO ("sqlite:{$filename}");
		self::$connectionsPDO[$id] = $PDO;
		return $PDO;
	}

	public static function getPDO(string $id) : ?\PDO {
		return isset(self::$connectionsPDO[$id]) ? self::$connectionsPDO[$id] : null;
	}

	private static $connectionsPDO = [];
}