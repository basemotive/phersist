<?php

/**
 * The generated files in examples/ must be what the generator produces now,
 * and the sample fixture must be the example model, so the fixture's expected
 * output also covers the example.
 */

use PHersist\Generator\XMLLoader;
use Tests\Support\Generate;

const EXAMPLE_XML = __DIR__.'/../../examples/basic/sample-model.xml';

it('has an up-to-date MySQL schema for the basic example', function () {
	expect(file_get_contents(dirname(EXAMPLE_XML).'/sample-model.sql'))
		->toBe(Generate::schema(XMLLoader::load(EXAMPLE_XML), 'mysql'));
});

it('uses the basic example as the sample fixture', function () {
	expect(file_get_contents(__DIR__.'/fixtures/sample/model.xml'))->toBe(file_get_contents(EXAMPLE_XML));
});
