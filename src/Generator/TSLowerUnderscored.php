<?php

namespace PHersist\Generator;

/**
 * Does an implicit conversion of class and property names etc to the convention
 * used in the database.
 *
 * This example converts camel case names to all lower case names with underscores.
 * Examples:
 * - class 'Page' maps to table 'pages'
 * - class 'ForumMessage' maps to table 'forum_messages'
 * - class 'Page' has id-field 'page_id'
 * - class 'ForumMessage' has id-field 'forum_message_id'
 * - fieldname 'name' maps to 'name'
 * - fieldname 'creationDate' maps to 'creation_date'
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class TSLowerUnderscored {

	public static function translate(string $what, string $className) : string {
		if ($what == 'table') {
			return self::fixup($className).'s';
		} elseif ($what == 'id') {
			return self::fixup($className).'_id';
		} elseif ($what == 'fieldname') {
			return self::fixup($className);
		} else {
			die("Don't know $what\n");
		}
	}

	private static function fixup(string $value) : string {
		$result = '';
		for ($i=0; $i<strlen($value); $i++) {
			$chr = substr($value,$i,1);
			$result .= strtolower($chr)!=$chr ? ($i!=0?'_':'').strtolower($chr) : $chr;
		}
		return $result;
	}

}
