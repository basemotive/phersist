<?php

/**
 * Creating objects: new + commit(), defaults, required properties, references
 * to other objects, and what's stored. See docs/using-model-in-php.md,
 * "Create and insert".
 */

use Tests\Models\Basic\{Post, User};
use Tests\Models\Datasets\{Marker, Note, Person, Profile};

useModel('basic', 'datasets');

describe('a new object', function () {
	it('has no id until it is committed', function () {
		$user = new User();
		$user->name = 'Ann';
		expect($user->id)->toBeNull();

		$user->commit();

		expect($user->id)->toBe(1);
		expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Ann', 'email' => null, 'age' => null ] ] ]);
	});

	it('gets the next id', function () {
		given([ 'users' => [ [ 'id' => 7, 'name' => 'Ann' ] ] ]);

		$user = new User();
		$user->name = 'Bob';
		$user->commit();

		expect($user->id)->toBe(8);
		expectState([ 'users' => [ [ 'id' => 7 ], [ 'id' => 8, 'name' => 'Bob' ] ] ]);
	});

	it('can\'t be created with an id', function () {
		expect(fn () => new User(1))->toThrow(Exception::class);
	});

	it('starts with the defaults of the model, and stores them', function () {
		$note = new Note();
		expect($note->text)->toBe('empty');
		expect($note->views)->toBe(0);

		$note->commit();

		expectState([ 'memo' => [ [ 'note_no' => 1, 'text' => 'empty', 'views' => 0, 'created_at' => now() ] ] ]);
	});

	it('gets the creation time for update_on="create", also when required', function () {
		$note = new Note();
		$note->commit();

		expect($note->createdAt)->toBeInstanceOf(DateTimeInterface::class);
		expect($note->createdAt->getTimestamp())->toBeGreaterThanOrEqual(time() - 2);
	});

	it('has null for properties without a default', function () {
		$user = new User();
		expect($user->email)->toBeNull();
	});

	it('is inserted on the first commit, even when nothing was set', function () {
		(new Marker())->commit();
		(new Marker())->commit();

		expectState([ 'markers' => [ [ 'marker_id' => 1 ], [ 'marker_id' => 2 ] ] ]); // ★ 2cffefb, DEFAULT VALUES
	});
});

describe('required properties', function () {
	it('must be set before the first commit', function () {
		$user = new User();
		$user->email = 'ann@example.com';

		expect(fn () => $user->commit())->toThrow(Exception::class);
		expect($user->id)->toBeNull();
		expectUnchanged();
	});

	it('can be set after a failed commit', function () {
		$user = new User();
		try {
			$user->commit();
		} catch (Exception) {
		}
		$user->name = 'Ann';
		$user->commit();

		expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ] ]);
	});
});

describe('datasets in other tables', function () {
	it('are stored with the id of the new object', function () {
		// ★ BUGS-ALREADY-FIXED1 #1: datasets in a second table got the wrong id
		given([ 'persons' => [ [ 'person_id' => 4, 'name' => 'Old' ] ], 'person_details' => [ [ 'person_id' => 4, 'bio' => 'old' ] ] ]);

		$person = new Person();
		$person->name = 'Ann';
		$person->bio = 'Writes tests';
		$person->shoeSize = 38;
		$person->commit();

		expect($person->id)->toBe(5);
		expectState([
			'persons' => [ [ 'person_id' => 4 ], [ 'person_id' => 5, 'name' => 'Ann', 'nickname' => null ] ],
			'person_details' => [ [ 'person_id' => 4 ], [ 'person_id' => 5, 'bio' => 'Writes tests', 'age' => null ] ],
			'person_extras' => [ [ 'person_id' => 5, 'shoe_size' => 38 ] ],
		]);
	});

	it('get a row on insert, also when none of their properties were set', function () {
		$person = new Person();
		$person->name = 'Ann';
		$person->commit();

		expectState([
			'persons' => [ [ 'person_id' => 1, 'name' => 'Ann' ] ],
			'person_details' => [ [ 'person_id' => 1, 'bio' => null, 'age' => null ] ],
			'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => null ] ],
		]);
	});

	it('work for a class with all its properties in another table', function () {
		// ★ BUGS-ALREADY-FIXED4 #5: an insert with an empty column list
		$profile = new Profile();
		$profile->motto = 'Test everything';
		$profile->commit();

		expectState([
			'profiles' => [ [ 'profile_id' => 1 ] ],
			'profile_data' => [ [ 'profile_id' => 1, 'motto' => 'Test everything' ] ],
		]);
	});

	it('read back as stored', function () {
		$person = new Person();
		$person->name = 'Ann';
		$person->age = 41;
		$person->commit();

		$fresh = Person::fetch($person->id);
		$fresh->reload(); // with the ObjectCache, fetch() returns the same instance

		expect($fresh->name)->toBe('Ann');
		expect($fresh->age)->toBe(41);
		expect($fresh->bio)->toBeNull();
		expect($fresh->shoeSize)->toBeNull();
	});
});

describe('values', function () {
	it('are converted on assignment', function () {
		$user = new User();
		// The generated @property says ?int, but assignment converts other values, as the docs say
		$user->age = '42'; // @phpstan-ignore assign.propertyType

		expect($user->age)->toBe(42);
	});

	it('keep multibyte text exactly', function (string $text) {
		$user = new User();
		$user->name = $text;
		$user->commit();

		expectState([ 'users' => [ [ 'id' => 1, 'name' => $text ] ] ]);
		$user->reload();
		expect($user->name)->toBe($text);
	})->with([
		'diacritics' => [ 'Zoë Ünïcødé, façade, naïve, Ångström' ],
		'emoji (4-byte UTF-8)' => [ '🎉 party 👩‍💻' ],
		'CJK' => [ '日本語のテキスト' ],
		'combining characters' => [ "e\u{0301}" ],
		'trailing spaces' => [ 'Ann  ' ],
		'quotes and backslashes' => [ "It's a \\ \"test\" ' */" ],
		'empty string' => [ '' ],
	]);

	it('keep a long text', function () {
		// 65535 bytes is the most a MySQL TEXT column holds
		$text = str_repeat('abcdefghij', 6553).'abcde';
		$post = new Post();
		$post->title = 'Long';
		$post->body = $text;
		$post->commit();

		$post->reload();
		expect($post->body)->toBe($text);
	});

	it('can be stored in a column named id when the primary key isn\'t', function () {
		// ★ BUGS-ALREADY-FIXED4 #1: the column got the object's id on update
		$person = new Person();
		$person->name = 'Ann';
		$person->externalId = 'EXT-9';
		$person->commit();
		$person->externalId = 'EXT-10';
		$person->commit();

		expectState([
			'persons' => [ [ 'person_id' => 1, 'name' => 'Ann', 'nickname' => null, 'id' => 'EXT-10' ] ],
			'person_details' => [ [ 'person_id' => 1, 'bio' => null, 'age' => null ] ],
			'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => null ] ],
		]);
	});
});

describe('isset() and empty()', function () {
	it('work like on regular properties', function () {
		// ★ BUGS-ALREADY-FIXED2 #1
		$user = new User();
		$user->name = 'Ann';
		$user->age = 0;

		expect(isset($user->name))->toBeTrue();
		expect(isset($user->email))->toBeFalse();
		expect(isset($user->nothing))->toBeFalse();
		expect(empty($user->age))->toBeTrue();
		expect(empty($user->name))->toBeFalse();
		expect(isset($user['name']))->toBeTrue();
		expect(isset($user['email']))->toBeFalse();
	});
});

describe('unknown properties', function () {
	it('can\'t be assigned', function () {
		$user = new User();
		expect(function () use ($user) { $user->nothing = 1; })->toThrow(Exception::class); // @phpstan-ignore property.notFound (what the test is about)
	});

	it('can\'t be read', function () {
		$user = new User();
		// @phpstan-ignore property.notFound, argument.unresolvableType, function.unresolvableReturnType (what the test is about)
		expect(fn () => $user->nothing)->toThrow(Exception::class);
	});
});

describe('references to other objects', function () {
	it('are stored as the id', function () {
		given([ 'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ] ]);

		$post = new Post();
		$post->title = 'Hi';
		$post->author = User::fetch(1);
		$post->commit();

		expectState([ 'posts' => [ [ 'id' => 1, 'title' => 'Hi', 'body' => null, 'author_id' => 1 ] ] ]);
	});

	it('can\'t be to an object that isn\'t committed yet', function () {
		// ★ BUGS-ALREADY-FIXED1 #14: stored as NULL
		$user = new User();
		$user->name = 'Ann';
		$post = new Post();
		$post->title = 'Hi';
		$post->author = $user;

		expect(fn () => $post->commit())->toThrow(Exception::class);
		expect($post->id)->toBeNull();
		expectUnchanged();
	});

	it('can\'t be to an object whose row doesn\'t exist', function () {
		// ★ BUGS-ALREADY-FIXED4 #10
		$post = new Post();
		$post->title = 'Hi';
		$post->author = User::fetch(99);

		expect(fn () => $post->commit())->toThrow(Exception::class);
		expect($post->id)->toBeNull();
		expectUnchanged();
	});
});

describe('commit()', function () {
	it('does nothing when nothing changed', function () {
		$user = new User();
		$user->name = 'Ann';
		$user->commit();

		expect(queries(fn () => $user->commit()))->toBe([]);
	});
});
