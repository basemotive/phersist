<?php

namespace PHersist;

/**
 * Knows where to find the references to a class, so they can be cleaned up
 * when one of its objects is deleted.
 *
 * The generator writes a PHersistReferences class next to the model classes
 * of each XML, with the references from those classes, keyed by the class
 * they refer to. On a delete, ReferenceCleaner reads those of the deleted
 * object's own namespace and of every registered namespace.
 *
 * A project with a single model XML doesn't need to register anything. With
 * several model XMLs, like plugins, register the namespace of each one whose
 * classes refer to classes of another one, on every request, whether the
 * request uses it or not: otherwise deleting an object would skip their
 * references.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class ReferenceRegistry {
	/**
	 * The name of the generated class with the references, in the namespace of the model classes.
	 */
	public const INDEX_CLASS = 'PHersistReferences';

	/**
	 * Registers the namespace of a model XML. Nothing is loaded until an
	 * object is deleted.
	 *
	 * @param string $namespace the namespace of the generated classes
	 */
	public static function register(string $namespace) : void {
		$namespace = trim($namespace, '\\');
		if (isset(self::$namespaces[$namespace]))
			return;
		self::$namespaces[$namespace] = true;
		self::$references = [];
	}

	/**
	 * Returns the references to a class, from its own namespace and the
	 * registered ones.
	 *
	 * @param string $class the class, with namespace, without leading backslash
	 * @return list<array<string, mixed>> the references, as in the REFERENCES
	 *   of the PHersistReferences classes
	 * @throws \Exception if a namespace has no PHersistReferences class
	 */
	public static function referencesTo(string $class) : array {
		if (isset(self::$references[$class]))
			return self::$references[$class];

		$pos = strrpos($class, '\\');
		$namespaces = [ ($pos === false ? '' : substr($class, 0, $pos)) => true ] + self::$namespaces;

		$references = [];
		foreach (array_keys($namespaces) as $namespace) {
			$indexClass = ($namespace === '' ? '' : $namespace.'\\').self::INDEX_CLASS;
			if (!class_exists($indexClass))
				throw new \Exception("Cannot find the PHersist references of ".($namespace === '' ? 'the global namespace' : "namespace '{$namespace}'")
					.": class {$indexClass} doesn't exist. Generate the classes of that model XML again, and make sure the class can be autoloaded");
			/** @var array<string, list<array<string, mixed>>> $index */
			$index = constant("{$indexClass}::REFERENCES");
			array_push($references, ...($index[$class] ?? []), ...($index['*'] ?? []));
		}

		return self::$references[$class] = $references;
	}

	/** @var array<string, true> the registered namespaces, as keys */
	private static array $namespaces = [];

	/** @var array<string, list<array<string, mixed>>> the references by class, once read */
	private static array $references = [];
}
