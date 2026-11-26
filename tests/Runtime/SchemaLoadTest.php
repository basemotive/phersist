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

it('writes the default of a VARCHAR column as a literal, and of a TEXT column as an expression', function () {
	// MariaDB reports both kinds of default the same way
	onlyOn('mysql');
	if (str_contains(target()->serverVersion(), 'MariaDB'))
		skip('Only on a MySQL server');

	$project = XMLLoader::load(dirname(__DIR__).'/Generator/fixtures/types/model.xml');
	$database = Database::current();
	$database->dropAllTables();
	$database->loadSchema(Generate::schema($project, target()->dialect));

	$columns = [];
	foreach (db()->query("select COLUMN_NAME, COLUMN_DEFAULT, EXTRA from information_schema.COLUMNS"
			." where TABLE_SCHEMA = database() and TABLE_NAME = 'items' and COLUMN_NAME in ('title', 'code')") as $row)
		$columns[$row['COLUMN_NAME']] = [ $row['COLUMN_DEFAULT'], $row['EXTRA'] ];

	expect($columns['code'])->toBe([ "né'\\1", '' ]);
	expect($columns['title'][1])->toBe('DEFAULT_GENERATED');
});
