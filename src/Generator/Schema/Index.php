<?php

namespace PHersist\Generator\Schema;

/**
 * An index of a table in the schema.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Index {
	/**
	 * @param string $name the index name, unique in its table
	 * @param non-empty-list<string> $columns the column names
	 * @param bool $unique whether the combination of values must be unique
	 * @param bool $optional whether it can be left out when the database can't
	 *   create it, like a unique index whose key is too long; PHersist doesn't
	 *   need it to work
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $columns,
		public readonly bool $unique = false,
		public readonly bool $optional = false,
	) {}
}
