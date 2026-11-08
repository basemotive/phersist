<?php

/**
 * Copying objects with clone, and storing them with serialize(), like in a
 * session. See docs/using-model-in-php.md, "Copy".
 */

use Tests\Models\Datasets\{Note, Person};
use Tests\Models\Maps\Account;
use Tests\Models\Relations\{Article, Author, Tag};

useModel('datasets', 'maps', 'relations');

beforeEach(fn () => given([
	'persons' => [ [ 'person_id' => 1, 'name' => 'Ann', 'nickname' => 'A' ] ],
	'person_details' => [ [ 'person_id' => 1, 'bio' => 'Bio', 'age' => 41 ] ],
	'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 38 ] ],
	'memo' => [ [ 'note_no' => 1, 'text' => 'Note', 'views' => 5, 'created_at' => '2020-01-01 00:00:00' ] ],
	'accounts' => [ [ 'id' => 1, 'name' => 'Acc' ] ],
	'account_flags' => [ [ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ] ],
	'authors' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
	'articles' => [ [ 'id' => 1, 'title' => 'First', 'author_id' => 1 ] ],
	'tags' => [ [ 'id' => 1, 'name' => 'php' ], [ 'id' => 2, 'name' => 'orm' ] ],
	'article_tags' => [ [ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ], [ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ] ],
	'likes' => [ [ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ] ],
	'comments' => [ [ 'id' => 1, 'body' => 'C', 'subject_type' => 'Article', 'subject_id' => 1 ] ],
]));

describe('a clone', function () {
	it('is a new object that commit() inserts, with all datasets', function () {
		// ★ c41b4b2
		$clone = clone Person::fetch(1);
		expect($clone->id)->toBeNull();
		$clone->commit();

		expectState([
			'persons' => [ [ 'person_id' => 1 ], [ 'person_id' => 2, 'name' => 'Ann', 'nickname' => 'A' ] ],
			'person_details' => [ [ 'person_id' => 1 ], [ 'person_id' => 2, 'bio' => 'Bio', 'age' => 41 ] ],
			'person_extras' => [ [ 'person_id' => 1 ], [ 'person_id' => 2, 'shoe_size' => 38 ] ],
		]);
	});

	it('includes the original\'s uncommitted changes, and leaves the original alone', function () {
		$person = Person::fetch(1);
		$person->bio = 'Changed';
		$clone = clone $person;
		$clone->name = 'Copy';
		$clone->commit();

		expectState([
			'persons' => [ [ 'person_id' => 1 ], [ 'person_id' => 2, 'name' => 'Copy' ] ],
			'person_details' => [ [ 'person_id' => 1 ], [ 'person_id' => 2, 'bio' => 'Changed' ] ],
			'person_extras' => [ [ 'person_id' => 1 ], [ 'person_id' => 2, 'shoe_size' => 38 ] ],
		]);
		expect($person->name)->toBe('Ann');
		$person->commit();
		expectState([
			'persons' => [ [ 'person_id' => 1 ], [ 'person_id' => 2 ] ],
			'person_details' => [ [ 'person_id' => 1, 'bio' => 'Changed' ], [ 'person_id' => 2 ] ],
			'person_extras' => [ [ 'person_id' => 1 ], [ 'person_id' => 2 ] ],
		]);
	});

	it('copies owned relations, but not read-only ones', function () {
		$clone = clone Article::fetch(1);
		$clone->commit();

		expectState([
			'articles' => [ [ 'id' => 1 ], [ 'id' => 2, 'title' => 'First', 'author_id' => 1 ] ],
			'article_tags' => [
				[ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ],
				[ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ],
				[ 'article_id' => 2, 'tag_id' => 2, 'position' => 0 ],
				[ 'article_id' => 2, 'tag_id' => 1, 'position' => 1 ],
			],
			'likes' => [
				[ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ],
				[ 'liked_id' => 2, 'liked_type' => 'Article', 'author_id' => 2 ],
			],
		]);
		expect($clone->comments)->toBe([]);
	});

	it('has its own copy of the maps', function () {
		// ★ BUGS.md #10: clone shared map storage with the original
		$account = Account::fetch(1);
		$clone = clone $account;
		$clone->flags['beta'] = 'off';
		$clone->flags['new'] = 'yes';

		expect($account->flags->toArray())->toBe([ 'beta' => 'on' ]);
		$clone->commit();
		$account->commit();
		expectState([
			'accounts' => [ [ 'id' => 1 ], [ 'id' => 2, 'name' => 'Acc' ] ],
			'account_flags' => [
				[ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ],
				[ 'account_id' => 2, 'flag' => 'beta', 'state' => 'off' ],
				[ 'account_id' => 2, 'flag' => 'new', 'state' => 'yes' ],
			],
		]);
	});

	it('gets a new value for update_on="create"', function () {
		$clone = clone Note::fetch(1);
		$clone->commit();

		expectState([ 'memo' => [ [ 'note_no' => 1 ], [ 'note_no' => 2, 'text' => 'Note', 'views' => 5, 'created_at' => now() ] ] ]);
	});

	it('of a new object is another new object', function () {
		$tag = new Tag();
		$tag->name = 'new';
		$clone = clone $tag;
		$tag->commit();
		$clone->commit();

		expectState([ 'tags' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3, 'name' => 'new' ], [ 'id' => 4, 'name' => 'new' ] ] ]);
	});

	// A clone of a soft-deleted object: see SoftDeleteTest

	it('can\'t be made of a deleted object', function () {
		$tag = Tag::fetch(1);
		$tag->delete();

		expect(fn () => clone $tag)->toThrow(Exception::class);
	});
});

describe('serialize() and unserialize()', function () {
	it('keep the loaded data', function () {
		$person = Person::fetch(1);
		expect($person->name)->toBe('Ann'); // loads the dataset
		$copy = unserialize(serialize($person));

		expect($copy->id)->toBe(1);
		expect(queries(fn () => $copy->name))->toBe([]);
		expect($copy->name)->toBe('Ann');
		expect($copy->bio)->toBe('Bio'); // loaded after unserializing
	});

	it('keep the uncommitted changes, which can be committed', function () {
		$person = Person::fetch(1);
		$person->nickname = 'Annie';
		$copy = unserialize(serialize($person));
		$copy->commit();

		expectState([ 'persons' => [ [ 'person_id' => 1, 'nickname' => 'Annie' ] ] ]);
	});

	it('keep a new object new', function () {
		$author = new Author();
		$author->name = 'Cy';
		$copy = unserialize(serialize($author));
		$copy->commit();

		expectState([ 'authors' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3, 'name' => 'Cy' ] ] ]);
	});

	it('keep a deleted object deleted', function () {
		$tag = Tag::fetch(1);
		$tag->delete();
		$copy = unserialize(serialize($tag));

		expect($copy->isDeleted())->toBeTrue();
		expect(fn () => $copy->commit())->toThrow(Exception::class);
	});

	it('work after a map was read', function () {
		// ★ BUGS-ALREADY-FIXED1 #5
		$account = Account::fetch(1);
		expect($account->flags['beta'])->toBe('on'); // loads the map
		$account->flags['new'] = 'yes';
		$copy = unserialize(serialize($account));

		expect($copy->flags->toArray())->toBe([ 'beta' => 'on', 'new' => 'yes' ]);
		$copy->commit();
		expectState([ 'account_flags' => [
			[ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ],
			[ 'account_id' => 1, 'flag' => 'new', 'state' => 'yes' ],
		] ]);
	});

	it('work after a relation was read', function () {
		$article = Article::fetch(1);
		expect($article->tags)->toHaveCount(2); // loads the relation
		$copy = unserialize(serialize($article));

		expect(array_map(fn ($tag) => $tag->id, $copy->tags))->toBe([ 2, 1 ]);
		$copy->tags = [ Tag::fetch(1) ];
		$copy->commit();
		expectState([ 'article_tags' => [ [ 'article_id' => 1, 'tag_id' => 1, 'position' => 0 ] ] ]);
	});
});
