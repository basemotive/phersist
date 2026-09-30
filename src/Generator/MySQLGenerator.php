<?php

namespace PHersist\Generator;

use DOMDocument;
use DOMElement;
use PHersist\Types\ARPropertyTypeDecimal;

/**
 * Generates MySQL tables.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class MySQLGenerator {
	public function __construct(string $xml) {
		$this->doc = new DOMDocument();
		$this->doc->loadXML($xml);

		$this->root = $this->doc->documentElement;

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
			die("ERROR: Invalid MySQL charset '{$this->charset}'\n");
		if ($this->collate !== null && !preg_match('/^[A-Za-z0-9_]+$/', $this->collate))
			die("ERROR: Invalid MySQL collation '{$this->collate}'\n");
	}

	/**
	 * Generates the tables for all the classes in the XML.
	 *
	 * @return string the tables in text format
	 */
	public function generate() : string {
		$tables = [];

		$classElements = $this->root->getElementsByTagName('class');
		foreach ($classElements as $classElement)
			$tables = array_merge($tables, $this->generateClass($classElement));

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
		$softdelete = $classElement->hasAttribute('softdelete') && $classElement->getAttribute('softdelete')=='true';

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
			$autoload = $dataset->hasAttribute('autoload') && $dataset->getAttribute('autoload') == 'true';

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
				$required = $property->hasAttribute('required') && $property->getAttribute('required') == 'true';

				$fieldNames = null;
				if ($property->hasAttribute('fieldname')) {
					$fieldNames = [ $property->getAttribute('fieldname') ];
				} elseif ($property->hasAttribute('fieldnames')) {
					$fieldNames = explode(',', $property->getAttribute('fieldnames'));
				}

				if ($fieldNames == null) {
					if ($propType == 'Class') {
						$fieldNames = [ $this->getAuto('relation_id', $propName) ];
					} elseif ($propType == 'DynamicClass') {
						$fieldNames = explode(',', $this->getAuto('relation_combo', $propName));
					} else {
						$fieldNames = [ $this->getAuto('fieldname', $propName) ];
					}
				}

				if ($propType == 'Text') {
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'TEXT',
						'required' => $required,
						'primaryKey' => false,
					];
					if ($property->hasAttribute('default'))
						$fieldSpec['defaultValue'] = $property->getAttribute('default');
					$result[$datasetTable][] = $fieldSpec;
				} elseif ($propType == 'Int') {
					// signed ints by default
					$signed = !$property->hasAttribute('signed') || $property->getAttribute('signed') == 'true';
					$fieldSpec = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'INT' . ($signed ? '' : ' UNSIGNED'),
						'required' => $required,
						'primaryKey' => false,
					];
					if ($property->hasAttribute('default'))
						$fieldSpec['defaultValue'] = intval($property->getAttribute('default'));
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
						$fieldSpec['defaultValue'] = floatval($property->getAttribute('default'));
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
						die("ERROR: Invalid Decimal property '{$propName}': {$e->getMessage()}\n");
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
						$fieldSpec['defaultValue'] = $property->getAttribute('default') == 'true';
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
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'TEXT',
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
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'DATETIME',
						'required' => $required,
						'primaryKey' => false,
					];
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
			$tableName = $relation->getAttribute('table');
			$localID = $relation->getAttribute('local_id');
			$remoteID = $relation->getAttribute('remote_id');
			$tableOwner = $relation->getAttribute('table_owner') == 'true';

			$localTypeTS = $this->getAuto('table', $className);
			$remoteTypeTS = $this->getAuto('table', $relation->getAttribute('class'));

			// only create table if it doesn't exist yet, because it may have been
			// already created from the reverse relation in another class
			// also, only create tables if we're the table owner, because if it's
			// a derived property, it may reference another class's base table
			if ($tableOwner && !isset($result[$tableName])) {
				$result[$tableName] = [
					[
						'fieldName' => $localID,
						'fieldType' => 'INT UNSIGNED',
						'required' => true,
						'primaryKey' => false,
					],
					[
						'fieldName' => $remoteID,
						'fieldType' => 'INT UNSIGNED',
						'required' => true,
						'primaryKey' => false,
					],
				];

				if ($relation->hasAttribute('order_field')) {
					$result[$tableName][] = [
						'fieldName' => $relation->getAttribute('order_field'),
						'fieldType' => 'INT UNSIGNED',
						'required' => true,
						'primaryKey' => false,
					];
				}

				$result[$tableName][0]['indexName'] = 'idx_' . $localTypeTS;
				$result[$tableName][1]['indexName'] = 'idx_' . $remoteTypeTS;
			}
		}

		$maps = $classElement->getElementsByTagName('map');
		foreach ($maps as $map) {
			$tableName = $map->getAttribute('table');
			$idField = $map->getAttribute('id');
			$objectTypeField = $map->hasAttribute('type') ? $map->getAttribute('type') : false;

			$result[$tableName] = [];

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
				$result[$tableName][] = [
					'fieldName' => $objectTypeField,
					'fieldType' => 'VARCHAR(191)',
					'required' => true,
					'primaryKey' => false,
				] + $ownerIndex;
			}

			$result[$tableName][] = [
				'fieldName' => $idField,
				'fieldType' => 'INT UNSIGNED',
				'required' => true,
				'primaryKey' => false,
			] + $ownerIndex;

			// Keys use a binary collation, so that they are as distinct in the
			// database as they are in a PHP array ('Theme' is not 'theme')
			foreach ($keyElements as $keyElement)
				$result[$tableName][] = [
					'fieldName' => $keyElement->getAttribute('name'),
					'fieldType' => "VARCHAR(191) CHARACTER SET {$this->charset} COLLATE {$this->charset}_bin",
					'required' => true,
					'primaryKey' => false,
				] + $keyIndex;

			$valueElements = $map->getElementsByTagName('value');
			if ($valueElements->length != 1)
				throw new \Exception("Map {$map->getAttribute('name')} of class {$classElement->getAttribute('name')} must have exactly one <value>, found {$valueElements->length}");
			foreach ($valueElements as $valueElement)
			$result[$tableName][] = [
				'fieldName' => $valueElement->getAttribute('name'),
				'fieldType' => 'TEXT',
				'required' => true,
				'primaryKey' => false,
			];
		}

		return $result;
	}

	/**
	 * Uses a table style converter to convert class and property names into table and column names.
	 *
	 * @param string $term what kind of term to translate: table | id | fieldname
	 * @param string $name the name to translate
	 * @return string the converted name
 	 */
	private function getAuto(string $term, string $name) : string {
		$styleConverter = __NAMESPACE__.'\\TS'.$this->root->getAttribute('tablestyle');

		if (!class_exists($styleConverter))
			die("ERROR: Cannot find table style converter class {$styleConverter}\n");

		if ($term == 'id') {
			// the root element property 'id_style' if it exists can be 'long' or
			// 'short', with the default being 'short', which means the main primary
			// key field for tables will be named 'id', whereas the long version uses
			// the converted class name + '_id'
			$idStyle = $this->root->hasAttribute('id_style') ? $this->root->getAttribute('id_style') : 'short';
			if ($idStyle == 'short')
				return 'id';
		}

		return $styleConverter::translate($term, $name);
	}

	private DOMDocument $doc;
	private DOMElement $root;
	private string $charset = 'utf8mb4';
	private ?string $collate = 'utf8mb4_unicode_ci';
}