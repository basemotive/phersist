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

		return $doc->documentElement;
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

			if ($type == 'Int') {
				$value = (new ARPropertyTypeInt())->normalize([], $default);
				// The column is a MySQL INT, which is 32 bits
				$signed = !$property->hasAttribute('signed') || $property->getAttribute('signed') == 'true';
				[$min, $max] = $signed ? [-2147483648, 2147483647] : [0, 4294967295];
				if ($value < $min || $value > $max)
					throw new \InvalidArgumentException("$value is out of range for ".($signed ? 'a signed' : 'an unsigned').' INT column');
				return $value;
			}

			if ($type == 'Float') {
				$value = (new ARPropertyTypeFloat())->normalize([], $default);
				if (!is_finite($value))
					throw new \InvalidArgumentException("'$default' is not a finite number");
				return $value;
			}

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
