<?php

namespace PHersist\Generator\Model;

/**
 * A reference from a class to objects: a Class or DynamicClass property, or
 * a relation through a join table. When the referred object is deleted, the
 * reference has to be cleaned up.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Reference {
	/**
	 * @param Record $record the class that holds the reference
	 * @param string $table the table that holds the reference
	 * @param string $column the column with the id of the referred object
	 * @param ?string $target the referred class, with namespace; null for any class (DynamicClass)
	 * @param ?Property $property the property that holds the reference, or null for a relation
	 * @param ?Relation $relation the relation that holds the reference, or null for a property
	 */
	public function __construct(
		public readonly Record $record,
		public readonly string $table,
		public readonly string $column,
		public readonly ?string $target,
		public readonly ?Property $property = null,
		public readonly ?Relation $relation = null,
	) {}
}
