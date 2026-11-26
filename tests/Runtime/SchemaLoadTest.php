<?php

/**
 * Every fixture's schema, in the target's dialect, must load on the target
 * database, with exactly the tables and columns the schema model describes,
 * and loading it again (DROP TABLE IF EXISTS first) must work too. With foreign
 * keys, on the databases that have them, the database must end up with
 * exactly those keys.
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

it('has exactly the foreign keys of the schema model, on the indexes it has without them', function (string $xmlFile) {
	onlyOn('mysql', 'mariadb');

	// With the model XMLs it depends on: its keys refer to their tables
	$projects = XMLLoader::load($xmlFile)->allProjects();
	$database = Database::current();
	$load = function (bool $foreignKeys) use ($projects, $database) : void {
		foreach ($projects as $project)
			$database->loadSchema(Generate::schema($project, target()->dialect, $foreignKeys));
	};
	$indexes = fn () : array => db()->query('select TABLE_NAME as t, INDEX_NAME as i, SEQ_IN_INDEX as s, COLUMN_NAME as c'
		.' from information_schema.STATISTICS where TABLE_SCHEMA = database() order by 1, 2, 3')->fetchAll(PDO::FETCH_NUM);

	$database->dropAllTables();
	$load(false);
	$withoutKeys = $indexes();

	// Over the tables without keys, and then over those with keys, which
	// drops tables that others refer to
	$load(true);
	$load(true);

	// InnoDB adds an index for a key whose column has none
	expect($indexes())->toBe($withoutKeys);

	$expected = [];
	foreach ($projects as $project)
		foreach (SchemaBuilder::build($project)->tables() as $table)
			foreach ($table->foreignKeys as $key)
				$expected[$key->name] = [ $table->name, $key->column, $key->referencedTable, $key->referencedColumn, $key->onDelete ];
	ksort($expected);

	$actual = [];
	foreach (db()->query('select r.CONSTRAINT_NAME as n, r.TABLE_NAME as t, k.COLUMN_NAME as c, r.REFERENCED_TABLE_NAME as rt,'
			.' k.REFERENCED_COLUMN_NAME as rc, r.DELETE_RULE as d'
			.' from information_schema.REFERENTIAL_CONSTRAINTS r'
			.' inner join information_schema.KEY_COLUMN_USAGE k on k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA'
			.' and k.CONSTRAINT_NAME = r.CONSTRAINT_NAME and k.TABLE_NAME = r.TABLE_NAME'
			.' where r.CONSTRAINT_SCHEMA = database()')->fetchAll(PDO::FETCH_NUM) as $row)
		$actual[(string)$row[0]] = array_slice($row, 1);
	ksort($actual);

	expect($actual)->toBe($expected);
	foreach (array_keys($expected) as $name)
		expect(strlen($name))->toBeLessThanOrEqual(64);
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
