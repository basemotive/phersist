<?php

/**
 * XMLAutoloader: classes generated from the XML and loaded on first use,
 * without generated files. See docs/advanced-features.md, "XMLAutoloader in
 * development workflows".
 */

use PHersist\Generator\XMLAutoloader;
use PHersist\Generator\XMLLoader;
use Tests\Support\Database;
use Tests\Support\Generate;

/**
 * Writes a model XML and registers an XMLAutoloader for it.
 */
function autoload(string $xml) : string {
	$file = tempModelDir().'/model.xml';
	file_put_contents($file, $xml);
	new XMLAutoloader($file);
	return $file;
}

it('loads the classes of the XML when they are first used', function () {
	// ★ a86110d / BUGS-ALREADY-FIXED1 #9: classes with a namespace were never loaded
	$file = autoload(<<<'XML'
	<project database="main" tablestyle="SnakeCase" namespace="Tests\Models\Autoloaded">
		<class name="AutoThing">
			<dataset autoload="true"><property name="name"/></dataset>
		</class>
	</project>
	XML);
	expect(class_exists('Tests\Models\Autoloaded\AutoThing', false))->toBeFalse();

	expect(class_exists('Tests\Models\Autoloaded\AutoThing'))->toBeTrue();
	expect(class_exists('Tests\Models\Autoloaded\PHersistReferences'))->toBeTrue();
	expect(class_exists('Tests\Models\Autoloaded\Nothing'))->toBeFalse();
	expect(class_exists('Tests\Models\Autoloaded\Sub\AutoThing'))->toBeFalse();

	(new Database(db(), target()->dialect))->loadSchema(Generate::schema(XMLLoader::load($file), target()->dialect));
	$class = 'Tests\Models\Autoloaded\AutoThing';
	$thing = new $class(); // @phpstan-ignore class.notFound (the XMLAutoloader defines it at runtime)
	$thing->name = 'Loaded';
	$thing->commit();
	$thing->reload();
	expect($thing->name)->toBe('Loaded');
	$thing->delete();
});

it('loads classes without a namespace', function () {
	autoload(<<<'XML'
	<project database="main" tablestyle="SnakeCase">
		<class name="AutoGlobalThing"/>
	</project>
	XML);

	expect(class_exists('AutoGlobalThing'))->toBeTrue();
	expect(class_exists('Tests\Models\Autoloaded\AutoGlobalThing'))->toBeFalse();
});
