<?php

/**
 * The generators against the fixtures: fixtures/<name>/model.xml must generate
 * exactly the files in fixtures/<name>/expected/: <Class>.php for every class
 * (and PHersistReferences.php), and <dialect>.sql for every schema dialect.
 *
 * After an intended change in the output, PHERSIST_UPDATE_EXPECTED=1 rewrites
 * the expected files; review their diff before committing.
 */

use Tests\Support\Generate;

$fixtures = [];
foreach (glob(__DIR__.'/fixtures/*/model.xml') ?: [] as $xmlFile)
	$fixtures[basename(dirname($xmlFile))] = [ $xmlFile ];

it('generates the expected output', function (string $xmlFile) {
	$expectedDir = dirname($xmlFile).'/expected';
	$output = Generate::all($xmlFile);

	if (getenv('PHERSIST_UPDATE_EXPECTED')) {
		foreach (glob("{$expectedDir}/*") ?: [] as $file)
			unlink($file);
		foreach ($output as $file => $content)
			file_put_contents("{$expectedDir}/{$file}", $content);
		skip('Expected output updated');
	}

	$expectedFiles = array_map('basename', glob("{$expectedDir}/*") ?: []);
	sort($expectedFiles);
	$generatedFiles = array_keys($output);
	sort($generatedFiles);
	expect($generatedFiles)->toBe($expectedFiles);

	foreach ($output as $file => $content)
		expect($content)->toBe(file_get_contents("{$expectedDir}/{$file}"), "{$file} differs from expected/{$file}");
})->with($fixtures);
