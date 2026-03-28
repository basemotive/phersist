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
	 * Converts a term from camel case to snake case.
	 *
	 * @param string $term what kind of term to translate: table | id | fieldname
	 * @param string $name the name to translate
	 * @return string the converted name
	 */
	public static function translate(string $term, string $name) : string {
		if ($term == 'table') {
			$singular = self::fixup($name);
			// Apply basic English pluralization rules
			if (preg_match('/(s|sh|ch|x|z)$/', $singular))
				return $singular . 'es';
			elseif (preg_match('/[^aeiou]y$/', $singular))
				return substr($singular, 0, -1) . 'ies';
			elseif (preg_match('/f$/', $singular))
				return substr($singular, 0, -1) . 'ves';
			elseif (preg_match('/fe$/', $singular))
				return substr($singular, 0, -2) . 'ves';
			elseif (preg_match('/o$/', $singular) && !preg_match('/(oo|eo|io|uo)$/', $singular))
				return $singular . 'es';
			else
				return $singular . 's';
		} elseif ($term == 'id' || $term == 'relation_id') {
			return self::fixup($name).'_id';
		} elseif ($term == 'relation_combo') {
			$fixedName = self::fixup($name);
			return "{$fixedName}_type,{$fixedName}_id";
		} elseif ($term == 'fieldname') {
			return self::fixup($name);
		} else {
			die("Don't know $term\n");
		}
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