<?php

/**
 * A simple script for generating class files and SQL schemas
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */

$autoloader = require(__DIR__ . '/../vendor/autoload.php');

use PHersist\Generator\ARGenerator;
use PHersist\Generator\MySQLGenerator;

$options = getopt('h', [
	'xml:',
	'classesdir:',
	'includesdir:',
	'mysql:',
	'skip-classes',
	'help',
]);

$xmlFile = $options['xml'] ?? null;
$classesDir = $options['classesdir'] ?? null;
$includesDir = $options['includesdir'] ?? null;
$mysqlFile = $options['mysql'] ?? null;
$skipClasses = isset($options['skip-classes']);
$help = isset($options['h']) || isset($options['help']);

if ($help) {
	echo "Usage: {$argv[0]} --xml=<file>\n";
    echo "Where:\n";
    echo "  --xml=<FILE>\n";
    echo "    the project's data structure\n";
    echo "Optional parameters:\n";
    echo "  --classesdir=<PATH>\n";
    echo "    the path where the classes should be written\n";
    echo "  --includesdir=<PATH>\n";
    echo "    the path where Classname.include.php files can be found\n";
    echo "  --mysql=<SQLFILE>\n";
    echo "    where to write the MySQL schema\n";
    echo "  --skip-classes\n";
    echo "    don't generate the class files\n";
    exit;
}

if ($skipClasses && $mysqlFile == null) {
	echo "Nothing to do.\n";
	exit(0);
}
if (!$xmlFile) {
    echo "Usage: {$argv[0]} --xml=<file>\n";
    echo "More info: {$argv[0]} --help\n";
    exit(1);
}
if (!file_exists($xmlFile)) {
	echo "ERROR: XML file '{$xmlFile}' not found\n";
	exit(1);
}
if ($classesDir && (!file_exists($classesDir) || !is_dir($classesDir))) {
	echo "ERROR: Classes dir '{$classesDir}' not found\n";
	exit(1);
}
if ($includesDir && (!file_exists($includesDir) || !is_dir($includesDir))) {
	echo "ERROR: Includes dir '{$includesDir}' not found\n";
	exit(1);
}

$xml = file_get_contents($xmlFile);
$arGenerator = new ARGenerator($xml, $includesDir);

if (!$skipClasses && !$classesDir) {
	// automagically figure out where to put the classes
	$namespace = $arGenerator->getNamespace();
 	$prefixes = $autoloader->getPrefixesPsr4();
    uksort($prefixes, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($prefixes as $prefix => $dirs) {
        if (str_starts_with($namespace, $prefix)) {
            $relative = str_replace('\\', '/', substr($namespace, strlen($prefix)));
            $classesDir = realpath("{$dirs[0]}/{$relative}");
            // realpath may not actually exist, so only break on success
            if ($classesDir)
            	break;
        }
    }
}

if (!$skipClasses && !$classesDir) {
	echo "ERROR: Could not auto-detect classes dir; please specify with --classesdir\n";
	exit(1);
}

if (!$skipClasses) {
	$metasets = $arGenerator->generate();
	foreach ($metasets as $className => $meta) {
	    echo "Writing class {$className} to {$classesDir}/{$className}.php\n";
	    file_put_contents("{$classesDir}/{$className}.php", $meta);
	}
}

if ($mysqlFile) {
	echo "Writing MySQL schema to {$mysqlFile}\n";
	$mysqlGenerator = new MySQLGenerator($xml);
	$sql = $mysqlGenerator->generate();
	file_put_contents($mysqlFile, $sql);
}