<?php

namespace PHersist\Generator\Schema;

/**
 * What a column holds, independent of the database. Each schema generator
 * picks the SQL type for it.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
enum ColumnType {
	/** The id of the table's objects; the primary key */
	case Id;
	/** The id of another object: a Class property, a relation's ids, or a map's owner */
	case Reference;
	case Int;
	/** A non-negative integer, like a position or the softdelete column */
	case UnsignedInt;
	case Bool;
	case Float;
	/** A fixed-point number, with the column's precision and scale */
	case Decimal;
	case Text;
	case Date;
	case DateTime;
	/** A class name, short enough to index: of a DynamicClass, or a relation's or map's type column */
	case ClassName;
	/** A map key: indexed, and compared exactly (case-sensitive, trailing spaces count) */
	case MapKey;
	/** A map value: any text, stored exactly */
	case MapValue;
}
