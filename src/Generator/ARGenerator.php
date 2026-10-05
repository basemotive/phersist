<?php

namespace PHersist\Generator;

use DOMElement;
use PHersist\Types\ARPropertyTypeDecimal;

/**
 * Generates ActiveRecord instances.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARGenerator {
	public function __construct(string $xml) {
		$this->root = XMLLoader::load($xml);
	}

	public function getNamespace() : string {
		$namespace = $this->root->hasAttribute('namespace') ? trim($this->root->getAttribute('namespace'), '\\') : '';
		// Without a namespace, class names must not get a leading backslash
		return $namespace == '' ? '' : $namespace.'\\';
	}

	/**
	 * Generates the code for all the classes in the XML.
	 *
	 * @return array<string, string> the code in the format [ $className => $classCode, ... ]
	 */
	public function generate() : array {
		$result = [];

		$classElements = $this->root->getElementsByTagName('class');
		foreach ($classElements as $classElement)
			$result[$classElement->getAttribute('name')] = $this->generateClass($classElement);

		return $result;
	}

	/**
	 * Generates the code for a single named class from the XML.
	 *
	 * @param string $className the simple (unqualified) class name to generate
	 * @return string the generated PHP code, or an empty string if not found
	 */
	public function generateForClass(string $className) : string {
		$classElements = $this->root->getElementsByTagName('class');
		foreach ($classElements as $classElement)
			if ($classElement->getAttribute('name') === $className)
				return $this->generateClass($classElement);
		return '';
	}

	private function generateClass(DOMElement $classElement) : string {
		$className = $classElement->getAttribute('name');
		$meta = $this->generateMeta($classElement);
		$namespace = rtrim($this->getNamespace(), '\\');

		$txt = "<?php\n\n";

		if ($namespace != '')
			$txt .= "namespace {$namespace};\n\n";

		$txt .= "use PHersist\ActiveRecord;\n";
		$txt .= "\n";

		$txt  .= $this->generateDocs($classElement);
		$txt .= "class $className extends ActiveRecord {\n";

		// If a Trait exists for this class, use it
		if ($classElement->hasAttribute('trait')) {
			$txt .= "\tuse {$classElement->getAttribute('trait')};\n\n";
		} elseif (trait_exists("{$this->getNamespace()}{$className}Trait")) {
			$txt .= "\tuse {$className}Trait;\n\n";
		}

		// Write the $_meta variable that holds the information the ActiveRecord
		// needs to function
		$txt .= "\t/** @var ?array<string, mixed> \$_meta */\n";
		$txt .= "\tprotected static ?array \$_meta = ".$this->exportArray($meta, 1).";\n\n";

		$txt .= "}\n";
		$txt .= "?>";

		return $txt;
	}

    /**
     * Generates a DocBlock for this class.
     *
     * The docblock helps code analyzers like PHPStan know which properties
     * this class has, since they work through the __get and __set magic
     * methods and thus cannot be directly analyzed.
     *
     * @param DOMElement $classElement
     * @return string the docblock for the given class
     */
    private function generateDocs(DOMElement $classElement) : string{
		$result = "/**\n";

		$className = $classElement->getAttribute('name');

		$result .= " * Class $className.\n";
		$result .= " *\n";

		// The id is always exposed as $id, regardless of its field name
		$idField = $classElement->hasAttribute('id') ?
			$classElement->getAttribute('id') : $this->getAuto('id', $className);
		$result .= " * @property-read ?int \$id id field: {$idField}\n";

		$datasets = $classElement->getElementsByTagName('dataset');
		if ($datasets->length >0) {
			foreach ($datasets as $dataset) {
				$properties = $dataset->getElementsByTagName('property');
				foreach ($properties as $property) {
					$prop_name = $property->getAttribute('name');
					$prop_type = $property->hasAttribute('type') ?
						$property->getAttribute('type') : 'Text';

					$phpType = 'string';
					if ($prop_type == 'Class') {
						$phpType = trim($property->getAttribute('class'), '\\');
						if (strpos($phpType, '\\') !== false)
							$phpType = "\\{$phpType}";
					} elseif ($prop_type == 'Int') {
						$phpType = 'int';
					} elseif ($prop_type == 'Bool') {
						$phpType = 'bool';
					} elseif ($prop_type == 'Float') {
						$phpType = 'float';
					} elseif ($prop_type == 'Date' || $prop_type == 'DateTime') {
						$phpType = '\\DateTimeImmutable';
					} elseif ($prop_type == 'DynamicClass') {
						$phpType = '\\PHersist\\ActiveRecord';
					}

					$required = XMLLoader::getBool($property, 'required');
					if (!$required)
						$phpType = "?{$phpType}";

					$result .= " * @property {$phpType} \${$prop_name}";

					$default = $this->getDefault($property, $prop_type);
					if ($default !== null)
						$result .= ' default '.var_export($default, true);

					if ($prop_type == 'Decimal') {
						[$precision, $scale] = $this->getDecimalSize($property);
						$result .= " decimal($precision,$scale)";
					} elseif (($prop_type == 'Date' || $prop_type == 'DateTime') && $property->hasAttribute('update_on')) {
						$result .= ' updates on '.$this->getUpdateOn($property);
					} elseif ($prop_type == 'TimestampText') {
						$result .= ' timestamp';
						if ($property->hasAttribute('update_on'))
							$result .= ', updates on '.$this->getUpdateOn($property);
						if ($property->hasAttribute('date_format'))
							$result .= ', date format: '.$property->getAttribute('date_format');
					}

					$result .= "\n";
				}
			}
		}

		$relations = $classElement->getElementsByTagName('relation');
		if ($relations->length > 0) {
			foreach ($relations as $relation) {
				$rl_name = $relation->getAttribute('name');
				$rl_class = trim($relation->getAttribute('class'), '\\');
				if (strpos($rl_class, '\\') !== false)
					$rl_class = "\\{$rl_class}";

				$result .= " * @property {$rl_class}[] \${$rl_name} relation";
				if ($relation->hasAttribute('order_field'))
					$result .= ", ordered by ".$relation->getAttribute('order_field');

				$rw = XMLLoader::getBool($relation, 'table_owner');
				$result .= ', '.($rw ? 'read-write' : 'read-only');

				$result .= "\n";
			}
		}

		// The maps
		$maps = $classElement->getElementsByTagName('map');
		foreach ($maps as $map) {
			$map_name = $map->getAttribute('name');

			$result .= " * @property \PHersist\Maps\Map \${$map_name} map\n";
		}

		$result .= " */\n";
		return $result;
	}

	/**
	 * Generates the metadata for a class.
	 *
	 * The metadata defines the properties, relations and maps for any object,
	 * and it's used to dynamically write database queries.
	 *
	 * @param DOMElement $classElement the class element in the XML tree
	 * @return array<string, mixed> the metadata
	 */
	private function generateMeta(DOMElement $classElement) : array {
		$className = $classElement->getAttribute('name');
		$id = $classElement->hasAttribute('id') ?
			$classElement->getAttribute('id') : $this->getAuto('id', $className);
		$database = $classElement->hasAttribute('database') ?
			$classElement->getAttribute('database') : $this->root->getAttribute('database');
		$table = $classElement->hasAttribute('table') ?
			$classElement->getAttribute('table') : $this->getAuto('table', $className);
		$softdelete = XMLLoader::getBool($classElement, 'softdelete');

		// The base data for the class
		$meta = [
			'id' => $id,
			'database' => $database,
			'table' => $table,
			'softdelete' => $softdelete,
			'datasets' => [],
			'relations' => [],
			'maps' => [],
		];

		// The datasets
		$datasets = $classElement->getElementsByTagName('dataset');
		foreach ($datasets as $dataset) {
			$ds_autoload = XMLLoader::getBool($dataset, 'autoload');
			$ds_table = $dataset->hasAttribute('table') ? $dataset->getAttribute('table') : $table;
			$ds_name = $dataset->hasAttribute('name') ? $this->getDatasetName($dataset, $className, $meta['datasets']) : null;

			$metads = [
				'name' => $ds_name,
				'autoload' => $ds_autoload,
				'table' => $ds_table,
				'props' => [],
			];

			// The properties within the dataset
			$properties = $dataset->getElementsByTagName('property');
			foreach ($properties as $property) {
				$prop_name = $property->getAttribute('name');
				// 'id' always refers to the object's id, so it can't be a property
				if ($prop_name == 'id')
					throw new \Exception("Class {$classElement->getAttribute('name')} can't have a property named 'id', it is reserved for the object's id");
				$prop_type = $property->hasAttribute('type') ?
					$property->getAttribute('type') : 'Text';
				$this->checkType('Property', $prop_type, $className, $prop_name);

				$metaprop = [
					'type' => $prop_type,
					'fieldnames' => XMLLoader::getFieldNames($property, $prop_type),
				];

				// Special types - TODO Can we make this more generic?
				if ($prop_type == 'Class') {
					$metaprop['class'] = $this->qualifyClass($property->getAttribute('class'));
				} elseif ($prop_type == 'Int') {
					$metaprop['signed'] = XMLLoader::isSigned($property);
				} elseif ($prop_type == 'Decimal') {
					[$metaprop['precision'], $metaprop['scale']] = $this->getDecimalSize($property);
				} elseif (($prop_type == 'Date' || $prop_type == 'DateTime') && $property->hasAttribute('update_on')) {
					$metaprop['update_on'] = $this->getUpdateOn($property);
				} elseif ($prop_type == 'TimestampText') {
					if ($property->hasAttribute('update_on'))
						$metaprop['update_on'] = $this->getUpdateOn($property);
					if ($property->hasAttribute('date_format'))
						$metaprop['date_format'] = $property->getAttribute('date_format');
				}

				// If this property is required
				$metaprop['required'] = XMLLoader::getBool($property, 'required');

				// What happens to the reference when the referred object is deleted
				if ($prop_type == 'Class' || $prop_type == 'DynamicClass')
					$metaprop['on_remote_delete'] = $this->getOnRemoteDelete($property, $metaprop['required']);
				elseif ($property->hasAttribute('on_remote_delete'))
					throw new \Exception("Property '$prop_name' of class $className can't have on_remote_delete, only Class and DynamicClass properties can");

				// Whether a DynamicClass property stores the class name with its namespace,
				// or (the default) without it if the class is in this class's namespace
				if ($prop_type == 'DynamicClass') {
					$metaprop['use_namespace'] = XMLLoader::getBool($property, 'use_namespace');
					if (!$metaprop['use_namespace'])
						$metaprop['namespace'] = rtrim($this->getNamespace(), '\\');
				} elseif ($property->hasAttribute('use_namespace'))
					throw new \Exception("Property '$prop_name' of class $className can't have use_namespace, only DynamicClass properties can");

				// The default value for new objects; mirrors the column default in the schema
				$default = $this->getDefault($property, $prop_type);
				if ($default !== null)
					$metaprop['default'] = $default;

				$metads['props'][$prop_name] = $metaprop;
			}

			$meta['datasets'][] = $metads;
		}

		// The relations
		$relations = $classElement->getElementsByTagName('relation');
		foreach ($relations as $relation) {
			$this->checkType('Relation', $relation->getAttribute('type'), $className, $relation->getAttribute('name'));
			$relationClass = $this->qualifyClass($relation->getAttribute('class'));

			$metarel = [
				'type' => $relation->getAttribute('type'),
				'class' => $relationClass,
				'table' => $relation->getAttribute('table'),
				'local_id' => $relation->getAttribute('local_id'),
				'remote_id' => $relation->getAttribute('remote_id'),
				'table_owner' => XMLLoader::getBool($relation, 'table_owner'),
				'load_objects' => XMLLoader::getBool($relation, 'load_objects'),
				'cascade_delete' => XMLLoader::getBool($relation, 'cascade_delete'),
			];

			if ($relation->hasAttribute('order_field'))
				$metarel['order_field'] = $relation->getAttribute('order_field');

			if ($relation->getAttribute('local_type') != '') {
				// relation table field that holds the class name of the local object
				$metarel['local_type'] = $relation->getAttribute('local_type');
				// use namespace of class for the local_type field (default false)
				$metarel['use_namespace'] = XMLLoader::getBool($relation, 'use_namespace');
			}

			$meta['relations'][$relation->getAttribute('name')] = $metarel;
		}

		// The maps
		$maps = $classElement->getElementsByTagName('map');
		foreach ($maps as $map) {
			$metamap = [
				'table' => $map->getAttribute('table'),
				'id' => $map->getAttribute('id') != '' ?
					$map->getAttribute('id') : $this->getAuto('relation_id', $className),
				'type' => $map->hasAttribute('type') ? $map->getAttribute('type') : false,
				'activeRecordKey' => $map->getAttribute('name'),
				'keys' => [],
				'value' => '',
				// use namespace of class for the type field (default false)
				'use_namespace' => XMLLoader::getBool($map, 'use_namespace'),
			];

			$keys = $map->getElementsByTagName('key');
			foreach ($keys as $key)
				$metamap['keys'][] = $key->getAttribute('name');

			$values = $map->getElementsByTagName('value');
			if ($values->length != 1)
				throw new \Exception("Map {$map->getAttribute('name')} of class {$classElement->getAttribute('name')} must have exactly one <value>, found {$values->length}");
			$metamap['value'] = $values->item(0)->getAttribute('name');

			$meta['maps'][$map->getAttribute('name')] = $metamap;
		}

		$meta['references'] = $this->generateReferences($classElement);

		return $meta;
	}

	/**
	 * Finds the references to a class from the classes in the XML, so they can
	 * be cleaned up when an object of the class is deleted.
	 *
	 * These are the Class properties that refer to the class, all DynamicClass
	 * properties (they may refer to any class), and the relations to the class
	 * through a join table. References that one of the class's own relations
	 * already cleans up are left out.
	 *
	 * @param DOMElement $classElement the class element in the XML tree
	 * @return list<array<string, string>> the references, each with the
	 *   referring 'class' and the 'property' or 'relation' in it
	 */
	private function generateReferences(DOMElement $classElement) : array {
		$className = $this->qualifyClass($classElement->getAttribute('name'));
		$classTables = XMLLoader::getClassTables($classElement);

		// The table and column of every reference our own relations clean up
		$covered = [];
		foreach ($classElement->getElementsByTagName('relation') as $relation)
			$covered[] = $relation->getAttribute('table').'.'.$relation->getAttribute('local_id');

		$references = [];
		foreach ($this->root->getElementsByTagName('class') as $otherElement) {
			$otherClass = $this->qualifyClass($otherElement->getAttribute('name'));
			$otherTables = XMLLoader::getClassTables($otherElement);
			$defaultTable = $otherTables[0];

			foreach ($otherElement->getElementsByTagName('dataset') as $dataset) {
				$table = $dataset->hasAttribute('table') ? $dataset->getAttribute('table') : $defaultTable;
				foreach ($dataset->getElementsByTagName('property') as $property) {
					$type = $property->getAttribute('type');
					if ($type == 'Class') {
						if ($this->qualifyClass($property->getAttribute('class')) != $className)
							continue;
						$column = XMLLoader::getFieldNames($property, $type)[0];
					} elseif ($type == 'DynamicClass') {
						$column = XMLLoader::getFieldNames($property, $type)[1];
					} else
						continue;

					if (!in_array("$table.$column", $covered))
						$references[] = ['class' => $otherClass, 'property' => $property->getAttribute('name')];
				}
			}

			foreach ($otherElement->getElementsByTagName('relation') as $relation) {
				if ($this->qualifyClass($relation->getAttribute('class')) != $className)
					continue;
				// Only a join table holds rows that are just about the relation;
				// the rows in a class's own tables are its objects
				$table = $relation->getAttribute('table');
				if (in_array($table, $classTables) || in_array($table, $otherTables))
					continue;

				if (!in_array("$table.{$relation->getAttribute('remote_id')}", $covered))
					$references[] = ['class' => $otherClass, 'relation' => $relation->getAttribute('name')];
			}
		}

		return $references;
	}

	/**
	 * Returns the fully qualified name of a class referred to in the XML.
	 *
	 * @param string $class the class name; without a namespace, the project's is used
	 * @return string the class name with namespace, without leading backslash
	 */
	private function qualifyClass(string $class) : string {
		return XMLLoader::qualifyClass($this->root, $class);
	}

	/**
	 * Reads the on_remote_delete attribute for a Class or DynamicClass property.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @param bool $required if the property is required
	 * @return string 'null', 'restrict' or 'cascade'; by default 'restrict' for
	 *   a required property and 'null' otherwise
	 */
	private function getOnRemoteDelete(DOMElement $property, bool $required) : string {
		if (!$property->hasAttribute('on_remote_delete'))
			return $required ? 'restrict' : 'null';

		$name = $property->getAttribute('name');
		$onRemoteDelete = $property->getAttribute('on_remote_delete');
		if (!in_array($onRemoteDelete, ['null', 'restrict', 'cascade']))
			throw new \Exception("Invalid on_remote_delete '$onRemoteDelete' for property '$name', use 'null', 'restrict' or 'cascade'");
		if ($onRemoteDelete == 'null' && $required)
			throw new \Exception("Property '$name' can't have on_remote_delete=\"null\", because it is required");

		return $onRemoteDelete;
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
	private function checkType(string $kind, string $type, string $className, string $name) : void {
		$base = "PHersist\\Types\\AR{$kind}Type";
		$typeClass = $base.$type;
		// class names are case-insensitive in PHP, but the type names aren't
		if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type) || !is_subclass_of($typeClass, $base)
				|| (new \ReflectionClass($typeClass))->getName() !== $typeClass)
			throw new \Exception(ucfirst(strtolower($kind))." '$name' of class $className has an unknown type '$type'");
	}

	/**
	 * Validates the name of a dataset. It is used in column aliases, like
	 * ds_<name>#<column>, so it must be an identifier, and unique in its class.
	 *
	 * @param DOMElement $dataset the dataset element
	 * @param string $className the name of the class
	 * @param list<array<string, mixed>> $datasets the class's datasets so far
	 * @return string the name
	 */
	private function getDatasetName(DOMElement $dataset, string $className, array $datasets) : string {
		$name = $dataset->getAttribute('name');
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name))
			throw new \Exception("Dataset name '$name' of class $className is invalid, it must start with a letter and contain only letters, digits and underscores");
		foreach ($datasets as $other)
			if ($other['name'] === $name)
				throw new \Exception("Class $className has more than one dataset named '$name'");
		return $name;
	}

	/**
	 * Determines the default value for a property.
	 *
	 * Only explicit 'default' attributes are used; required properties don't
	 * get an implicit default, so they must be set before committing.
	 * Only Text, Int, Float, Decimal and Bool properties support default values.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @param string $type the property type
	 * @return string|int|float|bool|null the default value, or null if there is none
	 */
	private function getDefault(DOMElement $property, string $type) : string|int|float|bool|null {
		if ($type == 'Decimal' && $property->hasAttribute('default')) {
			[$precision, $scale] = $this->getDecimalSize($property);
			try {
				return ARPropertyTypeDecimal::normalizeValue($property->getAttribute('default'), $precision, $scale);
			} catch (\InvalidArgumentException $e) {
				throw new \Exception("Invalid default for property '{$property->getAttribute('name')}': {$e->getMessage()}", 0, $e);
			}
		}

		return XMLLoader::getDefault($property, $type);
	}

	/**
	 * Reads the update_on attribute for a Date, DateTime or TimestampText property.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @return string 'create' or 'modify'
	 */
	private function getUpdateOn(DOMElement $property) : string {
		$updateOn = $property->getAttribute('update_on');
		if ($updateOn != 'create' && $updateOn != 'modify')
			throw new \Exception("Invalid update_on '{$updateOn}' for property '{$property->getAttribute('name')}', use 'create' or 'modify'");
		return $updateOn;
	}

	/**
	 * Reads the precision and scale for a Decimal property.
	 *
	 * @param DOMElement $property the property element in the XML tree
	 * @return array{int, int} the precision and scale
	 */
	private function getDecimalSize(DOMElement $property) : array {
		$precision = $property->hasAttribute('precision') ? $property->getAttribute('precision') : (string)ARPropertyTypeDecimal::DEFAULT_PRECISION;
		$scale = $property->hasAttribute('scale') ? $property->getAttribute('scale') : (string)ARPropertyTypeDecimal::DEFAULT_SCALE;
		try {
			return ARPropertyTypeDecimal::parseSize($precision, $scale);
		} catch (\InvalidArgumentException $e) {
			throw new \Exception("Invalid Decimal property '{$property->getAttribute('name')}': {$e->getMessage()}", 0, $e);
		}
	}

	/**
	 * Uses a table style converter to convert class and property names into table and column names.
	 *
	 * @param string $term what kind of term to translate: table | id | fieldname
	 * @param string $name the name to translate
	 * @return string the converted name
 	 */
	private function getAuto(string $term, string $name) : string {
		return XMLLoader::getAuto($this->root, $term, $name);
	}

	/**
	 * Exports a (nested) array as clean PHP syntax using square bracket notation
	 * with proper indentation, as an alternative to var_export().
	 *
	 * @param array<mixed> $array the array to export
	 * @param int $depth the current indentation depth (1 = inside class body)
	 * @return string the exported array as a PHP code string
	 */
	private function exportArray(array $array, int $depth = 0) : string {
		if (count($array) === 0)
			return '[]';

		$indent = str_repeat("\t", $depth);
		$innerIndent = str_repeat("\t", $depth + 1);

		$isList = array_is_list($array);

		$lines = [];
		foreach ($array as $key => $value) {
			$exportedValue = match(true) {
				is_array($value)  => $this->exportArray($value, $depth + 1),
				is_bool($value)   => ($value ? 'true' : 'false'),
				is_null($value)   => 'null',
				is_int($value)    => (string)$value,
				is_float($value)  => var_export($value, true),
				default           => "'".addcslashes((string)$value, "'\\")."'",
			};

			if ($isList)
				$lines[] = "{$innerIndent}{$exportedValue}";
			else
				$lines[] = "{$innerIndent}".(is_int($key) ? $key : "'".addcslashes($key, "'\\")."'")." => {$exportedValue}";
		}

		return "[\n".implode(",\n", $lines)."\n{$indent}]";
	}

	private DOMElement $root;
}