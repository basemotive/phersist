<?php

namespace PHersist\Generator;

use PHersist\Generator\Model\Project;
use PHersist\Generator\Model\Record;
use PHersist\ReferenceRegistry;

/**
 * Generates ActiveRecord instances.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARGenerator {
	/**
	 * @param Project $project the model to generate the classes for
	 */
	public function __construct(Project $project) {
		$this->project = $project;
	}

	/**
	 * Generates the code for all the classes in the model, and the class with
	 * their references (see generateReferences()).
	 *
	 * @return array<string, string> the code in the format [ $className => $classCode, ... ]
	 */
	public function generate() : array {
		$result = [];
		foreach ($this->project->records() as $record)
			$result[$record->name] = $this->generateClass($record);
		$result[ReferenceRegistry::INDEX_CLASS] = $this->generateReferences();
		return $result;
	}

	/**
	 * Generates the code for a single class.
	 *
	 * @param Record $record the class
	 * @return string the generated PHP code
	 */
	public function generateClass(Record $record) : string {
		$meta = $this->generateMeta($record);
		$namespace = $this->project->namespace;

		$txt = "<?php\n\n";

		if ($namespace != '')
			$txt .= "namespace {$namespace};\n\n";

		$txt .= "use PHersist\ActiveRecord;\n";
		$txt .= "\n";

		$txt .= $this->generateDocs($record);
		$txt .= "class {$record->name} extends ActiveRecord {\n";

		// If a Trait exists for this class, use it
		if ($record->trait !== null) {
			$txt .= "\tuse {$record->trait};\n\n";
		} elseif (trait_exists("{$record->qualifiedName()}Trait")) {
			$txt .= "\tuse {$record->name}Trait;\n\n";
		}

		// Write the $_meta variable that holds the information the ActiveRecord
		// needs to function
		$txt .= "\t/** @var ?array<string, mixed> \$_meta */\n";
		$txt .= "\tprotected static ?array \$_meta = ".$this->exportArray($meta, 1).";\n\n";

		$txt .= "}\n";

		return $txt;
	}

	/**
	 * Generates a DocBlock for a class.
	 *
	 * The docblock helps code analyzers like PHPStan know which properties
	 * this class has, since they work through the __get and __set magic
	 * methods and thus cannot be directly analyzed.
	 *
	 * @param Record $record the class
	 * @return string the docblock for the given class
	 */
	private function generateDocs(Record $record) : string {
		$result = "/**\n";

		$result .= " * Class {$record->name}.\n";
		$result .= " *\n";

		// The id is always exposed as $id, regardless of its field name
		$result .= " * @property-read ?int \$id id field: {$record->idColumn}\n";

		foreach ($record->properties() as $property) {
			$phpType = match ($property->type) {
				'Class' => $this->phpClass((string)$property->class),
				'Int' => 'int',
				'Bool' => 'bool',
				'Float' => 'float',
				'Date', 'DateTime' => '\\DateTimeImmutable',
				'DynamicClass' => '\\PHersist\\ActiveRecord',
				default => 'string',
			};
			if (!$property->required)
				$phpType = "?{$phpType}";

			$result .= " * @property {$phpType} \${$property->name}";

			if ($property->default !== null)
				$result .= ' default '.var_export($property->default, true);

			if ($property->type == 'Decimal') {
				$result .= " decimal({$property->precision},{$property->scale})";
			} elseif (($property->type == 'Date' || $property->type == 'DateTime') && $property->updateOn !== null) {
				$result .= ' updates on '.$property->updateOn;
			} elseif ($property->type == 'TimestampText') {
				$result .= ' timestamp';
				if ($property->updateOn !== null)
					$result .= ', updates on '.$property->updateOn;
				if ($property->dateFormat !== null)
					$result .= ', date format: '.$property->dateFormat;
			}

			$result .= "\n";
		}

		foreach ($record->relations as $relation) {
			$result .= " * @property {$this->phpClass($relation->class)}[] \${$relation->name} relation";
			if ($relation->orderField !== null)
				$result .= ", ordered by {$relation->orderField}";
			$result .= ', '.($relation->tableOwner ? 'read-write' : 'read-only');
			$result .= "\n";
		}

		foreach ($record->maps as $map)
			$result .= " * @property \\PHersist\\Maps\\Map \${$map->name} map\n";

		// Values like defaults, date formats and order fields come straight
		// from the XML; make sure a "*/" in them doesn't end the comment early
		$result = str_replace('*/', '*\\/', $result);

		$result .= " */\n";
		return $result;
	}

	/**
	 * Returns how the generated code refers to a class: without namespace if
	 * it's directly in the project's namespace, and fully qualified otherwise.
	 *
	 * @param string $class the class name with namespace, without leading backslash
	 */
	private function phpClass(string $class) : string {
		$class = $this->project->relativeClass($class);
		return strpos($class, '\\') !== false ? "\\{$class}" : $class;
	}

	/**
	 * Generates the metadata for a class.
	 *
	 * The metadata defines the properties, relations and maps for any object,
	 * and it's used to dynamically write database queries.
	 *
	 * @param Record $record the class
	 * @return array<string, mixed> the metadata
	 */
	private function generateMeta(Record $record) : array {
		// The base data for the class
		$meta = [
			'id' => $record->idColumn,
			'database' => $record->database,
			'table' => $record->table,
			'softdelete' => $record->softdelete,
			'datasets' => [],
			'relations' => [],
			'maps' => [],
		];

		foreach ($record->datasets as $dataset) {
			$metads = [
				'name' => $dataset->name,
				'autoload' => $dataset->autoload,
				'table' => $dataset->table,
				'props' => [],
			];

			foreach ($dataset->properties as $property) {
				$metaprop = [
					'type' => $property->type,
					'fieldnames' => $property->columns,
				];

				// Special types
				if ($property->type == 'Class') {
					$metaprop['class'] = $property->class;
				} elseif ($property->type == 'Int') {
					$metaprop['signed'] = $property->signed;
				} elseif ($property->type == 'Decimal') {
					$metaprop['precision'] = $property->precision;
					$metaprop['scale'] = $property->scale;
				} elseif (($property->type == 'Date' || $property->type == 'DateTime') && $property->updateOn !== null) {
					$metaprop['update_on'] = $property->updateOn;
				} elseif ($property->type == 'TimestampText') {
					if ($property->updateOn !== null)
						$metaprop['update_on'] = $property->updateOn;
					if ($property->dateFormat !== null)
						$metaprop['date_format'] = $property->dateFormat;
				}

				$metaprop['required'] = $property->required;

				// What happens to the reference when the referred object is deleted
				if ($property->isReference())
					$metaprop['on_remote_delete'] = $property->onRemoteDelete;

				// Whether a DynamicClass property stores the class name with its namespace,
				// or (the default) without it if the class is in this class's namespace
				if ($property->type == 'DynamicClass') {
					$metaprop['use_namespace'] = $property->useNamespace;
					if (!$property->useNamespace)
						$metaprop['namespace'] = $this->project->namespace;
				}

				// The default value for new objects; mirrors the column default in the schema
				if ($property->default !== null)
					$metaprop['default'] = $property->default;

				$metads['props'][$property->name] = $metaprop;
			}

			$meta['datasets'][] = $metads;
		}

		foreach ($record->relations as $relation) {
			$metarel = [
				'type' => $relation->type,
				'class' => $relation->class,
				'table' => $relation->table,
				'local_id' => $relation->localId,
				'remote_id' => $relation->remoteId,
				'table_owner' => $relation->tableOwner,
				'load_objects' => $relation->loadObjects,
				'cascade_delete' => $relation->cascadeDelete,
			];

			if ($relation->orderField !== null)
				$metarel['order_field'] = $relation->orderField;

			if ($relation->localType !== null) {
				// relation table field that holds the class name of the local object
				$metarel['local_type'] = $relation->localType;
				// use namespace of class for the local_type field (default false)
				$metarel['use_namespace'] = $relation->useNamespace;
			}

			$meta['relations'][$relation->name] = $metarel;
		}

		foreach ($record->maps as $map) {
			$meta['maps'][$map->name] = [
				'table' => $map->table,
				'id' => $map->idColumn,
				'type' => $map->typeColumn ?? false,
				'activeRecordKey' => $map->name,
				'keys' => $map->keys,
				'value' => $map->value,
				// use namespace of class for the type field (default false)
				'use_namespace' => $map->useNamespace,
			];
		}

		return $meta;
	}

	/**
	 * Generates the PHersistReferences class, with the references from the
	 * classes of the model to objects, for ReferenceCleaner. They're keyed by
	 * the class they refer to, or '*' for those that can refer to any class
	 * (DynamicClass properties). A reference has what's needed to clean it up
	 * without loading the referring class.
	 *
	 * @return string the generated PHP code
	 */
	public function generateReferences() : string {
		$index = [];
		foreach ($this->project->references() as $reference) {
			$record = $reference->record;
			$entry = [ 'class' => $record->qualifiedName() ];
			if ($reference->property !== null) {
				$property = $reference->property;
				$entry += [
					'property' => $property->name,
					'database' => $record->database,
					'table' => $reference->table,
					'type' => $property->type,
					'fieldnames' => $property->columns,
					'policy' => $property->onRemoteDelete,
					// For on_remote_delete="cascade", to find the objects to delete
					'id' => $record->idColumn,
					'base_table' => $record->table,
					'softdelete' => $record->softdelete,
				];
				// How the DynamicClass property stores the class name
				if ($property->type == 'DynamicClass') {
					$entry['use_namespace'] = $property->useNamespace;
					if (!$property->useNamespace)
						$entry['namespace'] = $this->project->namespace;
				}
			} elseif ($reference->relation !== null) {
				$relation = $reference->relation;
				$entry += [
					'relation' => $relation->name,
					'database' => $record->database,
					'table' => $relation->table,
					'remote_id' => $relation->remoteId,
					// The local_type column, and the value of the referring class in it
					'local_type' => $relation->localType,
					'local_type_value' => $relation->localType === null ? null
						: ($relation->useNamespace ? $record->qualifiedName() : $record->name),
				];
			}
			$index[$reference->target ?? '*'][] = $entry;
		}

		$txt = "<?php\n\n";
		if ($this->project->namespace != '')
			$txt .= "namespace {$this->project->namespace};\n\n";

		$txt .= "/**\n";
		$txt .= " * The references from the classes in this namespace to objects, keyed by\n";
		$txt .= " * the class they refer to; for PHersist\\ReferenceCleaner.\n";
		$txt .= " * Generated by PHersist.\n";
		$txt .= " */\n";
		$txt .= "final class ".ReferenceRegistry::INDEX_CLASS." {\n";
		$txt .= "\t/** @var array<string, list<array<string, mixed>>> */\n";
		$txt .= "\tpublic const REFERENCES = ".$this->exportArray($index, 1).";\n";
		$txt .= "}\n";

		return $txt;
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

	private Project $project;
}