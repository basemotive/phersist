<?php

namespace PHersist\DB;

/**
 * Converts MySQL queries to MSSQL queries.
 *
 * This conversion is not perfect, but it should work on most simple queries
 * produced by the PHersist ORM.
 * Basically this was built to do quick-and-dirty conversion, and should not
 * be used as a long-term solution. Ideally, PHersist should build custom
 * queries for each backend.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class MySQLtoMSSQLPDO extends \PDO {
	/** @param array<int, mixed> $options */
	public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = []) {
		parent::__construct($dsn, $username, $password, $options);
	}

	/** @param array<int, mixed> $options */
	public function prepare($query, array $options = []) : \PDOStatement|false {
		return parent::prepare($this->convertMySQLToMSSQL($query), $options);
	}

	public function query(string $query, mixed ...$args) : \PDOStatement|false {
		$query = $this->convertMySQLToMSSQL($query);
		return parent::query($query, ...$args);
    }

    public function exec($query) : int|false {
        $query = $this->convertMySQLToMSSQL($query);
        return parent::exec($query);
    }

    private function convertMySQLToMSSQL(string $query) : string {
        // --- mask string literals, so the conversions below don't touch them ---
        $literals = [];
        $query = preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s',
            function (array $m) use (&$literals) : string {
                $literals[] = $m[0];
                return "\0" . (count($literals) - 1) . "\0";
            },
            $query
        );

        // --- replace MySQL backticks (`col`) with MSSQL brackets ([col]) ---
        $query = preg_replace('/`([^`]*)`/', '[$1]', $query);

        // --- convert a trailing LIMIT clause to OFFSET/FETCH ---
        $limitPattern = '/\s+LIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+(\d+))?\s*;?\s*$/i';
        if (preg_match($limitPattern, $query, $matches, PREG_UNMATCHED_AS_NULL)) {
            if ($matches[3] !== null) {
                // LIMIT count OFFSET offset
                $count = (int)$matches[1];
                $offset = (int)$matches[3];
            } elseif ($matches[2] !== null) {
                // LIMIT offset, count
                $offset = (int)$matches[1];
                $count = (int)$matches[2];
            } else {
                // LIMIT count
                $offset = 0;
                $count = (int)$matches[1];
            }

            $query = substr($query, 0, -strlen($matches[0]));

            if ($count == 0) {
                // FETCH NEXT 0 ROWS is invalid, so use TOP 0 instead
                $query = preg_replace('/^(\s*SELECT(?:\s+DISTINCT)?)\s/i', '$1 TOP 0 ', $query, 1);
            } else {
                // OFFSET/FETCH requires an ORDER BY, which must come before it
                if (!preg_match('/\bORDER\s+BY\b/i', $query))
                    $query .= " ORDER BY (SELECT NULL)";
                $query .= " OFFSET $offset ROWS FETCH NEXT $count ROWS ONLY";
            }
        }

        // --- restore the string literals ---
        $query = preg_replace_callback(
            '/\0(\d+)\0/',
            fn(array $m) : string => $literals[(int)$m[1]],
            $query
        );

        return trim($query);
    }
}
