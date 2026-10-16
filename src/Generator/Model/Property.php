<?php

namespace PHersist\Generator\Model;

/**
 * A <property> of a class. The type-specific values are null for the types
 * that don't have them.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Property {
	/**
	 * @param string $name the property name
	 * @param string $type the type, like 'Text' or 'Class'; a PHersist\Types\ARPropertyType<type> class
	 * @param bool $required whether it must have a value on commit
	 * @param list<string> $columns the column names; two for a DynamicClass (class name and id), one otherwise
	 * @param string|int|float|bool|null $default the value for new objects, null if there is none;
	 *   Decimal defaults are normalized strings
	 * @param bool $signed for Int: whether the column is signed
	 * @param ?int $precision for Decimal: the total number of digits
	 * @param ?int $scale for Decimal: the number of digits after the decimal point
	 * @param ?string $updateOn for Date, DateTime and TimestampText: 'create' or 'modify', or null if not set
	 * @param ?string $dateFormat for TimestampText: the date format, or null for the default
	 * @param ?string $class for Class: the class it refers to, with namespace, without leading backslash
	 * @param ?string $onRemoteDelete for Class and DynamicClass: 'null', 'restrict' or 'cascade'
	 * @param bool $useNamespace for DynamicClass: whether the class name is stored with its namespace
	 * @param int $line the line of the element in the XML
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly bool $required,
		public readonly array $columns,
		public readonly string|int|float|bool|null $default,
		public readonly bool $signed,
		public readonly ?int $precision,
		public readonly ?int $scale,
		public readonly ?string $updateOn,
		public readonly ?string $dateFormat,
		public readonly ?string $class,
		public readonly ?string $onRemoteDelete,
		public readonly bool $useNamespace,
		public readonly int $line,
	) {}

	/**
	 * Returns whether the property refers to an object: a Class or DynamicClass property.
	 */
	public function isReference() : bool {
		return $this->type == 'Class' || $this->type == 'DynamicClass';
	}
}
