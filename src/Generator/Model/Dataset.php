<?php

namespace PHersist\Generator\Model;

/**
 * A <dataset> of a class: properties that are loaded together, from one table.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Dataset {
	/**
	 * @param ?string $name the name, used in column aliases; null if not set
	 * @param string $table the table, the class's base table by default
	 * @param bool $autoload whether ObjectFinder loads it by default
	 * @param list<Property> $properties
	 * @param int $line the line of the element in the XML
	 */
	public function __construct(
		public readonly ?string $name,
		public readonly string $table,
		public readonly bool $autoload,
		public readonly array $properties,
		public readonly int $line,
	) {}
}
