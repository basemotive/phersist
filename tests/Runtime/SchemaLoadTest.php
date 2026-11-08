<?php

/**
 * Every fixture's schema, in the target's dialect, must load on the target
 * database, with exactly the tables and columns the schema model describes,
 * and loading it again (DROP TABLE IF EXISTS first) must work too.
 *
 * It tests the generator's fixtures, but it's in the Runtime suite because it
 * depends on the target database: it catches SQL that only some databases
 * reject.
 */

use PHersist\Generator\Schema\SchemaBuilder;
use PHersist\Generator\XMLLoader;
use Tests\Support\Database;
use Tests\Support\Generate;

$fixtures = [];
foreach (glob(dirname(__DIR__).'/Generator/fixtures/*/model.xml') ?: [] as $xmlFile)
	$fixtures[basename(dirname($xmlFile))] = [ $xmlFile ];

it('loads on the target database', function (string $xmlFile) {
	$project = XMLLoader::load($xmlFile);
	$sql = Generate::schema($project, target()->dialect);
	$database = Database::current();

	$database->dropAllTables();
	$database->loadSchema($sql);
	$database->loadSchema($sql);

	$expected = [];
	foreach (SchemaBuilder::build($project)->tables() as $table)
		$expected[$table->name] = array_map(fn ($column) => $column->name, $table->columns);
	ksort($expected);

	$actual = [];
	foreach ($database->tableNames() as $table)
		$actual[$table] = $database->columnNames($table);

	expect($actual)->toBe($expected);
})->with($fixtures);
