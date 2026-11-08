<?php

/**
 * Relations: reading and writing every setup, and cleaning up the relation
 * rows on delete. References from properties are in ReferenceCleanupTest.
 * See docs/using-model-in-php.md, "Working with relations", and
 * docs/creating-model-from-xml.md, "Relations".
 */

use Tests\Models\Relations\{Article, Attachment, Author, Category, Comment, Photo, Tag};

useModel('relations');

beforeEach(fn () => given([
	'authors' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ], [ 'id' => 3, 'name' => 'Cy' ] ],
	'articles' => [
		[ 'id' => 1, 'title' => 'First', 'author_id' => 1 ],
		[ 'id' => 2, 'title' => 'Second', 'author_id' => 1 ],
		[ 'id' => 3, 'title' => 'Third', 'author_id' => 2 ],
	],
	'tags' => [ [ 'id' => 1, 'name' => 'php' ], [ 'id' => 2, 'name' => 'orm' ], [ 'id' => 3, 'name' => 'sql' ] ],
	'article_tags' => [
		[ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ],
		[ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ],
		[ 'article_id' => 2, 'tag_id' => 1, 'position' => 0 ],
	],
	'photos' => [ [ 'id' => 1, 'url' => 'a.jpg' ] ],
	'likes' => [
		[ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ],
		[ 'liked_id' => 1, 'liked_type' => 'Photo', 'author_id' => 3 ],
		[ 'liked_id' => 2, 'liked_type' => 'Article', 'author_id' => 3 ],
	],
	'comments' => [
		[ 'id' => 1, 'body' => 'On article 1', 'subject_type' => 'Article', 'subject_id' => 1 ],
		[ 'id' => 2, 'body' => 'On photo 1', 'subject_type' => 'Photo', 'subject_id' => 1 ],
		[ 'id' => 3, 'body' => 'Also on article 1', 'subject_type' => 'Article', 'subject_id' => 1 ],
	],
	'friendships' => [ [ 'author_id' => 1, 'friend_id' => 2 ], [ 'author_id' => 2, 'friend_id' => 1 ] ],
	'attachments' => [ [ 'id' => 1, 'name' => 'a.pdf', 'article_id' => 1 ], [ 'id' => 2, 'name' => 'b.pdf', 'article_id' => 2 ] ],
	'categories' => [
		[ 'id' => 1, 'name' => 'Root', 'parent_id' => null ],
		[ 'id' => 2, 'name' => 'Zebra', 'parent_id' => 1 ],
		[ 'id' => 3, 'name' => 'Apple', 'parent_id' => 1 ],
	],
]));

/**
 * The ids of related objects; sorted, unless the order is the point.
 *
 * @param list<\PHersist\ActiveRecord> $objects
 * @return list<?int>
 */
function relatedIds(array $objects, bool $sort = true) : array {
	$ids = array_map(fn ($object) => $object->id, $objects);
	if ($sort)
		sort($ids);
	return $ids;
}

describe('reading', function () {
	it('lists the objects of a derived 1-N relation', function () {
		expect(relatedIds(Author::fetch(1)->articles))->toBe([ 1, 2 ]);
		expect(relatedIds(Author::fetch(3)->articles))->toBe([]);
	});

	it('lists the objects of a join table, in order_field order', function () {
		expect(relatedIds(Article::fetch(1)->tags, false))->toBe([ 2, 1 ]);
	});

	it('lists them from the other side of the join table', function () {
		expect(relatedIds(Tag::fetch(1)->articles))->toBe([ 1, 2 ]);
		expect(relatedIds(Tag::fetch(3)->articles))->toBe([]);
	});

	it('only lists the rows of its own class in a polymorphic join table', function () {
		expect(relatedIds(Article::fetch(1)->likers))->toBe([ 2 ]);
		expect(relatedIds(Photo::fetch(1)->likers))->toBe([ 3 ]);
	});

	it('only lists the rows of its own class in a derived polymorphic relation', function () {
		expect(relatedIds(Article::fetch(1)->comments))->toBe([ 1, 3 ]);
		expect(relatedIds(Photo::fetch(1)->comments))->toBe([ 2 ]);
	});

	it('follows self-references', function () {
		expect(relatedIds(Author::fetch(1)->friends))->toBe([ 2 ]);
		expect(relatedIds(Category::fetch(1)->children, false))->toBe([ 3, 2 ]); // order_field="name"
	});

	it('loads the related objects along with load_objects="true"', function () {
		// ★ 66581a4, and ★ c9f8286 / BUGS-ALREADY-FIXED2 #9 for Author.articles on the articles base table
		$photo = Photo::fetch(1);
		$likers = $photo->likers;
		expect(queries(fn () => $likers[0]->name))->toBe([]);
		expect($likers[0]->name)->toBe('Cy');

		$articles = Author::fetch(1)->articles;
		expect(queries(fn () => array_map(fn ($article) => $article->title, $articles)))->toBe([]);
	});

	it('loads the related objects later with load_objects="false"', function () {
		onlyWithoutCache();
		$likers = Article::fetch(1)->likers;

		expect(queries(fn () => $likers[0]->name))->toHaveCount(1);
		expect($likers[0]->name)->toBe('Bob');
	});

	it('loads a relation once', function () {
		$article = Article::fetch(1);
		expect($article->tags)->toHaveCount(2); // loads the relation

		expect(queries(fn () => $article->tags))->toBe([]);
	});
});

describe('writing an owned relation', function () {
	it('replaces the object\'s rows, with positions for order_field', function () {
		$article = Article::fetch(1);
		$article->tags = [ Tag::fetch(3), Tag::fetch(1) ];
		$article->commit();

		expectState([ 'article_tags' => [
			[ 'article_id' => 1, 'tag_id' => 3, 'position' => 0 ],
			[ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ],
			[ 'article_id' => 2, 'tag_id' => 1, 'position' => 0 ],
		] ]);
		$article->reload();
		expect(relatedIds($article->tags, false))->toBe([ 3, 1 ]);
	});

	it('keeps the same object more than once with order_field', function () {
		$article = Article::fetch(2);
		$article->tags = [ Tag::fetch(1), Tag::fetch(2), Tag::fetch(1) ];
		$article->commit();

		expectState([ 'article_tags' => [
			[ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ],
			[ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ],
			[ 'article_id' => 2, 'tag_id' => 1, 'position' => 0 ],
			[ 'article_id' => 2, 'tag_id' => 2, 'position' => 1 ],
			[ 'article_id' => 2, 'tag_id' => 1, 'position' => 2 ],
		] ]);
	});

	it('rejects the same object twice without order_field', function () {
		$author = Author::fetch(1);

		expect(function () use ($author) { $author->friends = [ Author::fetch(2), Author::fetch(2) ]; })->toThrow(Exception::class);
	});

	it('is cleared with an empty array', function () {
		$article = Article::fetch(1);
		$article->tags = [];
		$article->commit();

		expectState([ 'article_tags' => [ [ 'article_id' => 2, 'tag_id' => 1, 'position' => 0 ] ] ]);
	});

	it('only touches the rows of its own class in a polymorphic join table', function () {
		$article = Article::fetch(1);
		$article->likers = [ Author::fetch(1), Author::fetch(3) ];
		$article->commit();

		expectState([ 'likes' => [
			[ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 1 ],
			[ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 3 ],
			[ 'liked_id' => 1, 'liked_type' => 'Photo', 'author_id' => 3 ],
			[ 'liked_id' => 2, 'liked_type' => 'Article', 'author_id' => 3 ],
		] ]);
	});

	it('works for a self-reference', function () {
		$author = Author::fetch(3);
		$author->friends = [ Author::fetch(1) ];
		$author->commit();

		expectState([ 'friendships' => [
			[ 'author_id' => 1, 'friend_id' => 2 ],
			[ 'author_id' => 2, 'friend_id' => 1 ],
			[ 'author_id' => 3, 'friend_id' => 1 ],
		] ]);
	});

	it('is stored for a new object', function () {
		$article = new Article();
		$article->title = 'New';
		$article->author = Author::fetch(3);
		$article->tags = [ Tag::fetch(3) ];
		$article->commit();

		expectState([
			'articles' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ], [ 'id' => 4, 'title' => 'New', 'author_id' => 3 ] ],
			'article_tags' => [
				[ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ],
				[ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ],
				[ 'article_id' => 2, 'tag_id' => 1, 'position' => 0 ],
				[ 'article_id' => 4, 'tag_id' => 3, 'position' => 0 ],
			],
		]);
	});

	it('isn\'t written when it didn\'t change', function () {
		$article = Article::fetch(1);
		expect($article->tags)->toHaveCount(2); // loads the relation

		expect(queries(fn () => $article->commit()))->toBe([]);
	});

	it('rejects values that aren\'t an array of the related class', function (Closure $value) {
		$article = Article::fetch(1);

		expect(function () use ($article, $value) { $article->tags = $value(); })->toThrow(Exception::class);
	})->with([
		'a single object' => [ fn () => Tag::fetch(1) ],
		'null' => [ fn () => null ],
		'objects of another class' => [ fn () => [ Author::fetch(1) ] ],
		'ids' => [ fn () => [ 1, 2 ] ],
	]);

	it('can\'t refer to an object that isn\'t committed or doesn\'t exist', function (Closure $tag) {
		$article = Article::fetch(1);
		$article->tags = [ Tag::fetch(1), $tag() ];

		expect(fn () => $article->commit())->toThrow(Exception::class);
		expectUnchanged();
	})->with([
		'new object' => [ fn () => new Tag() ],
		'no row' => [ fn () => Tag::fetch(99) ],
	]);

	it('doesn\'t support appending with []=', function () {
		knownBug('$obj->relation[] = $x does nothing for NN relations (AGENTS.md); assign a full array');
		$article = Article::fetch(2);
		$tags = $article->tags;
		$article->tags[] = Tag::fetch(3);
		$article->commit();

		expect(relatedIds($article->tags, false))->toBe([ ...relatedIds($tags, false), 3 ]);
	});
});

describe('a read-only relation', function () {
	it('can\'t be assigned', function (Closure $assign) {
		expect($assign)->toThrow(Exception::class);
		expectUnchanged();
	})->with([
		'other side of a join table' => [ function () { Tag::fetch(1)->articles = []; } ],
		'derived' => [ function () { Author::fetch(1)->articles = []; } ], // ★ BUGS-ALREADY-FIXED2 #3
		'derived polymorphic' => [ function () { Article::fetch(1)->comments = []; } ],
	]);

	it('isn\'t kept in sync with the owning side in memory', function () {
		knownBug('Relations defined from both sides aren\'t kept in sync in memory (AGENTS.md, TODO.md)');
		$tag = Tag::fetch(3);
		expect($tag->articles)->toBe([]);
		$article = Article::fetch(1);
		$article->tags = [ Tag::fetch(3) ];
		$article->commit();

		expect(relatedIds($tag->articles))->toBe([ 1 ]);
	});

	it('sees the owning side\'s changes after reload()', function () {
		$tag = Tag::fetch(3);
		expect($tag->articles)->toBe([]);
		$article = Article::fetch(1);
		$article->tags = [ Tag::fetch(3) ];
		$article->commit();

		$tag->reload();
		expect(relatedIds($tag->articles))->toBe([ 1 ]);
	});
});

describe('deleting', function () {
	it('removes the object\'s rows from owned join tables', function () {
		Article::fetch(2)->delete();

		expectState([
			'articles' => [ [ 'id' => 1 ], [ 'id' => 3 ] ],
			'article_tags' => [
				[ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ],
				[ 'article_id' => 1, 'tag_id' => 1, 'position' => 1 ],
			],
			'likes' => [
				[ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ],
				[ 'liked_id' => 1, 'liked_type' => 'Photo', 'author_id' => 3 ],
			],
			'attachments' => [ [ 'id' => 1 ] ], // cascade_delete
		]);
	});

	it('removes its rows from join tables it doesn\'t own', function () {
		Tag::fetch(1)->delete();

		expectState([
			'tags' => [ [ 'id' => 2 ], [ 'id' => 3 ] ],
			'article_tags' => [ [ 'article_id' => 1, 'tag_id' => 2, 'position' => 0 ] ],
		]);
	});

	it('removes the rows that refer to it as the related object', function () {
		Author::fetch(3)->delete();

		expectState([
			'authors' => [ [ 'id' => 1 ], [ 'id' => 2 ] ],
			'likes' => [ [ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ] ],
		]);
	});

	it('removes both directions of a self-reference', function () {
		given([
			'authors' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ], [ 'id' => 3, 'name' => 'Cy' ] ],
			'friendships' => [ [ 'author_id' => 1, 'friend_id' => 2 ], [ 'author_id' => 2, 'friend_id' => 1 ], [ 'author_id' => 2, 'friend_id' => 3 ] ],
		]);

		Author::fetch(1)->delete();

		expectState([
			'authors' => [ [ 'id' => 2 ], [ 'id' => 3 ] ],
			'friendships' => [ [ 'author_id' => 2, 'friend_id' => 3 ] ],
		]);
	});

	it('only removes the rows of its own class from a polymorphic join table', function () {
		given([
			'authors' => [ [ 'id' => 2, 'name' => 'Bob' ], [ 'id' => 3, 'name' => 'Cy' ] ],
			'photos' => [ [ 'id' => 1, 'url' => 'a.jpg' ] ],
			'likes' => [
				[ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ],
				[ 'liked_id' => 1, 'liked_type' => 'Photo', 'author_id' => 3 ],
			],
		]);

		Photo::fetch(1)->delete();

		expectState([
			'photos' => [],
			'likes' => [ [ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ] ],
		]);
	});

	it('deletes the objects of a cascade_delete relation', function () {
		// ★ BUGS-ALREADY-FIXED1 #19: cascade_delete only worked on owned join tables
		given([
			'authors' => [ [ 'id' => 1, 'name' => 'Ann' ] ],
			'articles' => [ [ 'id' => 1, 'title' => 'First', 'author_id' => 1 ] ],
			'attachments' => [ [ 'id' => 1, 'name' => 'a.pdf', 'article_id' => 1 ], [ 'id' => 2, 'name' => 'b.pdf', 'article_id' => null ] ],
		]);

		Article::fetch(1)->delete();

		expectState([ 'articles' => [], 'attachments' => [ [ 'id' => 2 ] ] ]);
	});

	it('cascades a derived polymorphic relation through on_remote_delete', function () {
		given([
			'photos' => [ [ 'id' => 1, 'url' => 'a.jpg' ] ],
			'comments' => [
				[ 'id' => 1, 'body' => 'On article 1', 'subject_type' => 'Article', 'subject_id' => 1 ],
				[ 'id' => 2, 'body' => 'On photo 1', 'subject_type' => 'Photo', 'subject_id' => 1 ],
			],
		]);

		Photo::fetch(1)->delete();

		expectState([ 'photos' => [], 'comments' => [ [ 'id' => 1 ] ] ]);
	});

	it('nulls the parent of the children of a self-referencing relation', function () {
		Category::fetch(1)->delete();

		expectState([ 'categories' => [ [ 'id' => 2, 'parent_id' => null ], [ 'id' => 3, 'parent_id' => null ] ] ]);
	});

	it('is restricted by a required reference', function () {
		// Article.author is required, so on_remote_delete defaults to restrict
		expect(fn () => Author::fetch(1)->delete())->toThrow(Exception::class);
		expectUnchanged();
	});
});

describe('a deleted related object', function () {
	it('stays in a relation that was already loaded', function () {
		// docs/creating-model-from-xml.md, "Deleting objects with relations"
		$article = Article::fetch(1);
		$tags = $article->tags;
		Tag::fetch(1)->delete();

		expect(count($article->tags))->toBe(count($tags));
		$article->reload();
		expect(relatedIds($article->tags))->toBe([ 2 ]);
	});

	it('makes committing a relation built from the stale value throw', function () {
		onlyWithCache(); // without the cache, the deleted instance isn't the one in the relation
		$article = Article::fetch(1);
		$tags = $article->tags;
		$tags[1]->delete();
		$article->tags = [ ...$tags, Tag::fetch(3) ];

		expect(fn () => $article->commit())->toThrow(Exception::class, 'deleted');
	});
});

it('can relate new objects only after committing them', function () {
	$comment = new Comment();
	$comment->body = 'New';
	$comment->subject = Photo::fetch(1);
	$comment->commit();

	expectState([ 'comments' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ], [ 'id' => 4, 'body' => 'New', 'subject_type' => 'Photo', 'subject_id' => 1 ] ] ]);
	$photo = Photo::fetch(1);
	$photo->reload();
	expect(relatedIds($photo->comments))->toBe([ 2, 4 ]);
});

it('lists attachments of an article through the derived relation', function () {
	$attachment = new Attachment();
	$attachment->name = 'c.pdf';
	$attachment->article = Article::fetch(2);
	$attachment->commit();

	$article = Article::fetch(2);
	$article->reload();
	expect(relatedIds($article->attachments))->toBe([ 2, 3 ]);
});
