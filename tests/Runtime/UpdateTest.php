<?php

/**
 * Updating objects: only changed columns are written, update_on="modify",
 * and objects whose row is gone. See docs/using-model-in-php.md, "Load and
 * update".
 */

use Tests\Models\Basic\{Post, User};
use Tests\Models\Datasets\{Note, Person};

useModel('basic', 'datasets');

beforeEach(fn () => given([
	'users' => [
		[ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30 ],
		[ 'id' => 2, 'name' => 'Bob' ],
	],
	'posts' => [ [ 'id' => 10, 'title' => 'Hi', 'body' => 'Text', 'author_id' => 1 ] ],
	'persons' => [ [ 'person_id' => 1, 'name' => 'Cy' ] ],
	'person_details' => [ [ 'person_id' => 1, 'bio' => 'Old bio', 'age' => 50 ] ],
	'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 44 ] ],
	'memo' => [ [ 'note_no' => 1, 'text' => 'Note', 'views' => 3, 'created_at' => '2026-01-01 10:00:00' ] ],
]));

it('writes the changed property', function () {
	$user = User::fetch(1);
	$user->email = 'ann@example.org';
	$user->commit();

	expectState([ 'users' => [ [ 'id' => 1, 'email' => 'ann@example.org' ], [ 'id' => 2 ] ] ]);
});

it('only writes the changed columns', function () {
	$user = User::fetch(1);
	$user->email = 'ann@example.org';
	$log = queries(fn () => $user->commit());

	$updates = array_values(array_filter($log, fn ($sql) => str_starts_with(strtolower($sql), 'update')));
	expect($updates)->toHaveCount(1);
	expect($updates[0])->toContain('email');
	expect($updates[0])->not->toContain('`name`');
	expect($updates[0])->not->toContain('`age`');
});

it('doesn\'t overwrite columns changed by someone else', function () {
	$user = User::fetch(1);
	expect($user->name)->toBe('Ann');
	db()->exec("update users set name = 'Anna' where id = 1");

	$user->email = 'ann@example.org';
	$user->commit();

	expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Anna', 'email' => 'ann@example.org' ], [ 'id' => 2 ] ] ]);
});

it('writes null', function () {
	$user = User::fetch(1);
	$user->email = null;
	$user->commit();

	expectState([ 'users' => [ [ 'id' => 1, 'email' => null ], [ 'id' => 2 ] ] ]);
});

it('doesn\'t count assigning the current value as a change', function () {
	$user = User::fetch(1);
	expect($user->name)->toBe('Ann');
	$user->name = 'Ann';
	$user->age = '30'; // converted to the int it already is

	expect(queries(fn () => $user->commit()))->toBe([]);
});

it('counts an assignment to a dataset that isn\'t loaded as a change', function () {
	$user = User::fetch(1);
	$user->name = 'Ann';
	$user->commit();

	expectUnchanged();
});

it('writes the datasets in other tables', function () {
	$person = Person::fetch(1);
	$person->bio = 'New bio';
	$person->shoeSize = 45;
	$person->commit();

	expectState([
		'person_details' => [ [ 'person_id' => 1, 'bio' => 'New bio' ] ],
		'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 45 ] ],
	]);
});

it('can\'t set a required property to null', function () {
	$user = User::fetch(1);

	expect(function () use ($user) {
		$user->name = null;
		$user->commit();
	})->toThrow(Exception::class);
	expectUnchanged();
});

it('changes a reference', function () {
	$post = Post::fetch(10);
	$post->author = User::fetch(2);
	$post->commit();

	expectState([ 'posts' => [ [ 'id' => 10, 'author_id' => 2 ] ] ]);
});

it('keeps the creation time of update_on="create"', function () {
	$note = Note::fetch(1);
	$note->text = 'Changed';
	$note->commit();

	expectState([ 'memo' => [ [ 'note_no' => 1, 'text' => 'Changed' ] ] ]);
});

it('commits a second change after the first', function () {
	$user = User::fetch(1);
	$user->name = 'Anna';
	$user->commit();
	$user->name = 'Annie';
	$user->commit();

	expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Annie' ], [ 'id' => 2 ] ] ]);
});

describe('an object without a row', function () {
	it('can\'t be committed', function () {
		// ★ 28b90a5 / BUGS-ALREADY-FIXED4 #8
		$user = User::fetch(99);
		$user->name = 'Nobody';

		expect(fn () => $user->commit())->toThrow(Exception::class);
		expectUnchanged();
	});

	it('can\'t be committed after someone else deleted the row', function () {
		$user = User::fetch(1);
		expect($user->name)->toBe('Ann');
		db()->exec('delete from users where id = 1');

		$user->name = 'Anna';
		expect(fn () => $user->commit())->toThrow(Exception::class);
	});

	it('is told apart from an update that changes no values', function () {
		// MySQL counts only changed rows, unless PDO::MYSQL_ATTR_FOUND_ROWS is set
		$user = User::fetch(1);
		$user->name = 'Ann'; // not loaded yet, so it counts as a change, but the value is the same

		$user->commit();

		expectUnchanged();
	});
});
