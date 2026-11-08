<?php

namespace Tests\Support;

use PHersist\Generator\ARGenerator;
use PHersist\Generator\MariaDBSchemaGenerator;
use PHersist\Generator\Model\Project;
use PHersist\Generator\MySQLSchemaGenerator;
use PHersist\Generator\SchemaGenerator;
use PHersist\Generator\SQLiteSchemaGenerator;
use PHersist\Generator\XMLLoader;

/**
 * Runs the generators the way bin/phersist does.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Generate {
	/**
	 * The schema generators, by dialect.
	 *
	 * @var array<string, class-string<SchemaGenerator>>
	 */
	public const SCHEMA_GENERATORS = [
		'mysql' => MySQLSchemaGenerator::class,
		'mariadb' => MariaDBSchemaGenerator::class,
		'sqlite' => SQLiteSchemaGenerator::class,
	];

	/**
	 * Generates everything for a model XML: the classes, and the schema of
	 * every dialect.
	 *
	 * @return array<string, string> the output, by file name (<Class>.php, <dialect>.sql)
	 */
	public static function all(string $xmlFile) : array {
		$project = XMLLoader::load($xmlFile);
		$output = [];
		foreach (self::classes($project) as $className => $code)
			$output["{$className}.php"] = $code;
		foreach (array_keys(self::SCHEMA_GENERATORS) as $dialect)
			$output["{$dialect}.sql"] = self::schema($project, $dialect);
		return $output;
	}

	/**
	 * @return array<string, string> the code, by class name
	 */
	public static function classes(Project $project) : array {
		return (new ARGenerator($project))->generate();
	}

	public static function schema(Project $project, string $dialect) : string {
		$generatorClass = self::SCHEMA_GENERATORS[$dialect] ?? throw new \InvalidArgumentException("Unknown dialect '{$dialect}'");
		return (new $generatorClass($project))->generate();
	}
}
