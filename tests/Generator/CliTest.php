<?php

/**
 * The bin/phersist command line tool: options, the files it writes, and its
 * errors and exit codes.
 */

use PHersist\Generator\XMLLoader;
use Tests\Support\Generate;

/**
 * Runs bin/phersist with the given arguments.
 *
 * @param list<string> $args
 * @param ?string $script the bin/phersist to run; by default this repository's
 * @return array{int, string} the exit code and the output
 */
function phersist(array $args, ?string $script = null) : array {
	$command = array_merge([ PHP_BINARY, $script ?? dirname(__DIR__, 2).'/bin/phersist' ], $args);
	$process = proc_open($command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes);
	if ($process === false)
		throw new RuntimeException('Cannot run bin/phersist');
	$output = (string)stream_get_contents($pipes[1]);
	$errors = (string)stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	// bin/phersist reports everything on stdout; PHP warnings or notices would go to stderr
	expect($errors)->toBe('', 'bin/phersist wrote to stderr');
	return [ proc_close($process), $output ];
}

const CLI_MODEL = <<<'XML'
<project database="main" tablestyle="SnakeCase" namespace="Cli\Model">
	<class name="User">
		<dataset><property name="name"/></dataset>
	</class>
	<class name="Group"/>
</project>
XML;

const CLI_OTHER_MODEL = <<<'XML'
<project database="main" tablestyle="SnakeCase" namespace="Cli\Other">
	<class name="Thing"/>
</project>
XML;

/**
 * A new directory with CLI_MODEL in model.xml.
 */
function cliDir() : string {
	$dir = tempModelDir();
	file_put_contents("{$dir}/model.xml", CLI_MODEL);
	return $dir;
}

/**
 * Installs bin/phersist into a new project in $dir, as composer would, with
 * the given PSR-4 autoloading of the project (the "" prefix for the fallback
 * dirs, like in composer.json).
 *
 * @param array<string, list<string>> $psr4 the dirs (relative to $dir) per namespace prefix
 * @return string the installed bin/phersist
 */
function cliProject(string $dir, array $psr4) : string {
	$bin = "{$dir}/vendor/basemotive/phersist/bin";
	mkdir($bin, 0777, true);
	foreach ([ 'phersist', 'phersist.php' ] as $file)
		copy(dirname(__DIR__, 2)."/bin/{$file}", "{$bin}/{$file}");
	$autoload = "<?php\n\nrequire ".var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n"
		."\$loader = new \\Composer\\Autoload\\ClassLoader();\n";
	foreach ($psr4 as $prefix => $dirs)
		$autoload .= '$loader->addPsr4('.var_export($prefix, true).', '.var_export(array_map(fn ($sub) => "{$dir}/{$sub}", $dirs), true).");\n";
	file_put_contents("{$dir}/vendor/autoload.php", $autoload."return \$loader;\n");
	return "{$bin}/phersist";
}

it('shows the usage with --help, including every dialect', function () {
	[ $exit, $output ] = phersist([ '--help' ]);

	expect($exit)->toBe(0);
	expect($output)->toContain('--xml=<FILE>', '--classesdir=<PATH>', '--skip-classes');
	foreach (array_keys(Generate::SCHEMA_GENERATORS) as $dialect)
		expect($output)->toContain("--{$dialect}=<SQLFILE>");
});

it('shows the short usage and fails without --xml', function () {
	[ $exit, $output ] = phersist([]);

	expect($exit)->toBe(1);
	expect($output)->toContain('Usage:', '--help');
});

it('has nothing to do with --skip-classes and no schema option', function () {
	$dir = cliDir();
	[ $exit, $output ] = phersist([ "--xml={$dir}/model.xml", '--skip-classes' ]);

	expect($exit)->toBe(0);
	expect($output)->toBe("Nothing to do.\n");
});

it('writes the classes and every schema', function () {
	$dir = cliDir();
	mkdir("{$dir}/classes");
	$args = [ "--xml={$dir}/model.xml", "--classesdir={$dir}/classes" ];
	foreach (array_keys(Generate::SCHEMA_GENERATORS) as $dialect)
		$args[] = "--{$dialect}={$dir}/{$dialect}.sql";

	[ $exit, $output ] = phersist($args);

	expect($exit)->toBe(0, $output);
	$project = XMLLoader::load("{$dir}/model.xml");
	$classes = Generate::classes($project);
	expect(array_map('basename', glob("{$dir}/classes/*.php")))->toEqualCanonicalizing(array_map(fn ($class) => "{$class}.php", array_keys($classes)));
	foreach ($classes as $class => $code) {
		expect(file_get_contents("{$dir}/classes/{$class}.php"))->toBe($code);
		expect($output)->toContain("Writing class {$class} to {$dir}/classes/{$class}.php");
	}
	foreach (array_keys(Generate::SCHEMA_GENERATORS) as $dialect) {
		expect(file_get_contents("{$dir}/{$dialect}.sql"))->toBe(Generate::schema($project, $dialect));
		expect($output)->toContain("schema to {$dir}/{$dialect}.sql");
	}
});

it('writes only the schema with --skip-classes', function () {
	$dir = cliDir();
	mkdir("{$dir}/classes");
	[ $exit, $output ] = phersist([ "--xml={$dir}/model.xml", "--classesdir={$dir}/classes", '--skip-classes', "--sqlite={$dir}/schema.sql" ]);

	expect($exit)->toBe(0, $output);
	expect(glob("{$dir}/classes/*"))->toBe([]);
	expect(file_get_contents("{$dir}/schema.sql"))->toBe(Generate::schema(XMLLoader::load("{$dir}/model.xml"), 'sqlite'));
});

it('writes the tables of several XMLs into one schema', function () {
	$dir = cliDir();
	file_put_contents("{$dir}/other.xml", CLI_OTHER_MODEL);
	[ $exit, $output ] = phersist([ "--xml={$dir}/model.xml", "--xml={$dir}/other.xml", '--skip-classes', "--mysql={$dir}/schema.sql" ]);

	expect($exit)->toBe(0, $output);
	expect(file_get_contents("{$dir}/schema.sql"))->toBe(
		Generate::schema(XMLLoader::load("{$dir}/model.xml"), 'mysql')
		.Generate::schema(XMLLoader::load("{$dir}/other.xml"), 'mysql'));
});

it('writes the classes to the dir of their namespace', function (string $model, array $psr4, string $classesDir) {
	$dir = tempModelDir();
	file_put_contents("{$dir}/model.xml", $model);
	foreach ([ 'wrong', $classesDir ] as $sub)
		mkdir("{$dir}/{$sub}", 0777, true);

	[ $exit, $output ] = phersist([ "--xml={$dir}/model.xml" ], cliProject($dir, $psr4));

	expect($exit)->toBe(0, $output);
	$classes = Generate::classes(XMLLoader::load("{$dir}/model.xml"));
	expect(array_map('basename', glob("{$dir}/{$classesDir}/*.php")))->toEqualCanonicalizing(array_map(fn ($class) => "{$class}.php", array_keys($classes)));
	expect(glob("{$dir}/wrong/*"))->toBe([]);
})->with([
	'the longest matching prefix' => [ CLI_MODEL, [ 'Cli\\' => [ 'wrong' ], 'Cli\\Model\\' => [ 'src/Model' ] ], 'src/Model' ],
	'the subdir of a prefix' => [ CLI_MODEL, [ 'Cli\\' => [ 'src' ] ], 'src/Model' ],
	'the first dir of a prefix that exists' => [ CLI_MODEL, [ 'Cli\\' => [ 'nothing', 'src' ] ], 'src/Model' ],
	'a fallback dir' => [ CLI_MODEL, [ 'Other\\' => [ 'wrong' ], '' => [ 'src' ] ], 'src/Cli/Model' ],
	'a fallback dir, without namespace' => [ str_replace(' namespace="Cli\\Model"', '', CLI_MODEL), [ 'Cli\\' => [ 'wrong' ], '' => [ 'classes/data' ] ], 'classes/data' ],
]);

it('fails with a readable error', function (callable $args, string $expected) {
	$dir = cliDir();
	[ $exit, $output ] = phersist($args($dir));

	expect($exit)->toBe(1);
	expect(str_replace($dir, '<dir>', $output))->toBe($expected);
})->with([
	'missing XML' => [
		fn ($dir) => [ "--xml={$dir}/nothing.xml" ],
		"ERROR: XML file '<dir>/nothing.xml' not found\n",
	],
	'invalid XML' => [
		function ($dir) {
			file_put_contents("{$dir}/bad.xml", '<project database="main" tablestyle="SnakeCase"><class name="Item" colour="red"/></project>');
			return [ "--xml={$dir}/bad.xml", '--skip-classes', "--mysql={$dir}/schema.sql" ];
		},
		"ERROR: Invalid model XML:\n- line 1: <class> 'Item' has unknown attribute 'colour'\n",
	],
	'missing classes dir' => [
		fn ($dir) => [ "--xml={$dir}/model.xml", "--classesdir={$dir}/nothing" ],
		"ERROR: Classes dir '<dir>/nothing' not found\n",
	],
	'classes dir with several XMLs' => [
		fn ($dir) => [ "--xml={$dir}/model.xml", "--xml={$dir}/model.xml", "--classesdir={$dir}" ],
		"ERROR: --classesdir can only be used with a single --xml; with several, the classes dir of each is detected from its namespace\n",
	],
	'classes dir not detectable' => [
		fn ($dir) => [ "--xml={$dir}/model.xml" ],
		"ERROR: Could not auto-detect classes dir for '<dir>/model.xml'; please specify with --classesdir\n",
	],
	'unwritable schema file' => [
		fn ($dir) => [ "--xml={$dir}/model.xml", '--skip-classes', "--mysql={$dir}/nothing/schema.sql" ],
		"Writing MySQL schema to <dir>/nothing/schema.sql\nERROR: Could not write '<dir>/nothing/schema.sql': file_put_contents(<dir>/nothing/schema.sql): Failed to open stream: No such file or directory\n",
	],
]);
