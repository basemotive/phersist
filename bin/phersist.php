<?php

/**
 * A simple script for generating class files and SQL schemas
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */

$autoloadFiles = [
	// when installed through composer
	__DIR__ . '/../../../autoload.php',
	// for local development
	__DIR__ . '/../vendor/autoload.php',
];
$autoloader = null;
foreach ($autoloadFiles as $autoloadFile)
	if (file_exists($autoloadFile))
		$autoloader = require($autoloadFile);

use PHersist\Generator\ARGenerator;
use PHersist\Generator\MariaDBSchemaGenerator;
use PHersist\Generator\MySQLSchemaGenerator;
use PHersist\Generator\SQLiteSchemaGenerator;
use PHersist\Generator\XMLLoader;

// The schema generators: [ option => [ generator class, database name ] ]
$dialects = [
	'mysql' => [ MySQLSchemaGenerator::class, 'MySQL' ],
	'mariadb' => [ MariaDBSchemaGenerator::class, 'MariaDB' ],
	'sqlite' => [ SQLiteSchemaGenerator::class, 'SQLite' ],
];

$options = getopt('h', array_merge([
	'xml:',
	'classesdir:',
	'skip-classes',
	'help',
], array_map(fn($dialect) => "{$dialect}:", array_keys($dialects))));

// --xml can be given more than once, then getopt() returns an array
$xmlFiles = isset($options['xml']) ? (array)$options['xml'] : [];
$classesDir = $options['classesdir'] ?? null;
// The schema files to write: [ dialect => file ]
$schemaFiles = [];
foreach (array_keys($dialects) as $dialect)
	if (isset($options[$dialect]) && is_string($options[$dialect]))
		$schemaFiles[$dialect] = $options[$dialect];
$skipClasses = isset($options['skip-classes']);
$help = isset($options['h']) || isset($options['help']);
$script = $argv[0] ?? 'phersist';

if ($help) {
	echo "Usage: {$script} --xml=<file>\n";
    echo "Where:\n";
    echo "  --xml=<FILE>\n";
    echo "    the project's data structure; can be given more than once\n";
    echo "Optional parameters:\n";
    echo "  --classesdir=<PATH>\n";
    echo "    the path where the classes should be written; only with a single --xml\n";
	foreach ($dialects as $dialect => [ , $name ]) {
		echo "  --{$dialect}=<SQLFILE>\n";
		echo "    where to write the {$name} schema\n";
	}
    echo "  --skip-classes\n";
    echo "    don't generate the class files\n";
    exit;
}

if ($skipClasses && !$schemaFiles) {
	echo "Nothing to do.\n";
	exit(0);
}
if (!$xmlFiles) {
    echo "Usage: {$script} --xml=<file>\n";
    echo "More info: {$script} --help\n";
    exit(1);
}
foreach ($xmlFiles as $xmlFile)
	if (!file_exists($xmlFile)) {
		echo "ERROR: XML file '{$xmlFile}' not found\n";
		exit(1);
	}
if ($classesDir && count($xmlFiles) > 1) {
	echo "ERROR: --classesdir can only be used with a single --xml; with several, the classes dir of each is detected from its namespace\n";
	exit(1);
}
if ($classesDir && (!file_exists($classesDir) || !is_dir($classesDir))) {
	echo "ERROR: Classes dir '{$classesDir}' not found\n";
	exit(1);
}
if (!$autoloader) {
	echo "ERROR: Composer autoloader not found; run 'composer install' first\n";
	exit(1);
}

try {
	$projects = array_map(fn($xmlFile) => XMLLoader::load($xmlFile), $xmlFiles);

	if (!$skipClasses) {
		foreach ($projects as $project) {
			$projectClassesDir = $classesDir;
			if (!$projectClassesDir) {
				// automagically figure out where to put the classes
				$namespace = $project->namespace == '' ? '' : $project->namespace.'\\';
				$prefixes = $autoloader->getPrefixesPsr4();
				uksort($prefixes, fn($a, $b) => strlen($b) - strlen($a));
				foreach ($prefixes as $prefix => $dirs) {
					if (str_starts_with($namespace, $prefix)) {
						$relative = str_replace('\\', '/', substr($namespace, strlen($prefix)));
						$projectClassesDir = realpath("{$dirs[0]}/{$relative}");
						// realpath may not actually exist, so only break on success
						if ($projectClassesDir)
							break;
					}
				}
			}

			if (!$projectClassesDir) {
				echo "ERROR: Could not auto-detect classes dir for '{$project->file}'; please specify with --classesdir\n";
				exit(1);
			}

			foreach ((new ARGenerator($project))->generate() as $className => $code) {
				$classFile = "{$projectClassesDir}/{$className}.php";
				echo "Writing class {$className} to {$classFile}\n";
				if (@file_put_contents($classFile, $code) === false) {
					$reason = error_get_last()['message'] ?? 'unknown error';
					echo "ERROR: Could not write '{$classFile}': {$reason}\n";
					exit(1);
				}
			}
		}
	}

	// With several XMLs, the schema has the tables of all of them
	foreach ($schemaFiles as $dialect => $schemaFile) {
		[ $generatorClass, $name ] = $dialects[$dialect];
		echo "Writing {$name} schema to {$schemaFile}\n";
		$sql = implode('', array_map(fn($project) => (new $generatorClass($project))->generate(), $projects));
		if (@file_put_contents($schemaFile, $sql) === false) {
			$reason = error_get_last()['message'] ?? 'unknown error';
			echo "ERROR: Could not write '{$schemaFile}': {$reason}\n";
			exit(1);
		}
	}
} catch (\Throwable $e) {
	echo "ERROR: {$e->getMessage()}\n";
	exit(1);
}
