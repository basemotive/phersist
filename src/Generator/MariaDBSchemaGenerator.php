<?php

namespace PHersist\Generator;

/**
 * Generates the schema for MariaDB 10.2 and newer.
 *
 * The SQL is that of MySQL, except for the collation of map keys and values,
 * since MariaDB doesn't have MySQL's utf8mb4_0900_bin (before 11.4.5).
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class MariaDBSchemaGenerator extends MySQLSchemaGenerator {
	/**
	 * Binary, so case-sensitive, and NO PAD, so trailing spaces count.
	 */
	protected const MAP_COLLATION = 'utf8mb4_nopad_bin';

	protected const DIALECT = 'mariadb';
}
