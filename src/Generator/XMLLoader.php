<?php

namespace PHersist\Generator;

use DOMDocument;
use DOMElement;

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
}
