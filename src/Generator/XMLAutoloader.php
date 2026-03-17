<?php

namespace PHersist\Generator;

/**
 * This is an autoloader that loads class definitions directly from a PHersist
 * compatible XML-file.
 *
 * Using this autoloader may be useful during development, especially while
 * tinkering with the XML file, taking out the necessity of generating the
 * class files every time the XML was modified.
 *
 * It is not recommended to use this for production environments, because
 * this class basically generates the PHP code for every needed class once
 * for every PHP request, which is probably bad for performance.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive V.O.F. - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class XMLAutoloader {
	public function __construct(string $xmlFile) {
		$xml = file_get_contents($xmlFile);
		$this->generator = new ARGenerator($xml);

		spl_autoload_register([ $this, 'loader' ]);
	}

	private function loader(string $className) : void {
		$phpcode = $this->generator->generate($className);

		// If the class was not found in the XML, the generator returns nothing
		if ($phpcode == '')
			return;

		// The generator creates complete PHP code, but eval can't handle the '<?php'
		if (substr($phpcode, 0, 5)=='<?php') $phpcode = substr($phpcode, 5);
		if (substr($phpcode, strlen($phpcode)-2) == '?>') $phpcode = substr($phpcode, 0, strlen($phpcode)-2);

		// Now load the PHP code
		eval($phpcode);
	}

	private $generator;
}
