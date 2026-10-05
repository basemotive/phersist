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

		self::checkElements($doc->documentElement);
		self::checkRelations($doc->documentElement);
		self::checkMaps($doc->documentElement);

		return $doc->documentElement;
	}

	/**
	 * The elements of the model XML: their allowed child elements, their
	 * known and required attributes, the attributes that hold a table or
	 * column name (see checkName()), and those that end up in the generated
	 * PHP code as a name (an identifier, or a qualified name with namespace,
	 * see checkIdentifier()).
	 */
	private const ELEMENTS = [
		'project' => [
			'children' => [ 'mysql', 'class' ],
			'attributes' => [ 'database', 'tablestyle', 'namespace', 'id_style' ],
			'required' => [ 'database', 'tablestyle' ],
			'names' => [],
			'identifiers' => [],
			'qualified' => [ 'namespace' ],
		],
		'mysql' => [
			'children' => [],
			'attributes' => [ 'charset', 'collate' ],
			'required' => [],
			'names' => [],
			'identifiers' => [],
			'qualified' => [],
		],
		'class' => [
			'children' => [ 'dataset', 'relation', 'map' ],
			'attributes' => [ 'name', 'id', 'table', 'database', 'softdelete', 'trait' ],
			'required' => [ 'name' ],
			'names' => [ 'id', 'table' ],
			'identifiers' => [ 'name' ],
			'qualified' => [ 'trait' ],
		],
		'dataset' => [
			'children' => [ 'property' ],
			'attributes' => [ 'name', 'autoload', 'table' ],
			'required' => [],
			'names' => [ 'table' ],
			'identifiers' => [],
			'qualified' => [],
		],
		'property' => [
			'children' => [],
			// Attributes for all types; PROPERTY_TYPE_ATTRIBUTES has the type-specific ones
			'attributes' => [ 'name', 'type', 'required', 'fieldname', 'fieldnames' ],
			'required' => [ 'name' ],
			// fieldnames is a list; getFieldNames() checks those names
			'names' => [ 'fieldname' ],
			'identifiers' => [ 'name' ],
			'qualified' => [],
		],
		'relation' => [
			'children' => [],
			'attributes' => [ 'name', 'type', 'class', 'table', 'local_id', 'remote_id', 'table_owner',
				'load_objects', 'order_field', 'cascade_delete', 'local_type', 'use_namespace' ],
			'required' => [ 'name', 'type', 'class', 'table', 'local_id', 'remote_id' ],
			'names' => [ 'table', 'local_id', 'remote_id', 'order_field', 'local_type' ],
			'identifiers' => [ 'name' ],
			'qualified' => [],
		],
		'map' => [
			'children' => [ 'key', 'value' ],
			'attributes' => [ 'name', 'table', 'id', 'type', 'use_namespace' ],
			'required' => [ 'name', 'table' ],
			'names' => [ 'table', 'id', 'type' ],
			'identifiers' => [ 'name' ],
			'qualified' => [],
		],
		'key' => [
			'children' => [],
			'attributes' => [ 'name' ],
			'required' => [ 'name' ],
			'names' => [ 'name' ],
			'identifiers' => [],
			'qualified' => [],
		],
		'value' => [
			'children' => [],
			'attributes' => [ 'name' ],
			'required' => [ 'name' ],
			'names' => [ 'name' ],
			'identifiers' => [],
			'qualified' => [],
		],
	];

	/**
	 * The type-specific attributes of a property, and which of them are required.
	 */
	private const PROPERTY_TYPE_ATTRIBUTES = [
		'Text' => [ 'attributes' => [ 'default' ], 'required' => [] ],
		'Int' => [ 'attributes' => [ 'default', 'signed' ], 'required' => [] ],
		'Float' => [ 'attributes' => [ 'default' ], 'required' => [] ],
		'Decimal' => [ 'attributes' => [ 'default', 'precision', 'scale' ], 'required' => [] ],
		'Bool' => [ 'attributes' => [ 'default' ], 'required' => [] ],
		'Date' => [ 'attributes' => [ 'update_on' ], 'required' => [] ],
		'DateTime' => [ 'attributes' => [ 'update_on' ], 'required' => [] ],
		'TimestampText' => [ 'attributes' => [ 'update_on', 'date_format' ], 'required' => [] ],
		'Class' => [ 'attributes' => [ 'class', 'on_remote_delete' ], 'required' => [ 'class' ] ],
		'DynamicClass' => [ 'attributes' => [ 'on_remote_delete', 'use_namespace' ], 'required' => [] ],
	];

	/**
	 * The attributes that only allow a fixed set of values, for every element
	 * that has them. The generators read the boolean ones with getBool().
	 */
	private const ATTRIBUTE_VALUES = [
		'id_style' => [ 'short', 'long' ],
		'softdelete' => [ 'true', 'false' ],
		'autoload' => [ 'true', 'false' ],
		'required' => [ 'true', 'false' ],
		'signed' => [ 'true', 'false' ],
		'table_owner' => [ 'true', 'false' ],
		'load_objects' => [ 'true', 'false' ],
		'cascade_delete' => [ 'true', 'false' ],
		'use_namespace' => [ 'true', 'false' ],
	];

	/**
	 * Checks the structure of the XML: that every element is known and in the
	 * right place, has only known attributes, and has its required attributes
	 * (with a non-empty value), and that attributes with a fixed set of values
	 * (see ATTRIBUTE_VALUES) have one of them. Without this check, a typo like requried="true"
	 * or a missing local_id would be ignored, or only fail at runtime.
	 *
	 * @param DOMElement $root the root element of the XML tree
	 * @throws \Exception listing all the problems, with their line numbers
	 */
	private static function checkElements(DOMElement $root) : void {
		$problems = [];
		if ($root->tagName != 'project')
			$problems[] = "line {$root->getLineNo()}: the root element must be <project>, not <{$root->tagName}>";
		else
			self::checkElement($root, $problems);

		if ($problems)
			throw new \Exception("Invalid model XML:\n- ".implode("\n- ", $problems));
	}

	/**
	 * Checks an element and its children, see checkElements().
	 *
	 * @param DOMElement $element the element to check, with a tag name in ELEMENTS
	 * @param list<string> $problems the problems found so far, to add to
	 */
	private static function checkElement(DOMElement $element, array &$problems) : void {
		$spec = self::ELEMENTS[$element->tagName];
		$known = $spec['attributes'];
		$required = $spec['required'];

		if ($element->tagName == 'property') {
			$type = $element->hasAttribute('type') ? $element->getAttribute('type') : 'Text';
			if (isset(self::PROPERTY_TYPE_ATTRIBUTES[$type])) {
				$known = array_merge($known, self::PROPERTY_TYPE_ATTRIBUTES[$type]['attributes']);
				$required = array_merge($required, self::PROPERTY_TYPE_ATTRIBUTES[$type]['required']);
			} else {
				// The generators report the unknown type; accept the attributes of any type
				foreach (self::PROPERTY_TYPE_ATTRIBUTES as $typeSpec)
					$known = array_merge($known, $typeSpec['attributes']);
			}
		}

		$description = self::describe($element);
		foreach ($element->attributes as $attribute) {
			// Attributes in a namespace, like xsi:schemaLocation, aren't ours
			if ($attribute->namespaceURI !== null || in_array($attribute->name, $known))
				continue;

			$problem = "line {$element->getLineNo()}: $description has unknown attribute '{$attribute->name}'";
			if (isset($type)) {
				foreach (self::PROPERTY_TYPE_ATTRIBUTES as $typeSpec)
					if (in_array($attribute->name, $typeSpec['attributes'])) {
						$problem = "line {$element->getLineNo()}: {$description} has attribute '{$attribute->name}', which a property of type {$type} can't have";
						break;
					}
			}
			$suggestion = self::suggest($attribute->name, $known);
			if ($suggestion !== null)
				$problem .= ", did you mean '$suggestion'?";
			$problems[] = $problem;
		}

		foreach ($required as $name)
			if (trim($element->getAttribute($name)) === '')
				$problems[] = "line {$element->getLineNo()}: $description ".($element->hasAttribute($name) ? "has an empty '{$name}' attribute" : "is missing the required attribute '{$name}'");

		foreach (self::ATTRIBUTE_VALUES as $name => $values)
			if ($element->hasAttribute($name) && in_array($name, $known) && !in_array($element->getAttribute($name), $values, true))
				$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: expected "
					.implode(' or ', array_map(fn($value) => "'{$value}'", $values)).", got '{$element->getAttribute($name)}'";

		foreach ($spec['names'] as $name)
			if (trim($element->getAttribute($name)) !== '' && ($problem = self::checkName($element->getAttribute($name))) !== null)
				$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: {$problem}";

		foreach ($spec['identifiers'] as $name)
			if (trim($element->getAttribute($name)) !== '' && ($problem = self::checkIdentifier($element->getAttribute($name), false)) !== null)
				$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: {$problem}";

		foreach ($spec['qualified'] as $name)
			if (trim($element->getAttribute($name)) !== '' && ($problem = self::checkIdentifier($element->getAttribute($name), true)) !== null)
				$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: {$problem}";

		foreach ($element->childNodes as $child) {
			if (!$child instanceof DOMElement)
				continue;
			if (in_array($child->tagName, $spec['children']))
				self::checkElement($child, $problems);
			else
				$problems[] = "line {$child->getLineNo()}: unexpected element <{$child->tagName}> in $description"
					.($spec['children'] ? ', expected '.implode(' or ', array_map(fn($name) => "<{$name}>", $spec['children'])) : '');
		}
	}

	/**
	 * Checks a table or column name. The generated SQL quotes the names in
	 * backticks, so any name MySQL allows works, except one with a backtick.
	 *
	 * @param string $name the name
	 * @return ?string what is wrong with the name, or null if it is valid
	 */
	public static function checkName(string $name) : ?string {
		if (strpos($name, '`') !== false)
			return "'{$name}' contains a backtick";
		if (preg_match('/[\x00-\x1F\x7F]/', $name))
			return "'{$name}' contains a control character";
		if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $name))
			return "'{$name}' contains a character outside the Basic Multilingual Plane, which MySQL doesn't allow in names";
		if (substr($name, -1) == ' ')
			return "'{$name}' ends with a space, which MySQL doesn't allow in names";
		if (preg_match_all('/./su', $name) > 64)
			return "'{$name}' is longer than 64 characters, the maximum for MySQL names";
		return null;
	}

	/**
	 * Checks a name that the generator writes into the PHP code: a class,
	 * property, relation or map name must be a PHP identifier (letters,
	 * digits and underscores, not starting with a digit; like PHP, any
	 * non-ASCII character counts as a letter). A qualified name is a list of
	 * identifiers separated by backslashes, optionally with a leading one.
	 *
	 * @param string $name the name
	 * @param bool $qualified whether the name may include a namespace
	 * @return ?string what is wrong with the name, or null if it is valid
	 */
	public static function checkIdentifier(string $name, bool $qualified) : ?string {
		$identifier = '[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*';
		if ($qualified) {
			if (!preg_match('/^\\\\?'.$identifier.'(\\\\'.$identifier.')*$/D', $name))
				return "'{$name}' is not a valid PHP name: use identifiers (letters, digits and underscores, not starting with a digit) separated by backslashes";
		} elseif (!preg_match('/^'.$identifier.'$/D', $name))
			return "'{$name}' is not a valid PHP identifier: use only letters, digits and underscores, and don't start with a digit";
		return null;
	}

	/**
	 * Describes an element for an error message, like "<property> 'title' of class 'Forum'".
	 *
	 * @param DOMElement $element the element
	 * @return string the description
	 */
	private static function describe(DOMElement $element) : string {
		$description = "<{$element->tagName}>";
		if ($element->getAttribute('name') !== '')
			$description .= " '{$element->getAttribute('name')}'";

		for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode)
			if ($parent->tagName == 'class') {
				$description .= " of class '{$parent->getAttribute('name')}'";
				break;
			}

		return $description;
	}

	/**
	 * Returns the known name closest to a misspelled one, if it is close enough.
	 *
	 * @param string $name the unknown name
	 * @param list<string> $known the known names
	 * @return ?string the suggested name, or null if none is close
	 */
	private static function suggest(string $name, array $known) : ?string {
		$best = null;
		$bestDistance = 3;
		foreach ($known as $candidate) {
			$distance = levenshtein(strtolower($name), $candidate);
			if ($distance < $bestDistance) {
				$best = $candidate;
				$bestDistance = $distance;
			}
		}
		return $best;
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
					throw new \Exception("Relation '{$relationName}' of class '{$className}' cannot use its own class's table '{$table}'."
						.' Its rows would be the object itself, so it holds at most one related object: use a Class property instead');

				if (self::getBool($relation, 'table_owner') && isset($tableClasses[$table]))
					throw new \Exception("Relation '{$relationName}' of class '{$className}'"
						." cannot have table_owner=\"true\", because its table '{$table}' is a table of class '{$tableClasses[$table]}'."
						.' A relation on a class\'s table is derived and must be read-only: use table_owner="false",'
						.' and change the property of the related objects instead');
			}
	}

	/**
	 * Checks the tables of the maps. A map selects its rows by owner (and by
	 * class name, with a type column), and replaces all of them on commit, so
	 * it needs a table of its own:
	 *
	 * - A map can't use a table of a class or a relation. Its rows would be
	 *   read as map data and deleted, and the map's columns are required.
	 * - Maps of the same class can't share a table: they would load and delete
	 *   each other's rows.
	 * - Maps of different classes can share a table only with a type column
	 *   that tells their rows apart, and with the same columns.
	 *
	 * @param DOMElement $root the root element of the XML tree
	 * @throws \Exception if a map uses a table that it can't
	 */
	private static function checkMaps(DOMElement $root) : void {
		$classElements = $root->getElementsByTagName('class');

		$usedTables = [];
		foreach ($classElements as $classElement) {
			$className = $classElement->getAttribute('name');
			foreach (self::getClassTables($classElement) as $table)
				$usedTables[$table] ??= "a table of class '{$className}'";
			foreach ($classElement->getElementsByTagName('relation') as $relation)
				$usedTables[$relation->getAttribute('table')] ??=
					"the table of relation '{$relation->getAttribute('name')}' of class '{$className}'";
		}

		$mapTables = [];
		foreach ($classElements as $classElement)
			foreach ($classElement->getElementsByTagName('map') as $map) {
				$className = $classElement->getAttribute('name');
				$mapName = $map->getAttribute('name');
				$table = $map->getAttribute('table');
				$description = "Map '{$mapName}' of class '{$className}'";

				if (isset($usedTables[$table]))
					throw new \Exception("{$description} cannot use table '{$table}', because it is {$usedTables[$table]}."
						.' A map replaces all rows of its owner on commit, so it needs a table of its own');

				$columns = [
					'id' => $map->getAttribute('id') != '' ?
						$map->getAttribute('id') : self::getAuto($root, 'relation_id', $className),
					'type' => $map->getAttribute('type'),
					'keys' => [],
					'values' => [],
				];
				foreach ($map->getElementsByTagName('key') as $key)
					$columns['keys'][] = $key->getAttribute('name');
				foreach ($map->getElementsByTagName('value') as $value)
					$columns['values'][] = $value->getAttribute('name');

				if (!isset($mapTables[$table])) {
					$mapTables[$table] = [ 'class' => $className, 'map' => $mapName, 'columns' => $columns ];
					continue;
				}

				$other = $mapTables[$table];
				$otherDescription = "map '{$other['map']}' of class '{$other['class']}'";
				if ($other['class'] == $className)
					throw new \Exception("{$description} cannot use table '{$table}', because {$otherDescription} already does."
						.' Maps of the same class would load and delete each other\'s rows, so each needs a table of its own');
				if ($columns['type'] === '' || $other['columns']['type'] === '')
					throw new \Exception("{$description} shares table '{$table}' with {$otherDescription},"
						.' so both need a type attribute: the type column stores the class name that tells their rows apart');
				if ($columns != $other['columns'])
					throw new \Exception("{$description} shares table '{$table}' with {$otherDescription},"
						.' so it must have the same id, type, <key> and <value> column names');
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
	 * Reads a boolean attribute. load() has checked that its value is 'true'
	 * or 'false' (see ATTRIBUTE_VALUES).
	 *
	 * @param DOMElement $element the element in the XML tree
	 * @param string $name the attribute name
	 * @param bool $default the value if the attribute is not set
	 * @return bool the value of the attribute
	 */
	public static function getBool(DOMElement $element, string $name, bool $default = false) : bool {
		return $element->hasAttribute($name) ? $element->getAttribute($name) === 'true' : $default;
	}

	/**
	 * Returns whether an Int property is signed: it is, unless signed="false".
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @return bool true for an INT column, false for INT UNSIGNED
	 */
	public static function isSigned(DOMElement $property) : bool {
		return self::getBool($property, 'signed', true);
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
					throw new \InvalidArgumentException("expected 'true' or 'false', got '{$default}'");
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
	 * @throws \Exception if both attributes are set, or a name is empty or invalid, or the number of names is wrong for the type
	 */
	public static function getFieldNames(DOMElement $property, string $type) : array {
		$name = $property->getAttribute('name');
		$expected = $type == 'DynamicClass' ? 2 : 1;

		if ($property->hasAttribute('fieldname') && $property->hasAttribute('fieldnames'))
			throw new \Exception("Property '{$name}' has both a fieldname and a fieldnames attribute, use only one");

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
			throw new \Exception("Property '{$name}' has an empty field name");
		foreach ($fieldNames as $fieldName)
			if (($problem = self::checkName($fieldName)) !== null)
				throw new \Exception("Property '{$name}' has an invalid field name: $problem");
		if (count($fieldNames) != $expected)
			throw new \Exception("Property '{$name}' of type {$type} needs {$expected} field name".($expected == 1 ? '' : 's').', got '.count($fieldNames));

		return $fieldNames;
	}

	/**
	 * Uses a table style converter to convert class and property names into table and column names.
	 *
	 * @param DOMElement $root the root element of the XML tree
	 * @param string $term what kind of term to translate: table | id | fieldname | relation_id | relation_combo
	 * @param string $name the name to translate
	 * @return string the converted name
	 * @throws \Exception if the table style is unknown, or the converted name is invalid (see checkName())
	 */
	public static function getAuto(DOMElement $root, string $term, string $name) : string {
		$styleConverter = __NAMESPACE__.'\\TS'.$root->getAttribute('tablestyle');

		if (!class_exists($styleConverter))
			throw new \Exception("Cannot find table style converter class {$styleConverter}");

		if ($term == 'id') {
			// the root element property 'id_style' if it exists can be 'long' or
			// 'short' (checked by load()), with the default being 'short', which
			// means the main primary key field for tables will be named 'id',
			// whereas the long version uses the converted class name + '_id'
			if ($root->getAttribute('id_style') != 'long')
				return 'id';
		}

		$result = $styleConverter::translate($term, $name);
		$what = $term == 'table' ? 'table name' : 'column name';
		foreach (explode(',', $result) as $converted)
			if (($problem = self::checkName($converted)) !== null)
				throw new \Exception("'{$name}' converts to an invalid {$what}: {$problem}. Rename it, or set the {$what} explicitly");
		return $result;
	}
}
