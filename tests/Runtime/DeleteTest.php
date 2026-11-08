<?php

/**
 * Deleting objects (hard delete): the rows that are removed, and the object
 * afterwards. Reference cleanup has its own test file. See
 * docs/using-model-in-php.md, "Delete".
 */

use Tests\Models\Basic\{Group, Post, User};
use Tests\Models\Datasets\{Marker, Person};

useModel('basic', 'datasets');

beforeEach(fn () => given([
	'users' => [
		[ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com' ],
		[ 'id' => 2, 'name' => 'Bob' ],
	],
	'groups' => [ [ 'id' => 1, 'name' => 'Admins' ] ],
	'user_groups' => [ [ 'user_id' => 1, 'group_id' => 1 ], [ 'user_id' => 2, 'group_id' => 1 ] ],
	'persons' => [ [ 'person_id' => 1, 'name' => 'Cy' ], [ 'person_id' => 2, 'name' => 'Di' ] ],
	'person_details' => [ [ 'person_id' => 1, 'bio' => 'Bio' ], [ 'person_id' => 2, 'bio' => 'Other' ] ],
	'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 40 ] ],
	'markers' => [ [ 'marker_id' => 1 ] ],
]));

it('removes the row', function () {
	User::fetch(2)->delete();

	expectState([
		'users' => [ [ 'id' => 1 ] ],
		'user_groups' => [ [ 'user_id' => 1, 'group_id' => 1 ] ],
	]);
});

it('removes the rows of datasets in other tables', function () {
	Person::fetch(1)->delete();

	expectState([
		'persons' => [ [ 'person_id' => 2 ] ],
		'person_details' => [ [ 'person_id' => 2 ] ],
		'person_extras' => [],
	]);
});

it('removes the rows of owned join tables', function () {
	Group::fetch(1)->delete();

	expectState([ 'groups' => [], 'user_groups' => [] ]);
});

it('works for a class without properties', function () {
	Marker::fetch(1)->delete();

	expectState([ 'markers' => [] ]);
});

describe('the deleted object', function () {
	it('loses its id and is marked deleted', function () {
		$user = User::fetch(1);
		$user->delete();

		expect($user->id)->toBeNull();
		expect($user->isDeleted())->toBeTrue();
	});

	it('can still read the properties it had loaded; the others are null', function () {
		$person = Person::fetch(1);
		expect($person->name)->toBe('Cy');
		$person->delete();

		expect($person->name)->toBe('Cy');
		expect($person->bio)->toBeNull();
	});

	it('can\'t be changed or committed', function () {
		$user = User::fetch(1);
		$user->delete();

		expect(function () use ($user) { $user->name = 'Again'; })->toThrow(Exception::class);
		expect(fn () => $user->commit())->toThrow(Exception::class);
		expectState([ 'users' => [ [ 'id' => 2 ] ], 'user_groups' => [ [ 'user_id' => 2, 'group_id' => 1 ] ] ]);
	});

	it('can be deleted again without effect', function () {
		$user = User::fetch(1);
		$user->delete();

		expect(queries(fn () => $user->delete()))->toBe([]);
	});
});

it('does nothing for a new object', function () {
	$user = new User();
	$user->name = 'New';

	expect(queries(fn () => $user->delete()))->toBe([]);
	expectUnchanged();
});

it('isn\'t isDeleted() before delete()', function () {
	expect(User::fetch(1)->isDeleted())->toBeFalse();
	expect((new User())->isDeleted())->toBeFalse();
});

it('sets references from other objects to null', function () {
	given([
		'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ],
		'posts' => [ [ 'id' => 10, 'title' => 'Hi', 'author_id' => 1 ], [ 'id' => 11, 'title' => 'Other', 'author_id' => null ] ],
	]);

	User::fetch(1)->delete();

	expectState([ 'users' => [], 'posts' => [ [ 'id' => 10, 'author_id' => null ], [ 'id' => 11 ] ] ]);
});

it('of an object without a row changes nothing', function () {
	User::fetch(99)->delete();

	expectUnchanged();
});

it('of a post leaves its author alone', function () {
	given([
		'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ],
		'posts' => [ [ 'id' => 10, 'title' => 'Hi', 'author_id' => 1 ] ],
	]);

	Post::fetch(10)->delete();

	expectState([ 'posts' => [] ]);
});
