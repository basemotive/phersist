<?php

namespace Tests\Support;

use PHersist\Generator\Schema\Schema;
use PHersist\Generator\Schema\SchemaBuilder;
use PHersist\Generator\Schema\Table;
use PHersist\Generator\XMLLoader;

/**
 * The shared model XMLs in tests/Runtime/models/: generated once per PHP
 * process, and their schema loaded at the start of each test file that uses
 * them.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Models {
	/** @var array<string, array{schema: Schema, sql: string}> the generated models, by name */
	private static array $generated = [];

	/** @var array<string, Table> the tables of the models loaded now, by name */
	private static array $tables = [];

	/** @var list<string> the names of the models loaded now */
	private static array $loaded = [];

	/** The test file the loaded models are for */
	private static ?string $loadedFor = null;

	/** @var array<string, list<string>> the models each test file uses, by file name */
	private static array $byFile = [];

	public static function dir() : string {
		return dirname(__DIR__).'/Runtime/models';
	}

	/**
	 * Generates a model, unless that already happened in this process: its
	 * classes are written to tests/.generated/<target>/models/<name>/ and
	 * required, and its schema for the target's dialect is kept.
	 *
	 * @param ?string $xml the XML, for a model that's inline in a test file;
	 *   null for tests/Runtime/models/<name>.xml
	 */
	public static function generate(string $name, ?string $xml = null) : void {
		if (isset(self::$generated[$name]))
			return;

		$outDir = Target::generatedDir().'/'.Target::current()->name.'/models/'.$name;
		if (!is_dir($outDir))
			@mkdir($outDir, 0777, true);

		$xmlFile = self::dir()."/{$name}.xml";
		if ($xml !== null) {
			$xmlFile = "{$outDir}/model.xml";
			self::write($xmlFile, $xml);
		} elseif (!file_exists($xmlFile))
			throw new \RuntimeException("No model '{$name}': ".self::dir()."/{$name}.xml doesn't exist");

		$project = XMLLoader::load($xmlFile);
		$sql = Generate::schema($project, Target::current()->dialect);
		$files = [ 'schema.sql' => $sql ];
		foreach (Generate::classes($project) as $className => $code)
			$files["{$className}.php"] = $code;
		if ($xml !== null)
			$files['model.xml'] = $xml;

		// Several processes may generate the same model at once (like PHPStan's
		// workers), so files are only replaced, atomically, and only when they changed
		foreach (glob("{$outDir}/*") ?: [] as $file)
			if (!isset($files[basename($file)]))
				@unlink($file);
		foreach ($files as $file => $content)
			self::write("{$outDir}/{$file}", $content);
		foreach (array_keys($files) as $file)
			if (str_ends_with($file, '.php'))
				require_once "{$outDir}/{$file}";

		self::$generated[$name] = [ 'schema' => SchemaBuilder::build($project), 'sql' => $sql ];
	}

	/**
	 * Writes a file atomically, unless it already has that content.
	 */
	private static function write(string $file, string $content) : void {
		if (is_file($file) && file_get_contents($file) === $content)
			return;
		$temp = $file.'.'.getmypid().'.tmp';
		file_put_contents($temp, $content);
		rename($temp, $file);
	}

	/**
	 * Remembers that a test file uses the given (generated) models.
	 *
	 * @param list<string> $names
	 */
	public static function useIn(string $file, array $names) : void {
		self::$byFile[realpath($file) ?: $file] = $names;
	}

	/**
	 * Makes sure the models of a test file are loaded, before each of its
	 * tests. The first test of a file loads them on a fresh database; test
	 * files without models get none.
	 */
	public static function loadFor(string $file) : void {
		$names = self::$byFile[realpath($file) ?: $file] ?? [];
		if ($names === [])
			self::unload();
		elseif ($names !== self::$loaded || $file !== self::$loadedFor)
			self::load(...$names);
		self::$loadedFor = $file;
	}

	/**
	 * Drops all tables in the test database and loads the schemas of the
	 * given (generated) models.
	 */
	public static function load(string ...$names) : void {
		$database = Database::current();
		$database->dropAllTables();
		self::$tables = [];
		self::$loaded = array_values($names);
		foreach ($names as $name) {
			$model = self::$generated[$name] ?? throw new \LogicException("Model '{$name}' hasn't been generated");
			$database->loadSchema($model['sql']);
			foreach ($model['schema']->tables() as $table) {
				if (isset(self::$tables[$table->name]))
					throw new \LogicException("Models '".implode("', '", $names)."' can't be used together: more than one has a table '{$table->name}'");
				self::$tables[$table->name] = $table;
			}
		}
	}

	/**
	 * The schema of a generated model, for the target's dialect.
	 */
	public static function sql(string $name) : string {
		return (self::$generated[$name] ?? throw new \LogicException("Model '{$name}' hasn't been generated"))['sql'];
	}

	/**
	 * Forgets the loaded models, for test files that don't use any.
	 */
	public static function unload() : void {
		self::$tables = [];
		self::$loaded = [];
	}

	/**
	 * The tables of the loaded models, by name.
	 *
	 * @return array<string, Table>
	 */
	public static function tables() : array {
		return self::$tables;
	}

	public static function table(string $name) : Table {
		return self::$tables[$name] ?? throw new \InvalidArgumentException(
			"Unknown table '{$name}'; the loaded models have: ".implode(', ', array_keys(self::$tables)));
	}
}
