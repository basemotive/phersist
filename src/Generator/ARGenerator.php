<?php

namespace PHersist\Generator;

use DOMDocument;
use DOMElement;

/**
 * Generates ActiveRecord instances.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARGenerator {
	public function __construct(string $xml) {
		$this->doc = new DOMDocument();
		$this->doc->loadXML($xml);

		$this->root = $this->doc->documentElement;
	}

	public function getNamespace() : string {
		$namespace = $this->root->hasAttribute('namespace') ? $this->root->getAttribute('namespace') : '';
		return trim($namespace, '\\').'\\';
	}

	/**
	 * Generates the code for all the classes in the XML.
	 *
	 * @return array the code in the format [ $className => $classCode, ... ]
	 */
	public function generate() : array {
		$result = [];

		$classElements = $this->root->getElementsByTagName('class');
		foreach ($classElements as $classElement)
			$result[$classElement->getAttribute('name')] = $this->generateClass($classElement);

		return $result;
	}

	private function generateClass(DOMElement $classElement) : string {
		$className = $classElement->getAttribute('name');
		$meta = $this->generateMeta($classElement);
		$namespace = $this->root->hasAttribute('namespace') ? rtrim($this->root->getAttribute('namespace'), '\\') : null;

		$txt = "<?php\n\n";

		if ($namespace != null)
			$txt .= "namespace {$namespace};\n\n";

		$txt  .= $this->generateDocs($classElement);
		$txt .= "class $className extends \\PHersist\\ActiveRecord {\n";

		// If a Trait exists for this class, use it
		if ($classElement->hasAttribute('trait')) {
			$txt .= "\tuse {$classElement->getAttribute('trait')};\n\n";
		} elseif (trait_exists("{$namespace}\\{$className}Trait")) {
			$txt .= "\tuse {$namespace}\\{$className}Trait;\n\n";
		}

		// Write the $_meta variable that holds the information the ActiveRecord
		// needs to function
		$txt .= "\tprotected static \$_meta = ".$this->exportArray($meta, 1).";\n\n";

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
		$datasets = $classElement->getElementsByTagName('dataset');
		if ($datasets->length >0) {
			$result .= " *\n";
			//$result .= " * Properties:\n";
			foreach ($datasets as $dataset) {
				$properties = $dataset->getElementsByTagName('property');
				foreach ($properties as $property) {
					$prop_name = $property->getAttribute('name');
					$prop_type = $property->hasAttribute('type') ?
						$property->getAttribute('type') : 'Text';

					$phpType = 'string';
					if ($prop_type == 'Class')
						$phpType = $property->getAttribute('class');

					if (!$property->hasAttribute('required') || $property->getAttribute('required') != 'true')
						$phpType = "?{$phpType}";

					$result .= " * @property {$phpType} \${$prop_name}";

					if ($prop_type == 'TimestampText') {
						$result .= ' timestamp';
						if ($property->hasAttribute('update_on'))
							$result .= ', updates on '.$property->getAttribute('update_on');
						if ($property->hasAttribute('date_format'))
							$result .= ', date format: '.$property->getAttribute('date_format');
					}

					$result .= "\n";
				}
			}
		}

		$relations = $classElement->getElementsByTagName('relation');
		if ($relations->length > 0) {
			//$result .= " *\n";
			//$result .= " * Relations:\n";
			foreach ($relations as $relation) {
				$rl_name = $relation->getAttribute('name');
				$rl_class = $relation->getAttribute('class');

				$result .= " * @property {$rl_class}[] \${$rl_name} relation";
				if ($relation->hasAttribute('order_field'))
					$result .= ", ordered by ".$relation->getAttribute('order_field');

				$rw = $relation->hasAttribute("table_owner") && $relation->getAttribute('table_owner') == 'true';
				$result .= ', '.($rw ? 'read-write' : 'read-only');

				$result .= "\n";
			}
		}

		$result .= " */\n";
		return $result;
	}

	/**
	 * Generates the metadata for a class. The ActiveRecord code will look at this data which
	 * is defined in every subclass, and change its behaviour accordingly.
	 *
	 * @param DOMElement $classElement the class element in the XML tree
	 * @return array the metadata
 	 */
	private function generateMeta(DOMElement $classElement) : array {
		$className = $classElement->getAttribute('name');
		$id = $classElement->hasAttribute('id') ?
			$classElement->getAttribute('id') : $this->getAuto('id', $className);
		$database = $classElement->hasAttribute('database') ?
			$classElement->getAttribute('database') : $this->root->getAttribute('database');
		$table = $classElement->hasAttribute('table') ?
			$classElement->getAttribute('table') : $this->getAuto('table', $className);
		$softdelete = $classElement->hasAttribute('softdelete') && $classElement->getAttribute('softdelete')=='true';

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

		// process the datasets
		$datasets = $classElement->getElementsByTagName('dataset');
		foreach ($datasets as $dataset) {
			$ds_autoload = $dataset->hasAttribute('autoload') && $dataset->getAttribute('autoload')=='true';
			$ds_table = $dataset->hasAttribute('table') ? $dataset->getAttribute('table') : $table;

			$metads = [
				'autoload' => $ds_autoload,
				'table' => $ds_table,
				'props' => [],
			];

			// process the properties within the dataset
			$properties = $dataset->getElementsByTagName('property');
			foreach ($properties as $property) {
				$prop_name = $property->getAttribute('name');
				$prop_fieldnames_str = '';
				if ($property->hasAttribute('fieldnames'))
					$prop_fieldnames_str = $property->getAttribute('fieldnames');
				if ($prop_fieldnames_str == '' && $property->hasAttribute('fieldname'))
					$prop_fieldnames_str = $property->getAttribute('fieldname');
				if ($prop_fieldnames_str == '')
					$prop_fieldnames_str = $this->getAuto('fieldname', $prop_name);

				$prop_type = $property->hasAttribute('type') ?
					$property->getAttribute('type') : 'Text';

				$metaprop = [
					'type' => $prop_type,
					'fieldnames' => explode(',', $prop_fieldnames_str),
				];

				// Special types - TODO Can we make this more generic?
				if ($prop_type == 'Class') {
					$metaprop['class'] = $this->getNamespace().$property->getAttribute('class');
				} elseif ($prop_type == 'TimestampText') {
					$metaprop['update_on'] = $property->getAttribute('update_on');
					if ($property->hasAttribute('date_format'))
						$metaprop['date_format'] = $property->getAttribute('date_format');
				}

				// if this property is required
				$metaprop['required'] = $property->hasAttribute('required') && $property->getAttribute('required') == 'true';

				$metads['props'][$prop_name] = $metaprop;
			}

			$meta['datasets'][] = $metads;
		}

		// Process the relations
		$relations = $classElement->getElementsByTagName('relation');
		foreach ($relations as $relation) {
			$metarel = [
				'type' => $relation->getAttribute('type'),
				'class' => $this->getNamespace().$relation->getAttribute('class'),
				'table' => $relation->getAttribute('table'),
				'local_id' => $relation->getAttribute('local_id'),
				'remote_id' => $relation->getAttribute('remote_id'),
				'table_owner' => $relation->getAttribute('table_owner') == 'true',
				'load_objects' => $relation->getAttribute('load_objects') == 'true',
				'cascade_delete' => $relation->getAttribute('cascade_delete') == 'true',
			];

			if ($relation->hasAttribute('order_field'))
				$metarel['order_field'] = $relation->getAttribute('order_field');

			if ($relation->hasAttribute('local_type'))
				$metarel['local_type'] = $relation->getAttribute('local_type');

			$meta['relations'][$relation->getAttribute('name')] = $metarel;
		}

		$maps = $classElement->getElementsByTagName('map');
		foreach ($maps as $map) {
			$metamap = [
				'table' => $map->getAttribute('table'),
				'id' => $map->getAttribute('id'),
				'type' => $map->hasAttribute('type') ? $map->getAttribute('type') : false,
				'activeRecordKey' => $map->getAttribute('name'),
				'keys' => [],
				'values' => [],
			];

			$keys = $map->getElementsByTagName('key');
			foreach ($keys as $key)
				$metamap['keys'][] = $key->getAttribute('name');

			$values = $map->getElementsByTagName('value');
			foreach ($values as $value)
				$metamap['values'][] = $value->getAttribute('name');

			$meta['maps'][$map->getAttribute('name')] = $metamap;
		}

		return $meta;
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
			// the root element property 'id_style' if it existscan be 'long' or
			// 'short', with the default being 'short', which means the main primary
			// key field for tables will be named 'id', whereas the long version uses
			// the converted class name + '_id'
			$idStyle = $this->root->hasAttribute('id_style') ? $this->root->getAttribute('id_style') : 'short';
			if ($idStyle == 'short')
				return 'id';
		}

		return $styleConverter::translate($term, $name);
	}

	/**
	 * Exports a (nested) array as clean PHP syntax using square bracket notation
	 * with proper indentation, as an alternative to var_export().
	 *
	 * @param array $array the array to export
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
				$lines[] = "{$innerIndent}'{$key}' => {$exportedValue}";
		}

		return "[\n".implode(",\n", $lines)."\n{$indent}]";
	}

	private DOMDocument $doc;
	private DOMElement $root;
}