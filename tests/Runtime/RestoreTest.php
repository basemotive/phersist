<?php

/**
 * Restoring objects: fetch(), lazy loading of datasets, reload() and
 * exists(). See docs/using-model-in-php.md, "Load and update", "Reload",
 * "Check existence" and "Dataset behavior at runtime".
 */

use PHersist\ActiveRecord;
use Tests\Models\Basic\{Post, User};
use Tests\Models\Datasets\Person;

useModel('basic', 'datasets');

describe('fetch()', function () {
	beforeEach(fn () => given([
		'users' => [ [ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30 ] ],
	]));

	it('returns an object without querying the database', function () {
		$log = queries(function () use (&$user) { $user = User::fetch(1); });

		expect($log)->toBe([]);
		expect($user)->toBeInstanceOf(User::class);
		expect($user->id)->toBe(1);
	});

	it('loads the data when a property is first read', function () {
		$user = User::fetch(1);

		expect($user->name)->toBe('Ann');
		expect($user->email)->toBe('ann@example.com');
		expect($user->age)->toBe(30);
	});

	it('returns null for a null id', function () {
		expect(User::fetch(null))->toBeNull();
	});

	it('returns an object for an id without a row, whose properties can\'t be read', function () {
		$user = User::fetch(99);

		expect($user)->toBeInstanceOf(User::class);
		expect(fn () => $user->name)->toThrow(Exception::class);
	});

	it('only creates ActiveRecord classes', function () {
		// ★ 6fab54b
		// Wrong classes are what the test is about
		expect(fn () => ActiveRecord::fetchObject(stdClass::class, 1))->toThrow(Exception::class); // @phpstan-ignore argument.type, argument.templateType
		expect(fn () => ActiveRecord::fetchObject('Tests\Models\Basic\Nothing', 1))->toThrow(Exception::class); // @phpstan-ignore argument.type, argument.templateType
	});

	it('still rejects an id in the constructor after a failed fetchObject()', function () {
		// ★ 6fab54b / BUGS.md #9: the _fetching flag stayed set
		try {
			ActiveRecord::fetchObject(stdClass::class, 1); // @phpstan-ignore argument.type, argument.templateType (a wrong class on purpose)
		} catch (Exception) {
		}

		expect(fn () => new User(1))->toThrow(Exception::class);
	});
});

describe('datasets', function () {
	beforeEach(fn () => given([
		'persons' => [ [ 'person_id' => 1, 'name' => 'Ann', 'nickname' => 'A' ] ],
		'person_details' => [ [ 'person_id' => 1, 'bio' => 'Writes tests', 'age' => 41 ] ],
		'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 38 ] ],
	]));

	it('are loaded as a whole, with one query each', function () {
		$person = Person::fetch(1);

		expect(queries(fn () => $person->bio))->toHaveCount(1);
		expect(queries(fn () => $person->age))->toBe([]);
		expect(queries(fn () => $person->shoeSize))->toHaveCount(1);
		expect(queries(fn () => $person->name))->toHaveCount(1);
		expect(queries(fn () => $person->nickname))->toBe([]);

		expect([ $person->name, $person->nickname, $person->bio, $person->age, $person->shoeSize ])
			->toBe([ 'Ann', 'A', 'Writes tests', 41, 38 ]);
	});

	it('in the base table are loaded separately from each other', function () {
		given([ 'posts' => [ [ 'id' => 1, 'title' => 'Hi', 'body' => 'Long text' ] ] ]);
		$post = Post::fetch(1);

		expect(queries(fn () => $post->title))->toHaveCount(1);
		expect(queries(fn () => $post->body))->toHaveCount(1);
		expect($post->body)->toBe('Long text');
	});
});

describe('reload()', function () {
	beforeEach(fn () => given([ 'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ] ]));

	it('loads the data again', function () {
		$user = User::fetch(1);
		expect($user->name)->toBe('Ann');
		db()->exec("update users set name = 'Anna' where id = 1");

		expect($user->name)->toBe('Ann');
		$user->reload();
		expect($user->name)->toBe('Anna');
	});

	it('refuses to lose uncommitted changes', function () {
		$user = User::fetch(1);
		$user->name = 'Bob';

		expect(fn () => $user->reload())->toThrow(Exception::class);
		expect($user->name)->toBe('Bob');
	});

	it('discards uncommitted changes when asked', function () {
		$user = User::fetch(1);
		$user->name = 'Bob';
		$user->reload(true);

		expect($user->name)->toBe('Ann');
		expect(queries(fn () => $user->commit()))->toBe([]);
		expectUnchanged();
	});

	it('can\'t reload a new object', function () {
		expect(fn () => (new User())->reload())->toThrow(Exception::class);
	});

	it('can\'t reload a deleted object', function () {
		$user = User::fetch(1);
		$user->delete();

		expect(fn () => $user->reload())->toThrow(Exception::class);
	});
});

describe('exists()', function () {
	beforeEach(fn () => given([ 'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ] ]));

	it('tells whether the row exists', function () {
		expect(User::fetch(1)->exists())->toBeTrue();
		expect(User::fetch(2)->exists())->toBeFalse();
	});

	it('is false for a new object', function () {
		expect((new User())->exists())->toBeFalse();
	});

	it('is false after delete()', function () {
		$user = User::fetch(1);
		$user->delete();

		expect($user->exists())->toBeFalse();
	});

	it('sees rows deleted by someone else', function () {
		$user = User::fetch(1);
		expect($user->name)->toBe('Ann');
		db()->exec('delete from users');

		expect($user->exists())->toBeFalse();
	});
});
