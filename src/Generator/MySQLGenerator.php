<?php

namespace PHersist\Generator;

use DOMDocument;
use DOMElement;

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

			$result .= "DROP TABLE IF NOT EXISTS `{$tableName}`\n";
			$result .= "CREATE TABLE `{$tableName}` (\n";
			foreach ($fields as $field) {
				$result .= "\t`{$field['fieldName']}` {$field['fieldType']}";
				if ($field['required'])
					$result .= " NOT NULL";
				if ($field['primaryKey']) {
					$result .= " AUTO_INCREMENT";
					$primaryKey = $field;
				}
				$result .= ",\n";

				if ($field['primaryKey'])
					$result .= "\n";
			}

			// cut the last comma if there's no primary key
			if ($primaryKey)
				$result .= "\n\tPRIMARY KEY (`{$primaryKey['fieldName']}`)\n";
			else
				$result = rtrim($result, ",\n")."\n";

			$result .= ");\n\n";
		}

		return $result;
	}

	/**
	 * Generates the tables for a single class.
	 *
	 * @param DOMElement $classElement the XML element for the $class
	 * @return array an associative array [ 'table_name' => [ PROPS ] ]
	 */
	private function generateClass(DOMElement $classElement) : array {
		$className = $classElement->getAttribute('name');
		$idField = $classElement->hasAttribute('id') ?
			$classElement->getAttribute('id') : $this->getAuto($className, 'id');
		$database = $classElement->hasAttribute('database') ?
			$classElement->getAttribute('database') : $this->root->getAttribute('database');
		$table = $classElement->hasAttribute('table') ?
			$classElement->getAttribute('table') : $this->getAuto($className, 'table');
		$softdelete = $classElement->hasAttribute('softdelete') && $classElement->getAttribute('softdelete')=='true';

		$result = [];

		// process the datasets
		$datasets = $classElement->getElementsByTagName('dataset');
		foreach ($datasets as $dataset) {
			$datasetTable = $dataset->hasAttribute('table') ? $dataset->getAttribute('table') : $table;

			if (!isset($result[$datasetTable]))
				$result[$datasetTable] = [];
				$result[$datasetTable][] = [
					'fieldName' => $idField,
					'fieldType' => 'INT UNSIGNED',
					'required' => true,
					'primaryKey' => true,
				];

			// process the properties within the dataset
			$properties = $dataset->getElementsByTagName('property');
			foreach ($properties as $property) {
				$propName = $property->getAttribute('name');
				$propType = $property->hasAttribute('type') ? $property->getAttribute('type') : 'Text';
				$required = $property->hasAttribute('required') && $property->getAttribute('required') == 'true';

				$fieldNames = null;
				if ($property->hasAttribute('fieldname')) {
					$fieldNames = [ $property->getAttribute('fieldname') ];
				} elseif ($property->hasAttribute('fieldnames')) {
					$fieldNames = explode(',', $property->getAttribute('fieldnames'));
				} else {
					$fieldNames = [ $this->getAuto($propName, 'fieldname') ];
				}

				if ($propType == 'Text') {
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'TEXT',
						'required' => $required,
						'primaryKey' => false,
					];
				} elseif ($propType == 'Int') {
					// signed ints by default
					$signed = !$property->hasAttribute('signed') || $property->getAttribute('signed') == 'true';
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'INT' . ($signed ? '' : ' UNSIGNED'),
						'required' => $required,
						'primaryKey' => false,
					];
				} elseif ($propType == 'Class') {
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'INT UNSIGNED',
						'required' => $required,
						'primaryKey' => false,
					];
				} elseif ($propType == 'DynamicClass') {
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[0],
						'fieldType' => 'TEXT',
						'required' => $required,
						'primaryKey' => false,
					];
					$result[$datasetTable][] = [
						'fieldName' => $fieldNames[1],
						'fieldType' => 'INT UNSIGNED',
						'required' => $required,
						'primaryKey' => false,
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

		// Process the relations
		$relations = $classElement->getElementsByTagName('relation');
		foreach ($relations as $relation) {
			$tableName = $relation->getAttribute('table');
			$localID = $relation->getAttribute('local_id');
			$remoteID = $relation->getAttribute('remote_id');
			$tableOwner = $relation->getAttribute('table_owner') == 'true';

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

				// TODO we may want to put indexes on the ID-fields for faster queries
			}
		}

		$maps = $classElement->getElementsByTagName('map');
		foreach ($maps as $map) {
			$tableName = $map->getAttribute('table');
			$idField = $map->getAttribute('id');
			$objectTypeField = $map->hasAttribute('type') ? $map->getAttribute('type') : false;

			$result[$tableName] = [];

			if ($objectTypeField) {
				$result[$tableName][] = [
					'fieldName' => $objectTypeField,
					'fieldType' => 'TEXT',
					'required' => true,
					'primaryKey' => false,
				];
			}

			$result[$tableName][] = [
				'fieldName' => $idField,
				'fieldType' => 'INT UNSIGNED',
				'required' => true,
				'primaryKey' => false,
			];

			$keyElements = $map->getElementsByTagName('key');
			foreach ($keyElements as $keyElement)
				$result[$tableName][] = [
					'fieldName' => $keyElement->getAttribute('name'),
					'fieldType' => 'TEXT',
					'required' => true,
					'primaryKey' => false,
				];

			$valueElements = $map->getElementsByTagName('value');
			foreach ($valueElements as $valueElement)
			$result[$tableName][] = [
				'fieldName' => $valueElement->getAttribute('name'),
				'fieldType' => 'TEXT',
				'required' => true,
				'primaryKey' => false,
			];

			// TODO add some indexes for faster lookups
		}

		return $result;
	}

	/**
	 * Uses a table style converter to convert class and property names into table and column names.
	 *
	 * @param string $base
	 * @param string $property
	 * @return string
 	 */
	private function getAuto(string $base, string $property) : string {
		$styleConverter = __NAMESPACE__.'\\TS'.$this->root->getAttribute('tablestyle');
		return $styleConverter::translate($property, $base);
	}

	private DOMDocument $doc;
	private DOMElement $root;
}