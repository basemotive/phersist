<?php

/**
 * Loading objects from your own queries with fetchObject() and a row with
 * ds_<dataset>#<column> aliases. See docs/using-model-in-php.md, "Loading
 * objects from your own queries".
 */

use PHersist\ActiveRecord;
use PHersist\ObjectCache;
use Tests\Models\Datasets\Person;

useModel('datasets');

beforeEach(fn () => given([
	'persons' => [ [ 'person_id' => 1, 'name' => 'Ann', 'nickname' => 'A', 'id' => 'EXT' ] ],
	'person_details' => [ [ 'person_id' => 1, 'bio' => 'Bio', 'age' => 41 ] ],
	'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 38 ] ],
]));

it('fills the datasets in the row, by name and by position', function () {
	// ★ 8740068
	$person = ActiveRecord::fetchObject(Person::class, 1, [
		'person_id' => '1',
		'ds_core#name' => 'Ann', 'ds_core#nickname' => 'A', 'ds_core#id' => 'EXT',
		'ds_3#shoe_size' => '38',
	]);

	expect(queries(fn () => [ $person->name, $person->nickname, $person->externalId, $person->shoeSize ]))->toBe([]);
	expect([ $person->name, $person->nickname, $person->externalId, $person->shoeSize ])->toBe([ 'Ann', 'A', 'EXT', 38 ]);
	expect(queries(fn () => $person->bio))->toHaveCount(1);
});

it('works with the rows of a real query', function () {
	$rows = db()->query('select persons.person_id, persons.name as "ds_core#name", persons.nickname as "ds_core#nickname", persons.id as "ds_core#id",
		person_details.bio as "ds_details#bio", person_details.age as "ds_details#age"
		from persons join person_details on person_details.person_id = persons.person_id')->fetchAll(PDO::FETCH_ASSOC);

	$person = ActiveRecord::fetchObject(Person::class, (int)$rows[0]['person_id'], $rows[0]);

	expect(queries(fn () => [ $person->name, $person->age ]))->toBe([]);
	expect($person->age)->toBe(41);
});

it('rejects a row with only part of a dataset, assigning nothing', function () {
	ObjectCache::setEnabled(true); // to see the object the row was for
	$person = Person::fetch(1);
	$row = [ 'ds_details#bio' => 'Other', 'ds_details#age' => '1', 'ds_core#name' => 'Other' ];

	expect(fn () => ActiveRecord::fetchObject(Person::class, 1, $row))->toThrow(Exception::class, 'only part of a dataset');
	expect([ $person->name, $person->bio ])->toBe([ 'Ann', 'Bio' ]);
});

it('rejects ds_ columns that don\'t belong to a dataset, assigning nothing', function () {
	ObjectCache::setEnabled(true);
	$person = Person::fetch(1);
	$row = [ 'ds_details#bio' => 'Other', 'ds_details#age' => '1', 'ds_details#nmae' => 'typo' ];

	expect(fn () => ActiveRecord::fetchObject(Person::class, 1, $row))->toThrow(Exception::class, "don't belong");
	expect($person->bio)->toBe('Bio');
});

it('ignores other columns', function () {
	$person = ActiveRecord::fetchObject(Person::class, 1, [ 'something' => 'x', 'ds_3#shoe_size' => '40' ]);

	expect($person->shoeSize)->toBe(40);
});

it('gives an object in use the values of the row, but keeps its uncommitted changes', function () {
	ObjectCache::setEnabled(true);
	$person = Person::fetch(1);
	expect($person->bio)->toBe('Bio');
	$person->age = 42;

	$same = ActiveRecord::fetchObject(Person::class, 1, [ 'ds_details#bio' => 'New bio', 'ds_details#age' => '50' ]);

	expect($same)->toBe($person);
	expect($person->bio)->toBe('New bio');
	expect($person->age)->toBe(42);
});

it('doesn\'t check that the row exists', function () {
	$person = ActiveRecord::fetchObject(Person::class, 99, [ 'ds_3#shoe_size' => '40' ]);

	expect($person->shoeSize)->toBe(40);
	expect($person->exists())->toBeFalse();
});
