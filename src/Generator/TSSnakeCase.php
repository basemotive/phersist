<?php

namespace PHersist\Generator;

/**
 * Does an implicit conversion of class and property names etc to the convention
 * used in the database.
 *
 * This instance converts camel case names to snake case.
 * Examples:
 * - class 'Page' maps to table 'pages'
 * - class 'ForumMessage' maps to table 'forum_messages'
 * - class 'XMLDocument' maps to table 'xml_documents'
 * - class 'Page' has id-field 'page_id'
 * - class 'ForumMessage' has id-field 'forum_message_id'
 * - fieldname 'name' maps to 'name'
 * - fieldname 'creationDate' maps to 'creation_date'
 * - fieldname 'isXML' maps to 'is_xml'
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class TSSnakeCase {

	/**
	 * Plurals the suffix rules don't produce, applied to the last word of a name.
	 * Not pluralizing "person" to "people" because that's just weird.
	 * Most of these are admittely pretty rare to want to pluralize.
	 */
	private const IRREGULAR_PLURALS = [
		'child' => 'children', 'man' => 'men', 'woman' => 'women',
		'series' => 'series', 'species' => 'species',
		'hero' => 'heroes', 'potato' => 'potatoes', 'tomato' => 'tomatoes', 'echo' => 'echoes', 'veto' => 'vetoes',
	];

	/**
	 * Basic English pluralization rules (pattern => replacement), the first match wins.
	 */
	private const PLURAL_RULES = [
		'/iz$/' => 'izzes',               // quiz
		'/sis$/' => 'ses',                // analysis, crisis
		'/(s|sh|ch|x|z)$/' => '$1es',     // bus, dish, match, box, waltz
		'/([^aeiou])y$/' => '$1ies',      // category (but not key)
		'/(l|ea|oa)f$/' => '$1ves',       // shelf, leaf, loaf (but not chief, roof, cliff)
		'/ife$/' => 'ives',               // knife, life (but not safe, giraffe)
	];

	/**
	 * Converts a term from camel case to snake case.
	 *
	 * @param string $term what kind of term to translate: table | id | fieldname
	 * @param string $name the name to translate
	 * @return string the converted name
	 */
	public static function translate(string $term, string $name) : string {
		if ($term == 'table') {
			return self::pluralize(self::fixup($name));
		} elseif ($term == 'id' || $term == 'relation_id') {
			return self::fixup($name).'_id';
		} elseif ($term == 'relation_combo') {
			$fixedName = self::fixup($name);
			return "{$fixedName}_type,{$fixedName}_id";
		} elseif ($term == 'fieldname') {
			return self::fixup($name);
		} else {
			throw new \Exception("Don't know $term");
		}
	}

	/**
	 * Pluralizes the last word of a snake case name.
	 *
	 * @param string $singular the snake case name
	 * @return string the name with its last word pluralized
	 */
	private static function pluralize(string $singular) : string {
		$pos = strrpos($singular, '_');
		$prefix = $pos === false ? '' : substr($singular, 0, $pos + 1);
		$word = substr($singular, strlen($prefix));
		if (isset(self::IRREGULAR_PLURALS[$word]))
			return $prefix . self::IRREGULAR_PLURALS[$word];

		foreach (self::PLURAL_RULES as $pattern => $replacement) {
			if (preg_match($pattern, $singular))
				return (string)preg_replace($pattern, $replacement, $singular);
		}
		return $singular . 's';
	}

	/**
	 * Does the actual camel case to snake case conversion.
	 *
	 * @param string $name the name to convert
	 * @return string the converted name
	 */
	private static function fixup(string $name) : string {
		$result = '';
		for ($i = 0; $i < strlen($name); $i++) {
			$chr = substr($name, $i, 1);
			$lower = strtolower($chr);

			// Add underscore if:
			// 1. This is not the first character AND
			// 2. Current character is uppercase AND
			// 3. Either previous character is lowercase OR (next char is lowercase and
			//    we're in a sequence of caps)
			if ($i !== 0 && $chr !== $lower) {
				$prevChar = substr($name, $i - 1, 1);
				$nextChar = $i + 1 < strlen($name) ? substr($name, $i + 1, 1) : '';

				// Add underscore if previous is lowercase (transition from lower to upper)
				// OR if next is lowercase and previous is uppercase (end of acronym)
				if (strtolower($prevChar) === $prevChar || ($nextChar && strtolower($nextChar) === $nextChar && strtolower($prevChar) !== $prevChar))
					$result .= '_';
			}

			$result .= $lower;
		}

		return $result;
	}

}