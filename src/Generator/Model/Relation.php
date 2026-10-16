<?php

namespace PHersist\Generator\Model;

/**
 * A <relation> of a class: a list of related objects, from the rows of a table.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Relation {
	/**
	 * @param string $name the relation name
	 * @param string $type the type, like 'NN'; a PHersist\Types\ARRelationType<type> class
	 * @param string $class the related class, with namespace, without leading backslash
	 * @param string $table the table that holds the relation
	 * @param string $localId the column with the id of the object that has the relation
	 * @param string $remoteId the column with the id of the related object
	 * @param ?string $localType the column with the class name of the object that has the relation, or null
	 * @param ?string $orderField the column to order the related objects by, or null
	 * @param bool $tableOwner whether the relation writes to the table
	 * @param bool $loadObjects whether the autoload datasets of the related objects are loaded too
	 * @param bool $cascadeDelete whether deleting the object deletes the related objects
	 * @param bool $useNamespace whether the local_type column stores the class name with its namespace
	 * @param int $line the line of the element in the XML
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly string $class,
		public readonly string $table,
		public readonly string $localId,
		public readonly string $remoteId,
		public readonly ?string $localType,
		public readonly ?string $orderField,
		public readonly bool $tableOwner,
		public readonly bool $loadObjects,
		public readonly bool $cascadeDelete,
		public readonly bool $useNamespace,
		public readonly int $line,
	) {}

	/**
	 * Returns the columns that the relation uses in its table.
	 *
	 * @return array<string, string> the column names, by attribute: local_id,
	 *   remote_id, and local_type and order_field if set
	 */
	public function columns() : array {
		$columns = [ 'local_id' => $this->localId, 'remote_id' => $this->remoteId ];
		if ($this->localType !== null)
			$columns['local_type'] = $this->localType;
		if ($this->orderField !== null)
			$columns['order_field'] = $this->orderField;
		return $columns;
	}
}
