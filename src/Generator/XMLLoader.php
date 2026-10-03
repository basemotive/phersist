<?php

namespace PHersist\Generator;

use DOMDocument;
use DOMElement;
use PHersist\Types\ARPropertyTypeFloat;
use PHersist\Types\ARPropertyTypeInt;

/**
 * Parses the model XML for the generators.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class XMLLoader {
	/**
	 * Parses the XML and returns its root element.
	 *
	 * @throws \Exception if the XML is empty or not well-formed
	 */
	public static function load(string $xml) : DOMElement {
		if (trim($xml) === '')
			throw new \Exception('Invalid XML: the document is empty');

		$doc = new DOMDocument();

		// Collect the libxml errors instead of emitting PHP warnings
		$useInternalErrors = libxml_use_internal_errors(true);
		libxml_clear_errors();
		try {
			$loaded = $doc->loadXML($xml);
			$errors = libxml_get_errors();
			libxml_clear_errors();
		} finally {
			libxml_use_internal_errors($useInternalErrors);
		}

		if (!$loaded || !$doc->documentElement) {
			$messages = [];
			foreach ($errors as $error) {
				if ($error->level == LIBXML_ERR_WARNING)
					continue;
				$messages[] = trim($error->message).' on line '.$error->line;
			}
			throw new \Exception('Invalid XML: '.($messages ? implode('; ', $messages) : 'the document could not be parsed'));
		}

		self::checkRelations($doc->documentElement);

		return $doc->documentElement;
	}

	/**
	 * Checks the tables of the relations:
	 *
	 * - A relation can't use one of its own class's tables, unless that table
	 *   also holds the related class (like a parent/children relation). Its rows
	 *   would be the object itself, so it holds at most one related object,
	 *   which a Class property already does.
	 * - A relation can't own a table that holds a class. Such a relation is
	 *   derived: its rows are the objects of that class, so writing the
	 *   relation would delete their data.
	 *
	 * @param DOMElement $root the root element of the XML tree
	 * @throws \Exception if a relation uses a table that it can't
	 */
	private static function checkRelations(DOMElement $root) : void {
		$classElements = $root->getElementsByTagName('class');

		$tableClasses = [];
		$classTables = [];
		foreach ($classElements as $classElement) {
			$tables = self::getClassTables($classElement);
			$classTables[self::qualifyClass($root, $classElement->getAttribute('name'))] = $tables;
			foreach ($tables as $table)
				$tableClasses[$table] ??= $classElement->getAttribute('name');
		}

		foreach ($classElements as $classElement)
			foreach ($classElement->getElementsByTagName('relation') as $relation) {
				$className = $classElement->getAttribute('name');
				$relationName = $relation->getAttribute('name');
				$table = $relation->getAttribute('table');

				$ownTables = $classTables[self::qualifyClass($root, $className)];
				$relatedTables = $classTables[self::qualifyClass($root, $relation->getAttribute('class'))] ?? [];
				if (in_array($table, $ownTables) && !in_array($table, $relatedTables))
					throw new \Exception("Relation '$relationName' of class '$className' cannot use its own class's table '$table'."
						.' Its rows would be the object itself, so it holds at most one related object: use a Class property instead');

				if ($relation->getAttribute('table_owner') == 'true' && isset($tableClasses[$table]))
					throw new \Exception("Relation '$relationName' of class '$className'"
						." cannot have table_owner=\"true\", because its table '$table' is a table of class '{$tableClasses[$table]}'."
						.' A relation on a class\'s table is derived and must be read-only: use table_owner="false",'
						.' and change the property of the related objects instead');
			}
	}

	/**
	 * Returns the fully qualified name of a class referred to in the XML.
	 *
	 * @param DOMElement $root the root element of the XML tree
	 * @param string $class the class name; without a namespace, the project's is used
	 * @return string the class name with namespace, without leading backslash
	 */
	public static function qualifyClass(DOMElement $root, string $class) : string {
		if (strpos($class, '\\') !== false)
			return ltrim($class, '\\');
		$namespace = trim($root->getAttribute('namespace'), '\\');
		return $namespace == '' ? $class : $namespace.'\\'.$class;
	}

	/**
	 * Returns the tables of a class: its base table, then those of its datasets.
	 *
	 * @param DOMElement $classElement the class element in the XML tree
	 * @return non-empty-list<string> the table names
	 */
	public static function getClassTables(DOMElement $classElement) : array {
		$table = $classElement->hasAttribute('table') ?
			$classElement->getAttribute('table')
			:
			self::getAuto($classElement->ownerDocument->documentElement, 'table', $classElement->getAttribute('name'));

		$tables = [$table];
		foreach ($classElement->getElementsByTagName('dataset') as $dataset)
			if ($dataset->hasAttribute('table'))
				$tables[] = $dataset->getAttribute('table');

		return array_values(array_unique($tables));
	}

	/**
	 * Returns whether an Int property is signed: it is, unless signed="false".
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @return bool true for an INT column, false for INT UNSIGNED
	 */
	public static function isSigned(DOMElement $property) : bool {
		return !$property->hasAttribute('signed') || $property->getAttribute('signed') == 'true';
	}

	/**
	 * Reads and validates the default attribute of a Text, Int, Float or Bool
	 * property. Decimal defaults depend on the column size, so the generators
	 * validate those themselves.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @param string $type the property type
	 * @return string|int|float|bool|null the default value, or null if there is none
	 * @throws \Exception if the default is not a valid value for the type
	 */
	public static function getDefault(DOMElement $property, string $type) : string|int|float|bool|null {
		if (!$property->hasAttribute('default'))
			return null;
		$default = $property->getAttribute('default');

		try {
			if ($type == 'Text')
				return $default;

			if ($type == 'Int')
				return (new ARPropertyTypeInt())->normalize([ 'signed' => self::isSigned($property) ], $default);

			if ($type == 'Float')
				return (new ARPropertyTypeFloat())->normalize([], $default);

			if ($type == 'Bool') {
				if ($default !== 'true' && $default !== 'false')
					throw new \InvalidArgumentException("expected 'true' or 'false', got '$default'");
				return $default === 'true';
			}
		} catch (\InvalidArgumentException $e) {
			throw new \Exception("Invalid default for property '{$property->getAttribute('name')}': {$e->getMessage()}", 0, $e);
		}

		return null;
	}

	/**
	 * Returns the column names for a property: from the fieldname or fieldnames
	 * attribute, or generated from the property name with the table style.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @param string $type the property type
	 * @return list<string> the column names; two for a DynamicClass (class name and id), one otherwise
	 * @throws \Exception if both attributes are set, or a name is empty, or the number of names is wrong for the type
	 */
	public static function getFieldNames(DOMElement $property, string $type) : array {
		$name = $property->getAttribute('name');
		$expected = $type == 'DynamicClass' ? 2 : 1;

		if ($property->hasAttribute('fieldname') && $property->hasAttribute('fieldnames'))
			throw new \Exception("Property '$name' has both a fieldname and a fieldnames attribute, use only one");

		if ($property->hasAttribute('fieldname'))
			$fieldNames = [ $property->getAttribute('fieldname') ];
		elseif ($property->hasAttribute('fieldnames'))
			$fieldNames = explode(',', $property->getAttribute('fieldnames'));
		else {
			$root = $property->ownerDocument->documentElement;
			if ($type == 'Class')
				return [ self::getAuto($root, 'relation_id', $name) ];
			if ($type == 'DynamicClass')
				return explode(',', self::getAuto($root, 'relation_combo', $name));
			return [ self::getAuto($root, 'fieldname', $name) ];
		}

		$fieldNames = array_map('trim', $fieldNames);
		if (in_array('', $fieldNames, true))
			throw new \Exception("Property '$name' has an empty field name");
		if (count($fieldNames) != $expected)
			throw new \Exception("Property '$name' of type $type needs $expected field name".($expected == 1 ? '' : 's').', got '.count($fieldNames));

		return $fieldNames;
	}

	/**
	 * Uses a table style converter to convert class and property names into table and column names.
	 *
	 * @param DOMElement $root the root element of the XML tree
	 * @param string $term what kind of term to translate: table | id | fieldname | relation_id | relation_combo
	 * @param string $name the name to translate
	 * @return string the converted name
	 */
	public static function getAuto(DOMElement $root, string $term, string $name) : string {
		$styleConverter = __NAMESPACE__.'\\TS'.$root->getAttribute('tablestyle');

		if (!class_exists($styleConverter))
			throw new \Exception("Cannot find table style converter class {$styleConverter}");

		if ($term == 'id') {
			// the root element property 'id_style' if it exists can be 'long' or
			// 'short', with the default being 'short', which means the main primary
			// key field for tables will be named 'id', whereas the long version uses
			// the converted class name + '_id'
			$idStyle = $root->hasAttribute('id_style') ? $root->getAttribute('id_style') : 'short';
			if ($idStyle == 'short')
				return 'id';
		}

		return $styleConverter::translate($term, $name);
	}
}
