<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * The DynamicClass property type handles the conversion between an
 * (ActiveRecord) object in PHP to an object ID and its classname
 * in the database and vice versa.
 *
 * Contrary to the Class property, this object reference is not
 * statically typed, but stored in the database next to the id. Assigned
 * values can be any ActiveRecord object; other values throw an exception.
 * The class name is stored without namespace if it's in the namespace of
 * the class with the property, or always fully qualified with use_namespace.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeDynamicClass extends ARPropertyType {
	public function __construct(?ActiveRecord $activeRecord = null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		$class_name = $values[$prop['fieldnames'][0]];
		$id = $values[$prop['fieldnames'][1]];
		if ($id === null)
			return null;
		if (is_string($class_name))
			$class_name = $this->_qualify($prop, $class_name);
		// The class name comes from the database, so only ActiveRecord classes
		// may be instantiated: a renamed class would otherwise cause an Error,
		// and any other autoloadable class would be constructed with the id.
		if (!is_string($class_name) || !is_subclass_of($class_name, ActiveRecord::class))
			throw new \Exception('Column '.$prop['fieldnames'][0].' refers to '.(is_string($class_name) ? "'$class_name'" : get_debug_type($class_name)).', which is not an existing ActiveRecord class');
		/** @var class-string<ActiveRecord> $class_name */
		// We use the fetchObject method instead of the constructor so the
		// ObjectCache is used
		return ActiveRecord::fetchObject($class_name, (int)$id);
	}

	public function toDB(array $prop, mixed $value) : array {
		// ActiveRecord::commit() makes sure the related object has been committed already
		$class_name = $value === null ? null : $this->_storedName($prop, get_class($value));
		$id = $value === null ? null : $value->id;
		// A reference to null is stored as NULL in both fields.
		return [
			$prop['fieldnames'][0] => $class_name,
			$prop['fieldnames'][1] => $id
		];
	}

	/**
	 * Returns the class name to store for a class. That is the fully qualified
	 * name, unless use_namespace is false and the class is in the namespace of
	 * the class that has the property: then the namespace is left out. A class
	 * without a namespace then gets a leading backslash, so it isn't mistaken
	 * for one in that namespace.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param string $class the fully qualified class name, without leading backslash
	 * @return string the name to store
	 */
	private function _storedName(array $prop, string $class) : string {
		if ($prop['use_namespace'])
			return $class;
		$pos = strrpos($class, '\\');
		$namespace = $pos === false ? '' : substr($class, 0, $pos);
		if ($namespace === $prop['namespace'])
			return $pos === false ? $class : substr($class, $pos + 1);
		return $namespace === '' ? '\\'.$class : $class;
	}

	/**
	 * Turns a stored class name back into a fully qualified one; the reverse of
	 * _storedName(). With use_namespace false, a name without namespace is in
	 * the namespace of the class that has the property.
	 *
	 * @param array<string, mixed> $prop the property definition from the metadata
	 * @param string $class the stored class name
	 * @return string the fully qualified class name, without leading backslash
	 */
	private function _qualify(array $prop, string $class) : string {
		if (!$prop['use_namespace'] && $prop['namespace'] !== '' && !str_contains($class, '\\'))
			return $prop['namespace'].'\\'.$class;
		return ltrim($class, '\\');
	}

	public function normalize(array $prop, mixed $value) : mixed {
		if (!($value instanceof ActiveRecord))
			throw new \InvalidArgumentException('expected an ActiveRecord object, got '.get_debug_type($value));
		return $value;
	}

	public function toDBSearch(array $prop, mixed $value) : array {
		// A plain id is not accepted, as it doesn't tell which class is meant
		if ($value !== null && !($value instanceof ActiveRecord))
			throw new \InvalidArgumentException('expected an ActiveRecord object or null, got '.(is_string($value) ? "'$value'" : get_debug_type($value)));
		// Without an id, the search would turn into one for null
		if ($value !== null && $value->id === null)
			throw new \InvalidArgumentException('cannot search for a '.get_class($value).' object that has no id yet');
		return $this->toDB($prop, $value);
	}

	// This class lacks the dereference method, because you'd have to dynamically
	// join in all the possible tables.
	// Just building your own SQL query and feeding the results into the ORM system is probably
	// the best here.
}