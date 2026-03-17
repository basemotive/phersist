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
    public function __construct($dsn, $username = null, $password = null, $options = []) {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function prepare($query, $options = []) : \PDOStatement|false {
        $query = $this->convertMySQLToMSSQL($query);
        return parent::prepare($query, $options);
    }

    public function query($query, ...$args) : \PDOStatement|false {
        $query = $this->convertMySQLToMSSQL($query);
        return parent::query($query, ...$args);
    }

    public function exec($query) : int|false {
        $query = $this->convertMySQLToMSSQL($query);
        return parent::exec($query);
    }

    private function convertMySQLToMSSQL(string $query) : string {
        // --- replace MySQL backticks (`col`) with MSSQL brackets ([col]) ---
        $query = preg_replace('/`([^`]*)`/', '[$1]', $query);

        // --- convert LIMIT syntax to OFFSET/FETCH ---
        $limitPattern = '/\s+LIMIT\s+(\d+)(\s*,\s*(\d+))?(\s+OFFSET\s+(\d+))?/i';
        if (preg_match($limitPattern, $query, $matches)) {
            $offset = 0;
            $count = 0;

            if (!empty($matches[5])) {
                // LIMIT x OFFSET y
                $count = (int)$matches[1];
                $offset = (int)$matches[5];
            } elseif (!empty($matches[3])) {
                // LIMIT offset, count
                $offset = (int)$matches[1];
                $count = (int)$matches[3];
            } else {
                // LIMIT count
                $count = (int)$matches[1];
            }

            // Ensure ORDER BY exists (required for OFFSET/FETCH)
            if (!preg_match('/ORDER\s+BY/i', $query)) {
                $query .= " ORDER BY (SELECT NULL)";
            }

            // Replace LIMIT with OFFSET/FETCH
            $replacement = " OFFSET $offset ROWS";
            if ($count > 0) {
                $replacement .= " FETCH NEXT $count ROWS ONLY";
            }

            $query = preg_replace($limitPattern, $replacement, $query);
        }

        return trim($query);
    }
}
