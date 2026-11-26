<?php

namespace PHersist\Generator\Schema;

/**
 * A foreign key of a table in the schema: a column that holds the id of a row
 * in another table (or its own), and what the database does with the row
 * when that one is deleted.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class ForeignKey {
	/** The row's column is set to NULL when the referenced row is deleted */
	public const SET_NULL = 'SET NULL';
	/** The referenced row can't be deleted while the row refers to it */
	public const RESTRICT = 'RESTRICT';
	/** The row is deleted along with the referenced row */
	public const CASCADE = 'CASCADE';

	/**
	 * The maximum length of a constraint name, which is that of MySQL and MariaDB.
	 */
	public const MAX_NAME_LENGTH = 64;

	/**
	 * @param string $name the constraint name, unique in the schema; see name()
	 * @param string $column the column that holds the id
	 * @param string $referencedTable the table it refers to
	 * @param string $referencedColumn the id column of that table
	 * @param self::SET_NULL|self::RESTRICT|self::CASCADE $onDelete what happens
	 *   to the row when the referenced row is deleted
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $column,
		public readonly string $referencedTable,
		public readonly string $referencedColumn,
		public readonly string $onDelete,
	) {}

	/**
	 * Returns the name of the foreign key on a column: fk_<table>_<column>.
	 *
	 * A name of more than 64 characters is cut off after 55, followed by an
	 * underscore and a hash: the first 8 hex digits of the MD5 of
	 * '<table>.<column>'. So the name only depends on the table and the column,
	 * and a project can work it out for its migrations.
	 *
	 * @param string $table the table that has the key
	 * @param string $column the column that holds the id
	 * @param bool $hashed whether the name gets the hash even if it's short
	 *   enough, for names that would be the same otherwise (like those of
	 *   table 'a_b', column 'c' and table 'a', column 'b_c')
	 */
	public static function name(string $table, string $column, bool $hashed = false) : string {
		$name = "fk_{$table}_{$column}";
		if (!$hashed && strlen($name) <= self::MAX_NAME_LENGTH)
			return $name;
		return substr($name, 0, self::MAX_NAME_LENGTH - 9).'_'.substr(md5("{$table}.{$column}"), 0, 8);
	}

	/**
	 * Returns whether this key refers to the same rows as another one, with
	 * the same rule.
	 *
	 * @param ForeignKey $other the other key
	 */
	public function equals(ForeignKey $other) : bool {
		return $this->column === $other->column && $this->referencedTable === $other->referencedTable
			&& $this->referencedColumn === $other->referencedColumn && $this->onDelete === $other->onDelete;
	}
}
