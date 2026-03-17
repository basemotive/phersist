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
	public function __construct(string $xml, ?string $includeDir = null) {
		$this->doc = new DOMDocument();
		$this->doc->loadXML($xml);

		$this->root = $this->doc->documentElement;
		$this->includeDir = $includeDir;
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

		$txt = "<?php\n\n";
		$txt  .= $this->generateDocs($classElement);
		$txt .= "class $className extends \\PHersist\\ActiveRecord {\n";
		$txt .= "\tprotected static \$_meta = ".var_export($meta, true).";\n\n";

		// Import the include code for this class, which is required because PHP cannot include
		// extra class methods from a file.
		if ($this->includeDir !== null && file_exists("{$this->includeDir}/$className.include.php")) {
			$filedata = trim(file_get_contents("{$this->includeDir}/$className.include.php"));

			if (substr($filedata,0,5) == '<?php') $filedata = substr($filedata,5);
			if (substr($filedata, strlen($filedata)-2) == '?>') $filedata = substr($filedata, 0, strlen($filedata)-2);
			$filedata = trim($filedata);

			$txt .= "\t// --- START Content from {$className}.include.php\n";
			$txt .= "\n";
			foreach (explode("\n", $filedata) as $line) $txt .= "\t$line\n";
			$txt .= "\n";
			$txt .= "\t// --- END Content from {$className}.include.php\n";
		}

		$txt .= "}\n";
		$txt .= "?>";

		return $txt;
	}

    /**
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
			$classElement->getAttribute('id') : $this->getAuto($className, 'id');
		$database = $classElement->hasAttribute('database') ?
			$classElement->getAttribute('database') : $this->root->getAttribute('database');
		$table = $classElement->hasAttribute('table') ?
			$classElement->getAttribute('table') : $this->getAuto($className, 'table');
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

		// Process the datasets
		$datasets = $classElement->getElementsByTagName('dataset');
		foreach ($datasets as $dataset) {
			$ds_autoload = $dataset->hasAttribute('autoload') && $dataset->getAttribute('autoload')=='true';
			$ds_table = $dataset->hasAttribute('table') ? $dataset->getAttribute('table') : $table;

			$metads = [
				'autoload' => $ds_autoload,
				'table' => $ds_table,
				'props' => [],
			];

			// Process the properties within the dataset
			$properties = $dataset->getElementsByTagName('property');
			foreach ($properties as $property) {
				$prop_name = $property->getAttribute('name');
				$prop_fieldnames_str = '';
				if ($property->hasAttribute('fieldnames'))
					$prop_fieldnames_str = $property->getAttribute('fieldnames');
				if ($prop_fieldnames_str == '' && $property->hasAttribute('fieldname'))
					$prop_fieldnames_str = $property->getAttribute('fieldname');
				if ($prop_fieldnames_str == '')
					$prop_fieldnames_str = $this->getAuto($prop_name, 'fieldname');

				/*
				$prop_fieldnames_str = $property->hasAttribute('fieldnames') ?
					$property->getAttribute('fieldnames') : $this->getAuto($prop_name, 'fieldname');
				*/
				$prop_type = $property->hasAttribute('type') ?
					$property->getAttribute('type') : 'Text';

				$metaprop = [
					'type' => $prop_type,
					'fieldnames' => explode(',', $prop_fieldnames_str),
				];

				// Special types - TODO Can we make this more generic?
				if ($prop_type == 'Class') {
					$metaprop['class'] = $property->getAttribute('class');
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
				'class' => $relation->getAttribute('class'),
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
	 * @param string $base
	 * @param string $property
	 * @return string
 	 */
	private function getAuto(string $base, string $property) : string {
		$styleConverter = __NAMESPACE__.'\\TS'.$this->root->getAttribute('tablestyle');
		return $styleConverter::translate($property, $base);
	}

	private \DOMDocument $doc;
	private \DOMElement $root;
	private ?string $includeDir;
}