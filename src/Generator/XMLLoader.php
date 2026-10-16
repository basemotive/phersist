<?php

namespace PHersist\Generator;

use DOMDocument;
use DOMElement;
use PHersist\Generator\Model\Dataset;
use PHersist\Generator\Model\Map;
use PHersist\Generator\Model\Project;
use PHersist\Generator\Model\Property;
use PHersist\Generator\Model\Record;
use PHersist\Generator\Model\Relation;
use PHersist\ReferenceRegistry;
use PHersist\Types\ARPropertyTypeDecimal;
use PHersist\Types\ARPropertyTypeFloat;
use PHersist\Types\ARPropertyTypeInt;

/**
 * Loads the model XML for the generators: checks it, and builds the model
 * (see Model\Project) from it.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class XMLLoader {
	/**
	 * Loads a model XML: parses and checks it, and builds the model from it,
	 * with the model XMLs it depends on.
	 *
	 * @param string $xmlFile the path of the model XML
	 * @return Project the model
	 * @throws \Exception if a file can't be read, or isn't a valid model XML
	 */
	public static function load(string $xmlFile) : Project {
		$path = realpath($xmlFile);
		if ($path === false)
			throw new \Exception("Cannot find model XML '{$xmlFile}'");
		$loaded = [];
		return self::loadFile($path, [], $loaded);
	}

	/**
	 * Loads a model XML, see load().
	 *
	 * @param string $path the real path of the model XML
	 * @param list<string> $dependents the model XMLs that (indirectly) depend on this one, to find cycles
	 * @param array<string, Project> $loaded the model XMLs loaded so far, by path, so each is loaded once
	 */
	private static function loadFile(string $path, array $dependents, array &$loaded) : Project {
		if (isset($loaded[$path]))
			return $loaded[$path];

		$xml = is_file($path) ? file_get_contents($path) : false;
		if ($xml === false)
			throw new \Exception("Cannot read model XML '{$path}'");
		$root = self::parse($xml);
		self::checkElements($root);

		$dependencies = [];
		foreach ($root->childNodes as $child) {
			if (!$child instanceof DOMElement || $child->tagName != 'depends')
				continue;

			// Relative to the directory of the XML
			$file = $child->getAttribute('xml');
			if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $file))
				$file = dirname($path).'/'.$file;
			$dependency = realpath($file);
			$where = "line {$child->getLineNo()} of '{$path}'";
			if ($dependency === false)
				throw new \Exception("Cannot find model XML '{$file}', from <depends> on {$where}");
			if ($dependency == $path || in_array($dependency, $dependents))
				throw new \Exception('Model XMLs can\'t depend on themselves, directly or through others: '.implode(' depends on ', array_merge($dependents, [ $path, $dependency ])));

			try {
				$dependencies[] = self::loadFile($dependency, array_merge($dependents, [ $path ]), $loaded);
			} catch (\Exception $e) {
				throw new \Exception("In model XML '{$dependency}', from <depends> on {$where}: {$e->getMessage()}", 0, $e);
			}
		}

		$project = self::buildProject($root, $path, $dependencies);
		ModelChecker::check($project);

		return $loaded[$path] = $project;
	}

	/**
	 * Parses an XML document.
	 *
	 * @param string $xml the XML
	 * @return DOMElement the root element
	 * @throws \Exception if the XML is empty or not well-formed
	 */
	private static function parse(string $xml) : DOMElement {
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
			'children' => [ 'depends', 'schema', 'class' ],
			'attributes' => [ 'database', 'tablestyle', 'namespace', 'id_style' ],
			'required' => [ 'database', 'tablestyle' ],
			'names' => [],
			'identifiers' => [],
			'qualified' => [ 'namespace' ],
		],
		'depends' => [
			'children' => [],
			'attributes' => [ 'xml' ],
			'required' => [ 'xml' ],
			'names' => [],
			'identifiers' => [],
			'qualified' => [],
		],
		'schema' => [
			'children' => [],
			// Attributes for all dialects; DIALECT_ATTRIBUTES has the dialect-specific ones
			'attributes' => [ 'dialect' ],
			'required' => [ 'dialect' ],
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
	 * The dialect-specific attributes of a <schema> element. Their values end up
	 * in the SQL as they are, so they may only contain letters, digits and
	 * underscores.
	 */
	private const DIALECT_ATTRIBUTES = [
		'mysql' => [ 'charset', 'collate' ],
		'mariadb' => [ 'charset', 'collate' ],
		'sqlite' => [],
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

		if ($element->tagName == 'schema') {
			$dialect = $element->getAttribute('dialect');
			if (isset(self::DIALECT_ATTRIBUTES[$dialect]))
				$dialectAttributes = self::DIALECT_ATTRIBUTES[$dialect];
			else {
				// Accept the attributes of any dialect, and only report the dialect
				$dialectAttributes = array_merge(...array_values(self::DIALECT_ATTRIBUTES));
				if ($dialect !== '') {
					$problem = "line {$element->getLineNo()}: {$description} has an unknown dialect '{$dialect}'";
					$suggestion = self::suggest($dialect, array_keys(self::DIALECT_ATTRIBUTES));
					$problems[] = $problem.($suggestion !== null ? ", did you mean '{$suggestion}'?" : ', use '
						.implode(' or ', array_map(fn($name) => "'{$name}'", array_keys(self::DIALECT_ATTRIBUTES))));
				}
			}
			$known = array_merge($known, $dialectAttributes);

			foreach ($dialectAttributes as $name)
				if ($element->hasAttribute($name) && !preg_match('/^[A-Za-z0-9_]+$/D', $element->getAttribute($name)))
					$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: '{$element->getAttribute($name)}'"
						.' may only contain letters, digits and underscores';
		}

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

		// An optional name attribute that is set must have a value: the
		// generators would use an empty value as the name
		foreach (array_merge($spec['names'], $spec['identifiers'], $spec['qualified']) as $name)
			if ($element->hasAttribute($name) && !in_array($name, $required) && trim($element->getAttribute($name)) === '')
				$problems[] = "line {$element->getLineNo()}: {$description} has an empty '{$name}' attribute, leave it out instead";

		foreach ($spec['names'] as $name)
			if (trim($element->getAttribute($name)) !== '' && ($problem = self::checkName($element->getAttribute($name))) !== null)
				$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: {$problem}";

		foreach (array_merge($spec['identifiers'], $spec['qualified']) as $name) {
			$value = $element->getAttribute($name);
			if (trim($value) === '')
				continue;
			$problem = self::checkIdentifier($value, in_array($name, $spec['qualified']));
			// Class, trait and namespace names must also be allowed by PHP where
			// the generator writes them; property, relation and map names only
			// end up in arrays and docblocks
			$kind = self::PHP_NAME_KINDS["{$element->tagName}.{$name}"] ?? null;
			if ($problem === null && $kind !== null)
				$problem = self::checkReservedName($value, $kind);
			if ($problem !== null)
				$problems[] = "line {$element->getLineNo()}: {$description} has an invalid '{$name}' attribute: {$problem}";
		}

		$schemas = [];
		foreach ($element->childNodes as $child) {
			if (!$child instanceof DOMElement)
				continue;
			if ($child->tagName == 'schema' && in_array('schema', $spec['children'])) {
				$other = $schemas[$child->getAttribute('dialect')] ?? null;
				if ($other !== null)
					$problems[] = "line {$child->getLineNo()}: there's already a ".self::describe($other)." on line {$other->getLineNo()}, use one per dialect";
				$schemas[$child->getAttribute('dialect')] = $child;
			}
			if (in_array($child->tagName, $spec['children']))
				self::checkElement($child, $problems);
			else
				$problems[] = "line {$child->getLineNo()}: unexpected element <{$child->tagName}> in $description"
					.($spec['children'] ? ', expected '.implode(' or ', array_map(fn($name) => "<{$name}>", $spec['children'])) : '');
		}
	}

	/**
	 * Checks a table or column name. The generated SQL quotes the names in
	 * backticks, so any name MySQL allows works, except one with a backtick,
	 * '?' or ':'.
	 *
	 * @param string $name the name
	 * @return ?string what is wrong with the name, or null if it is valid
	 */
	public static function checkName(string $name) : ?string {
		if (strpos($name, '`') !== false)
			return "'{$name}' contains a backtick";
		// Before PHP 8.4, PDO's SQL parser doesn't know backtick quoted names,
		// so a ? or :word inside one is taken for a placeholder and breaks the
		// query
		if (preg_match('/[?:]/', $name))
			return "'{$name}' contains a '?' or ':', which PDO before PHP 8.4 mistakes for a query parameter";
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
	 * The attributes that hold a class, trait or namespace name, for
	 * checkReservedName(), keyed by element and attribute name.
	 */
	private const PHP_NAME_KINDS = [
		'class.name' => 'class',
		'class.trait' => 'trait',
		'project.namespace' => 'namespace',
	];

	/**
	 * PHP's keywords, compile-time constants and reserved words, in lower
	 * case. None of them can be a class or trait name. Some are only
	 * reserved in later PHP versions, or for future use.
	 */
	private const RESERVED_WORDS = [
		'__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
		'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else',
		'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile',
		'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
		'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
		'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private',
		'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch',
		'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
		'__class__', '__dir__', '__file__', '__function__', '__line__', '__method__',
		'__namespace__', '__property__', '__trait__',
		'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'numeric', 'object',
		'parent', 'resource', 'self', 'string', 'true', 'void',
	];

	/**
	 * Checks that a valid PHP name (see checkIdentifier()) can be used where
	 * the generator writes it. A class name can't be a reserved word, nor
	 * ActiveRecord, which the generated class imports. The same goes for
	 * the last part of a trait name, the trait itself; ActiveRecord only
	 * without namespace. A class name can't be PHersistReferences either
	 * (ReferenceRegistry::INDEX_CLASS), which the generator writes next to
	 * the classes. A namespace may contain reserved words, but can't start
	 * with 'namespace'.
	 *
	 * @param string $name the name
	 * @param 'class'|'trait'|'namespace' $kind what the name is for
	 * @return ?string what is wrong with the name, or null if it is valid
	 */
	public static function checkReservedName(string $name, string $kind) : ?string {
		$segments = explode('\\', ltrim($name, '\\'));
		if ($kind == 'namespace') {
			if (strtolower($segments[0]) == 'namespace')
				return "'{$name}' can't start with 'namespace', which PHP reserves for relative names";
			return null;
		}

		$last = $segments[count($segments) - 1];
		if (in_array(strtolower($last), self::RESERVED_WORDS))
			return ($last == $name ? "'{$name}' is" : "'{$name}' ends in '{$last}', which is")." a reserved word in PHP, so it can't be a {$kind} name";
		if (strtolower($name) == 'activerecord')
			return "'{$name}' clashes with PHersist\\ActiveRecord, which the generated classes extend";
		if ($kind == 'class' && strtolower($name) == strtolower(ReferenceRegistry::INDEX_CLASS))
			return "'{$name}' clashes with the class that the generator writes next to the classes, with the references between them";
		return null;
	}

	/**
	 * Describes an element for an error message, like "<property> 'title' of class 'Forum'".
	 *
	 * @param DOMElement $element the element
	 * @return string the description
	 */
	private static function describe(DOMElement $element) : string {
		if ($element->tagName == 'schema')
			return $element->getAttribute('dialect') !== '' ? "<schema dialect=\"{$element->getAttribute('dialect')}\">" : '<schema>';

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
	 * Builds the model from the checked XML.
	 *
	 * @param DOMElement $root the <project> element
	 * @param string $file the path of the model XML
	 * @param list<Project> $dependencies the model XMLs it depends on
	 * @return Project the model
	 * @throws \Exception if a value is invalid
	 */
	private static function buildProject(DOMElement $root, string $file, array $dependencies) : Project {
		$schemaSettings = [];
		foreach ($root->childNodes as $child) {
			if (!$child instanceof DOMElement || $child->tagName != 'schema')
				continue;
			$settings = [];
			foreach ($child->attributes as $attribute)
				if ($attribute->namespaceURI === null && $attribute->name != 'dialect')
					$settings[$attribute->name] = $attribute->value;
			$schemaSettings[$child->getAttribute('dialect')] = $settings;
		}

		$project = new Project(
			namespace: trim($root->getAttribute('namespace'), '\\'),
			database: $root->getAttribute('database'),
			tableStyle: $root->getAttribute('tablestyle'),
			longIds: $root->getAttribute('id_style') == 'long',
			schemaSettings: $schemaSettings,
			file: $file,
			dependencies: $dependencies,
		);

		foreach ($root->getElementsByTagName('class') as $classElement)
			$project->addRecord(self::buildRecord($project, $classElement));

		return $project;
	}

	/**
	 * Builds a class of the model.
	 *
	 * @param Project $project the project the class is in
	 * @param DOMElement $element the <class> element
	 */
	private static function buildRecord(Project $project, DOMElement $element) : Record {
		$name = $element->getAttribute('name');
		$table = $element->hasAttribute('table') ? $element->getAttribute('table') : $project->translate('table', $name);

		$datasets = [];
		foreach ($element->getElementsByTagName('dataset') as $datasetElement) {
			$datasetName = null;
			if ($datasetElement->hasAttribute('name')) {
				// The name is used in column aliases, like ds_<name>#<column>
				$datasetName = $datasetElement->getAttribute('name');
				if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $datasetName))
					throw new \Exception("Dataset name '{$datasetName}' of class {$name} is invalid, it must start with a letter and contain only letters, digits and underscores");
				foreach ($datasets as $other)
					if ($other->name === $datasetName)
						throw new \Exception("Class {$name} has more than one dataset named '{$datasetName}'");
			}

			$properties = [];
			foreach ($datasetElement->getElementsByTagName('property') as $propertyElement)
				$properties[] = self::buildProperty($project, $propertyElement, $name);

			$datasets[] = new Dataset(
				name: $datasetName,
				table: $datasetElement->hasAttribute('table') ? $datasetElement->getAttribute('table') : $table,
				autoload: self::getBool($datasetElement, 'autoload'),
				properties: $properties,
				line: $datasetElement->getLineNo(),
			);
		}

		$relations = [];
		foreach ($element->getElementsByTagName('relation') as $relationElement) {
			self::checkType('Relation', $relationElement->getAttribute('type'), $name, $relationElement->getAttribute('name'));
			$relations[] = new Relation(
				name: $relationElement->getAttribute('name'),
				type: $relationElement->getAttribute('type'),
				class: $project->qualifyClass($relationElement->getAttribute('class')),
				table: $relationElement->getAttribute('table'),
				localId: $relationElement->getAttribute('local_id'),
				remoteId: $relationElement->getAttribute('remote_id'),
				localType: $relationElement->hasAttribute('local_type') ? $relationElement->getAttribute('local_type') : null,
				orderField: $relationElement->hasAttribute('order_field') ? $relationElement->getAttribute('order_field') : null,
				tableOwner: self::getBool($relationElement, 'table_owner'),
				loadObjects: self::getBool($relationElement, 'load_objects'),
				cascadeDelete: self::getBool($relationElement, 'cascade_delete'),
				useNamespace: self::getBool($relationElement, 'use_namespace'),
				line: $relationElement->getLineNo(),
			);
		}

		$maps = [];
		foreach ($element->getElementsByTagName('map') as $mapElement) {
			$mapName = $mapElement->getAttribute('name');
			$keys = [];
			foreach ($mapElement->getElementsByTagName('key') as $key)
				$keys[] = $key->getAttribute('name');
			if (!$keys)
				throw new \Exception("Map {$mapName} of class {$name} must have at least one <key>");
			$values = $mapElement->getElementsByTagName('value');
			if ($values->length != 1)
				throw new \Exception("Map {$mapName} of class {$name} must have exactly one <value>, found {$values->length}");

			$maps[] = new Map(
				name: $mapName,
				table: $mapElement->getAttribute('table'),
				// The owner id column defaults to the long id style of the class
				// name, like a relation column
				idColumn: $mapElement->hasAttribute('id') ? $mapElement->getAttribute('id') : $project->translate('relation_id', $name),
				typeColumn: $mapElement->hasAttribute('type') ? $mapElement->getAttribute('type') : null,
				keys: $keys,
				value: $values->item(0)->getAttribute('name'),
				useNamespace: self::getBool($mapElement, 'use_namespace'),
				line: $mapElement->getLineNo(),
			);
		}

		return new Record(
			project: $project,
			name: $name,
			idColumn: $element->hasAttribute('id') ? $element->getAttribute('id') : $project->translate('id', $name),
			table: $table,
			database: $element->hasAttribute('database') ? $element->getAttribute('database') : $project->database,
			softdelete: self::getBool($element, 'softdelete'),
			trait: $element->hasAttribute('trait') ? $element->getAttribute('trait') : null,
			datasets: $datasets,
			relations: $relations,
			maps: $maps,
			line: $element->getLineNo(),
		);
	}

	/**
	 * Builds a property of the model.
	 *
	 * @param Project $project the project the property is in
	 * @param DOMElement $element the <property> element
	 * @param string $className the name of the class the property is in
	 */
	private static function buildProperty(Project $project, DOMElement $element, string $className) : Property {
		$name = $element->getAttribute('name');
		$type = $element->hasAttribute('type') ? $element->getAttribute('type') : 'Text';
		self::checkType('Property', $type, $className, $name);

		$precision = $scale = null;
		if ($type == 'Decimal')
			[$precision, $scale] = self::getDecimalSize($element);

		$updateOn = null;
		if ($element->hasAttribute('update_on')) {
			$updateOn = $element->getAttribute('update_on');
			if ($updateOn != 'create' && $updateOn != 'modify')
				throw new \Exception("Invalid update_on '{$updateOn}' for property '{$name}', use 'create' or 'modify'");
		}

		$required = self::getBool($element, 'required');

		// What happens to the reference when the referred object is deleted; by
		// default it's refused for a required property, and set to null otherwise
		$onRemoteDelete = null;
		if ($type == 'Class' || $type == 'DynamicClass') {
			$onRemoteDelete = $element->hasAttribute('on_remote_delete') ? $element->getAttribute('on_remote_delete') : ($required ? 'restrict' : 'null');
			if (!in_array($onRemoteDelete, ['null', 'restrict', 'cascade']))
				throw new \Exception("Invalid on_remote_delete '{$onRemoteDelete}' for property '{$name}', use 'null', 'restrict' or 'cascade'");
			if ($onRemoteDelete == 'null' && $required)
				throw new \Exception("Property '{$name}' can't have on_remote_delete=\"null\", because it is required");
		}

		return new Property(
			name: $name,
			type: $type,
			required: $required,
			columns: self::getFieldNames($project, $element, $type),
			default: self::getDefault($element, $type, $precision, $scale),
			signed: self::getBool($element, 'signed', true),
			precision: $precision,
			scale: $scale,
			updateOn: $updateOn,
			dateFormat: $element->hasAttribute('date_format') ? $element->getAttribute('date_format') : null,
			class: $type == 'Class' ? $project->qualifyClass($element->getAttribute('class')) : null,
			onRemoteDelete: $onRemoteDelete,
			useNamespace: self::getBool($element, 'use_namespace'),
			line: $element->getLineNo(),
		);
	}

	/**
	 * Checks that a property or relation type exists, so a typo fails here
	 * instead of when the generated class is used.
	 *
	 * @param string $kind 'Property' or 'Relation'
	 * @param string $type the type from the XML, like 'Int' or 'NN'
	 * @param string $className the class the property or relation is on
	 * @param string $name the name of the property or relation
	 */
	private static function checkType(string $kind, string $type, string $className, string $name) : void {
		$base = "PHersist\\Types\\AR{$kind}Type";
		$typeClass = $base.$type;
		// class names are case-insensitive in PHP, but the type names aren't
		if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type) || !is_subclass_of($typeClass, $base)
				|| (new \ReflectionClass($typeClass))->getName() !== $typeClass)
			throw new \Exception(ucfirst(strtolower($kind))." '$name' of class $className has an unknown type '$type'");
	}

	/**
	 * Reads a boolean attribute. checkElements() has checked that its value is
	 * 'true' or 'false' (see ATTRIBUTE_VALUES).
	 *
	 * @param DOMElement $element the element in the XML tree
	 * @param string $name the attribute name
	 * @param bool $default the value if the attribute is not set
	 * @return bool the value of the attribute
	 */
	private static function getBool(DOMElement $element, string $name, bool $default = false) : bool {
		return $element->hasAttribute($name) ? $element->getAttribute($name) === 'true' : $default;
	}

	/**
	 * Reads the precision and scale for a Decimal property.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @return array{int, int} the precision and scale
	 */
	private static function getDecimalSize(DOMElement $property) : array {
		$precision = $property->hasAttribute('precision') ? $property->getAttribute('precision') : (string)ARPropertyTypeDecimal::DEFAULT_PRECISION;
		$scale = $property->hasAttribute('scale') ? $property->getAttribute('scale') : (string)ARPropertyTypeDecimal::DEFAULT_SCALE;
		try {
			return ARPropertyTypeDecimal::parseSize($precision, $scale);
		} catch (\InvalidArgumentException $e) {
			throw new \Exception("Invalid Decimal property '{$property->getAttribute('name')}': {$e->getMessage()}", 0, $e);
		}
	}

	/**
	 * Reads and validates the default attribute of a property. Only Text, Int,
	 * Float, Decimal and Bool properties can have one.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @param string $type the property type
	 * @param ?int $precision for Decimal: the precision
	 * @param ?int $scale for Decimal: the scale
	 * @return string|int|float|bool|null the default value, or null if there is none
	 * @throws \Exception if the default is not a valid value for the type
	 */
	private static function getDefault(DOMElement $property, string $type, ?int $precision, ?int $scale) : string|int|float|bool|null {
		if (!$property->hasAttribute('default'))
			return null;
		$default = $property->getAttribute('default');

		try {
			if ($type == 'Text')
				return $default;

			if ($type == 'Int')
				return (new ARPropertyTypeInt())->normalize([ 'signed' => self::getBool($property, 'signed', true) ], $default);

			if ($type == 'Float')
				return (new ARPropertyTypeFloat())->normalize([], $default);

			if ($type == 'Decimal' && $precision !== null && $scale !== null)
				return ARPropertyTypeDecimal::normalizeValue($default, $precision, $scale);

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
	 * @param Project $project the project the property is in
	 * @param DOMElement $property the property element in the XML tree
	 * @param string $type the property type
	 * @return list<string> the column names; two for a DynamicClass (class name and id), one otherwise
	 * @throws \Exception if both attributes are set, or a name is empty or invalid, or the number of names is wrong for the type
	 */
	private static function getFieldNames(Project $project, DOMElement $property, string $type) : array {
		$name = $property->getAttribute('name');
		$expected = $type == 'DynamicClass' ? 2 : 1;

		if ($property->hasAttribute('fieldname') && $property->hasAttribute('fieldnames'))
			throw new \Exception("Property '{$name}' has both a fieldname and a fieldnames attribute, use only one");

		if ($property->hasAttribute('fieldname'))
			$fieldNames = [ $property->getAttribute('fieldname') ];
		elseif ($property->hasAttribute('fieldnames'))
			$fieldNames = explode(',', $property->getAttribute('fieldnames'));
		else {
			if ($type == 'Class')
				return [ $project->translate('relation_id', $name) ];
			if ($type == 'DynamicClass')
				return explode(',', $project->translate('relation_combo', $name));
			return [ $project->translate('fieldname', $name) ];
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
}
