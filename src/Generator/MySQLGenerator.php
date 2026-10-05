<?php

namespace PHersist\Generator;

use DOMElement;
use PHersist\Types\ARPropertyTypeDecimal;
use PHersist\Types\ARPropertyTypeTimestampText;

/**
 * Generates MySQL tables.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class MySQLGenerator {
	/**
	 * The collation of map key and value columns: binary, so case-sensitive,
	 * and NO PAD, so trailing spaces count. Requires MySQL 8.0.
	 */
	protected const MAP_COLLATION = 'utf8mb4_0900_bin';

	public function __construct(string $xml) {
		$this->root = XMLLoader::load($xml);

		$this->readSettings();
	}

	/**
	 * Reads the MySQL-specific settings from the optional <mysql> element
	 * directly under the <project> root.
	 */
	private function readSettings() : void {
		$mysqlElement = null;
		foreach ($this->root->childNodes as $child) {
			if ($child instanceof DOMElement && $child->tagName == 'mysql') {
				$mysqlElement = $child;
				break;
			}
		}

		if ($mysqlElement && $mysqlElement->hasAttribute('charset')) {
			$this->charset = $mysqlElement->getAttribute('charset');
			// without an explicit collation, let MySQL use the charset's default
			$this->collate = null;
		}
		if ($mysqlElement && $mysqlElement->hasAttribute('collate'))
			$this->collate = $mysqlElement->getAttribute('collate');

		if (!preg_match('/^[A-Za-z0-9_]+$/', $this->charset))
			throw new \Exception("Invalid MySQL charset '{$this->charset}'");
		if ($this->collate !== null && !preg_match('/^[A-Za-z0-9_]+$/', $this->collate))
			throw new \Exception("Invalid MySQL collation '{$this->collate}'");
	}

	/**
	 * Generates the tables for all the classes in the XML.
	 *
	 * @return string the tables in text format
	 */
	public function generate() : string {
		$tables = [];

		$classElements = $this->root->getElementsByTagName('class');

		// A table can be shared by multiple classes (a join table that is defined
		// from both sides, or one that holds the relations of several classes), so
		// each class adds the columns that the table doesn't have yet
		foreach ($classElements as $classElement)
			foreach ($this->generateClass($classElement) as $tableName => $fields)
				$this->addFields($tables, $tableName, $fields);

		$result = '';
		foreach ($tables as $tableName => $fields) {
			$primaryKey = false;

			// Collect indexes: group fields by indexName and uniqueName
			$indexes = [];
			$uniques = [];
			foreach ($fields as $field) {
				if (isset($field['indexName'])) {
					$indexes[$field['indexName']][] = $field['fieldName'];
				}
				if (isset($field['uniqueName']))
					$uniques[$field['uniqueName']][] = $field['fieldName'];
			}

			$result .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
			$result .= "CREATE TABLE `{$tableName}` (\n";
			foreach ($fields as $field) {
				$result .= "\t`{$field['fieldName']}` {$field['fieldType']}";
				if ($field['required'])
					$result .= ' NOT NULL';
				else
					$result .= ' NULL';
				if ($field['primaryKey']) {
					// Only the base table hands out ids; other dataset tables receive them
					if (!empty($field['autoIncrement']))
						$result .= ' AUTO_INCREMENT';
					$primaryKey = $field;
				}
				if (isset($field['defaultRaw'])) {
					$result .= " DEFAULT {$field['defaultRaw']}";
				} elseif (isset($field['defaultValue'])) {
					if (is_string($field['defaultValue']))
						$result .= " DEFAULT ('".str_replace(['\\', "'"], ['\\\\', "''"], $field['defaultValue'])."')";
					elseif (is_int($field['defaultValue']))
						$result .= " DEFAULT {$field['defaultValue']}";
					elseif (is_float($field['defaultValue']))
						$result .= ' DEFAULT '.var_export($field['defaultValue'], true);
					elseif (is_bool($field['defaultValue']))
						$result .= ' DEFAULT '.($field['defaultValue'] ? 1 : 0);
				}
				$result .= ",\n";

				if ($field['primaryKey'])
					$result .= "\n";
			}

			// Emit indexes
			foreach ($indexes as $indexName => $indexFields) {
				$fieldList = implode('`, `', $indexFields);
				$result .= "\tINDEX `{$indexName}` (`{$fieldList}`),\n";
			}
			foreach ($uniques as $uniqueName => $uniqueFields) {
				$fieldList = implode('`, `', $uniqueFields);
				$result .= "\tUNIQUE INDEX `{$uniqueName}` (`{$fieldList}`),\n";
			}

			// cut the last comma if there's no primary key
			if ($primaryKey)
				$result .= "\n\tPRIMARY KEY (`{$primaryKey['fieldName']}`)\n";
			else
				$result = rtrim($result, ",\n")."\n";

			$result .= ") ENGINE=InnoDB\n";
			$result .= "  DEFAULT CHARSET={$this->charset}";
			if ($this->collate !== null)
				$result .= "\n  COLLATE={$this->collate}";
			$result .= ";\n";
			$result .= "\n";
		}

		return $result;
	}

	/**
	 * Generates the tables for a single class.
	 *
	 * @param DOMElement $classElement the XML element for the $class
	 * @return array<string, list<array<string, mixed>>> an associative array [ 'table_name' => [ PROPS ] ]
	 */
	private function generateClass(DOMElement $classElement) : array {
		$className = $classElement->getAttribute('name');
		$idField = $classElement->hasAttribute('id') ?
			$classElement->getAttribute('id') : $this->getAuto('id', $className);
		$database = $classElement->hasAttribute('database') ?
			$classElement->getAttribute('database') : $this->root->getAttribute('database');
		$table = $classElement->hasAttribute('table') ?
			$classElement->getAttribute('table') : $this->getAuto('table', $className);
		$softdelete = XMLLoader::getBool($classElement, 'softdelete');

		$result = [];

		// the base table always exists, even if no dataset stores its properties
		// there, because it hands out the ids and holds the softdelete field
		$result[$table] = [
			[
				'fieldName' => $idField,
				'fieldType' => 'INT UNSIGNED',
				'required' => true,
				'primaryKey' => true,
				'autoIncrement' => true,
			],
		];

		// process the datasets
		$datasets = $classElement->getElementsByTagName('dataset');
		foreach ($datasets as $dataset) {
			$datasetTable = $dataset->hasAttribute('table') ? $dataset->getAttribute('table') : $table;
			$autoload = XMLLoader::getBool($dataset, 'autoload');

			if (!isset($result[$datasetTable])) {
				$result[$datasetTable] = [];
				$result[$datasetTable][] = [
					'fieldName' => $idField,
					'fieldType' => 'INT UNSIGNED',
					'required' => true,
					'primaryKey' => true,
				];
			}

			// process the properties within the dataset
			$properties = $dataset->getElementsByTagName('property');
			foreach ($properties as $property) {
				$propName = $property->getAttribute('name');
				// 'id' always refers to the object's id, so it can't be a property
				if ($propName == 'id')
					throw new \Exception("Class {$classElement->getAttribute('name')} can't have a property named 'id', it is reserved for the object's id");
				$propNameTS = $this->getAuto('fieldname', $propName);
				$propType = $property->hasAttribute('type') ? $property->getAttribute('type') : 'Text';
				$this->checkType('Property', $propType, $className, $propName);
				$required = XMLLoader::getBool($property, 'required');

				$fieldNames = XMLLoader::getFieldNames($property, $propType);

				if ($propType == 'Text') {
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'TEXT',
						'required' => $required,
						'primaryKey' => false,
					];
					if ($property->hasAttribute('default'))
						$fieldSpec['defaultValue'] = XMLLoader::getDefault($property, $propType);
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'Int') {
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'INT' . (XMLLoader::isSigned($property) ? '' : ' UNSIGNED'),
						'required' => $required,
						'primaryKey' => false,
					];
					if ($property->hasAttribute('default'))
						$fieldSpec['defaultValue'] = XMLLoader::getDefault($property, $propType);
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'Float') {
					// DOUBLE matches the precision of PHP floats
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'DOUBLE',
						'required' => $required,
						'primaryKey' => false,
					];
					if ($property->hasAttribute('default'))
						$fieldSpec['defaultValue'] = XMLLoader::getDefault($property, $propType);
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'Decimal') {
					$precision = $property->hasAttribute('precision') ? $property->getAttribute('precision') : (string)ARPropertyTypeDecimal::DEFAULT_PRECISION;
					$scale = $property->hasAttribute('scale') ? $property->getAttribute('scale') : (string)ARPropertyTypeDecimal::DEFAULT_SCALE;
					try {
						[$precision, $scale] = ARPropertyTypeDecimal::parseSize($precision, $scale);
						$fieldSpec = [
							'fieldName' => $fieldNames[0],
							'fieldType' => "DECIMAL($precision,$scale)",
							'required' => $required,
							'primaryKey' => false,
						];
						// The value is validated, so it's safe to use as a numeric literal
						if ($property->hasAttribute('default'))
							$fieldSpec['defaultRaw'] = ARPropertyTypeDecimal::normalizeValue($property->getAttribute('default'), $precision, $scale);
					} catch (\InvalidArgumentException $e) {
						throw new \Exception("Invalid Decimal property '{$propName}': {$e->getMessage()}", 0, $e);
					}
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'Date' || $propType == 'DateTime') {
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => $propType == 'Date' ? 'DATE' : 'DATETIME',
						'required' => $required,
						'primaryKey' => false,
					];
				} elseif ($propType == 'Bool') {
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'TINYINT UNSIGNED',
						'required' => $required,
						'primaryKey' => false,
					];
					if ($property->hasAttribute('default'))
						$fieldSpec['defaultValue'] = XMLLoader::getDefault($property, $propType);
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'Class') {
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'INT UNSIGNED',
						'required' => $required,
						'primaryKey' => false,
						'indexName' => 'idx_' . $propNameTS,
					];
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'DynamicClass') {
					// the class name is indexed, which MySQL doesn't allow on a TEXT
					// column without a key length
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'VARCHAR(191)',
						'required' => $required,
						'primaryKey' => false,
						'indexName' => 'idx_' . $propNameTS,
					];
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[1],
						'fieldType' => 'INT UNSIGNED',
						'required' => $required,
						'primaryKey' => false,
						'indexName' => 'idx_' . $propNameTS,
					];
				} elseif ($propType == 'TimestampText') {
					// a custom date format doesn't fit a DATETIME column, so store it as text
					$customFormat = $property->hasAttribute('date_format')
						&& $property->getAttribute('date_format') != ARPropertyTypeTimestampText::DEFAULT_DATE_FORMAT;
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => $customFormat ? 'TEXT' : 'DATETIME',
						'required' => $required,
						'primaryKey' => false,
					];
				} else {
					// a type class without a column definition here
					throw new \Exception("Property '$propName' of class $className has type '$propType', which has no MySQL column type");
				}
			}
		}

		// add a deleted field for softdelete; it belongs in the base table,
		// because that's where ActiveRecord and ObjectFinder look for it
		if ($softdelete) {
			$result[$table][] = [
				'fieldName' => 'deleted',
				'fieldType' => 'INT UNSIGNED',
				'required' => true,
				'primaryKey' => false,
				'defaultValue' => 0,
			];
		}

		// Process the relations
		$relations = $classElement->getElementsByTagName('relation');
		foreach ($relations as $relation) {
			$this->checkType('Relation', $relation->getAttribute('type'), $className, $relation->getAttribute('name'));
			$tableName = $relation->getAttribute('table');
			$localID = $relation->getAttribute('local_id');
			$remoteID = $relation->getAttribute('remote_id');
			$tableOwner = XMLLoader::getBool($relation, 'table_owner');

			// only create the table if we're the table owner, because if it's a
			// derived relation, it references another table that we don't write to
			// (XMLLoader makes sure an owned table never holds a class)
			if (!$tableOwner)
				continue;

			$fields = [
				[
					'fieldName' => $localID,
					'fieldType' => 'INT UNSIGNED',
					'required' => true,
					'primaryKey' => false,
					'indexName' => 'idx_' . $localID,
				],
			];

			// the class name of the local object, for a table that holds the
			// relations of several classes; without it, the local id is all we need
			if ($relation->getAttribute('local_type') != '') {
				$fields[] = [
					'fieldName' => $relation->getAttribute('local_type'),
					'fieldType' => 'VARCHAR(191)',
					'required' => true,
					'primaryKey' => false,
					'indexName' => 'idx_' . $localID,
				];
			}

			$fields[] = [
				'fieldName' => $remoteID,
				'fieldType' => 'INT UNSIGNED',
				'required' => true,
				'primaryKey' => false,
				'indexName' => 'idx_' . $remoteID,
			];

			if ($relation->hasAttribute('order_field')) {
				$fields[] = [
					'fieldName' => $relation->getAttribute('order_field'),
					'fieldType' => 'INT UNSIGNED',
					'required' => true,
					'primaryKey' => false,
				];
			}

			// the table may already exist from another relation in this class, in
			// which case we only add what's missing
			$this->addFields($result, $tableName, $fields);
		}

		$maps = $classElement->getElementsByTagName('map');
		foreach ($maps as $map) {
			$tableName = $map->getAttribute('table');
			// the owner id column defaults to the long id style of the class name,
			// like a relation column
			$idField = $map->getAttribute('id') != '' ?
				$map->getAttribute('id') : $this->getAuto('relation_id', $className);
			$objectTypeField = $map->hasAttribute('type') ? $map->getAttribute('type') : false;

			$fields = [];

			$keyElements = $map->getElementsByTagName('key');

			// The owner and the keys together identify a value, so they form a
			// unique index, which also serves to look up a map by its owner. An
			// InnoDB index fits 4 VARCHAR(191) columns (at 4 bytes per character);
			// a map with more gets a plain index on just the owner.
			$ownerIndex = ['indexName' => 'idx_' . $idField];
			$keyIndex = [];
			if ($keyElements->length + ($objectTypeField ? 1 : 0) <= 4)
				$ownerIndex = $keyIndex = ['uniqueName' => 'uniq_' . $idField];

			if ($objectTypeField) {
				$fields[] = [
					'fieldName' => $objectTypeField,
					'fieldType' => 'VARCHAR(191)',
					'required' => true,
					'primaryKey' => false,
				] + $ownerIndex;
			}

			$fields[] = [
				'fieldName' => $idField,
				'fieldType' => 'INT UNSIGNED',
				'required' => true,
				'primaryKey' => false,
			] + $ownerIndex;

			// Keys use a binary NO PAD collation, so that they are as distinct in
			// the database as they are in a PHP array ('Theme' is not 'theme',
			// 'a' is not 'a '). Map keys and values are always UTF-8, whatever
			// the charset of the table.
			foreach ($keyElements as $keyElement)
				$fields[] = [
					'fieldName' => $keyElement->getAttribute('name'),
					'fieldType' => 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE ' . static::MAP_COLLATION,
					'required' => true,
					'primaryKey' => false,
				] + $keyIndex;

			$valueElements = $map->getElementsByTagName('value');
			if ($valueElements->length != 1)
				throw new \Exception("Map {$map->getAttribute('name')} of class {$classElement->getAttribute('name')} must have exactly one <value>, found {$valueElements->length}");
			foreach ($valueElements as $valueElement)
			$fields[] = [
				'fieldName' => $valueElement->getAttribute('name'),
				'fieldType' => 'TEXT CHARACTER SET utf8mb4 COLLATE ' . static::MAP_COLLATION,
				'required' => true,
				'primaryKey' => false,
			];

			// XMLLoader only lets maps of different classes share a table, with
			// the same columns
			$this->addFields($result, $tableName, $fields);
		}

		return $result;
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
	 * Adds fields to a table, creating the table if needed. Fields that the
	 * table already has (by name) are left as they are.
	 *
	 * @param array<string, list<array<string, mixed>>> $tables the tables so far: [ 'table_name' => [ PROPS ] ]
	 * @param string $tableName the table to add the fields to
	 * @param list<array<string, mixed>> $fields the fields to add
	 */
	private function addFields(array &$tables, string $tableName, array $fields) : void {
		if (!isset($tables[$tableName]))
			$tables[$tableName] = [];

		$existing = array_column($tables[$tableName], 'fieldName');
		foreach ($fields as $field) {
			if (in_array($field['fieldName'], $existing))
				continue;
			$tables[$tableName][] = $field;
			$existing[] = $field['fieldName'];
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

	private DOMElement $root;
	private string $charset = 'utf8mb4';
	private ?string $collate = 'utf8mb4_unicode_ci';
}