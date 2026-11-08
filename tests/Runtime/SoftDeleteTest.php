<?php

/**
 * Soft delete: the row stays and is marked deleted; the object becomes
 * inactive but keeps working wherever it's referenced. See
 * docs/advanced-features.md, "Soft delete runtime behavior", and
 * docs/using-model-in-php.md, "Delete".
 */

use PHersist\ObjectCache;
use PHersist\ObjectFinder;
use Tests\Models\Softdelete\{Article, Tag, Writer};

useModel('softdelete');

beforeEach(fn () => given([
	'writers' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
	'writer_bios' => [ [ 'id' => 1, 'bio' => 'Writes' ], [ 'id' => 2, 'bio' => 'Also writes' ] ],
	'tags' => [ [ 'id' => 1, 'name' => 'php' ], [ 'id' => 2, 'name' => 'orm' ] ],
	'articles' => [ [ 'id' => 1, 'title' => 'Hello', 'author_id' => 1 ] ],
	'article_tags' => [ [ 'article_id' => 1, 'tag_id' => 1 ], [ 'article_id' => 1, 'tag_id' => 2 ] ],
]));

describe('delete()', function () {
	it('only sets deleted = 1', function () {
		Writer::fetch(1)->delete();

		expectState([ 'writers' => [ [ 'id' => 1, 'deleted' => 1 ], [ 'id' => 2 ] ] ]);
	});

	it('leaves join rows and references alone', function () {
		Tag::fetch(1)->delete();
		Writer::fetch(1)->delete();

		expectState([
			'tags' => [ [ 'id' => 1, 'deleted' => 1 ], [ 'id' => 2 ] ],
			'writers' => [ [ 'id' => 1, 'deleted' => 1 ], [ 'id' => 2 ] ],
		]);
	});

	it('of an object that is already soft-deleted does nothing', function () {
		$writer = Writer::fetch(1);
		$writer->delete();

		expect(queries(fn () => $writer->delete()))->toBe([]);
	});
});

/**
 * Writer 1, soft-deleted.
 */
function deletedWriter() : Writer {
	$writer = Writer::fetch(1);
	$writer->delete();
	return $writer;
}

describe('the soft-deleted object', function () {
	it('keeps its id and is marked deleted', function () {
		$writer = deletedWriter();
		expect($writer->id)->toBe(1);
		expect($writer->isDeleted())->toBeTrue();
	});

	it('can still be read, also what wasn\'t loaded yet', function () {
		// ★ 1cc20ea
		$writer = deletedWriter();
		expect($writer->name)->toBe('Ann');
		expect($writer->bio)->toBe('Writes');
		expect(array_map(fn ($article) => $article->id, $writer->articles))->toBe([ 1 ]);
	});

	it('can be reloaded', function () {
		$writer = deletedWriter();
		db()->exec("update writers set name = 'Anna' where id = 1");
		$writer->reload();

		expect($writer->name)->toBe('Anna');
	});

	it('can\'t be changed or committed', function () {
		$writer = deletedWriter();

		expect(function () use ($writer) { $writer->name = 'Again'; })->toThrow(Exception::class);
		expect(fn () => $writer->commit())->toThrow(Exception::class);
		expectState([ 'writers' => [ [ 'id' => 1, 'deleted' => 1 ], [ 'id' => 2 ] ] ]);
	});

	it('doesn\'t exist, unless soft-deleted objects count', function () {
		$writer = deletedWriter();
		expect($writer->exists())->toBeFalse();
		expect($writer->exists(true))->toBeTrue();
	});
});

describe('a soft-deleted object fetched again', function () {
	beforeEach(fn () => given([
		'writers' => [ [ 'id' => 1, 'name' => 'Ann', 'deleted' => 1 ] ],
		'writer_bios' => [ [ 'id' => 1, 'bio' => 'Writes' ] ],
	]));

	it('is marked deleted once its base data is loaded', function () {
		$writer = Writer::fetch(1);
		expect($writer->name)->toBe('Ann');

		expect($writer->isDeleted())->toBeTrue();
	});

	it('can\'t commit changes that were set before its data was loaded', function () {
		// ★ BUGS-ALREADY-FIXED4 #9
		$writer = Writer::fetch(1);
		$writer->name = 'Anna';

		expect(fn () => $writer->commit())->toThrow(Exception::class);
		expectUnchanged();
	});

	it('can\'t commit a change to a dataset in another table', function () {
		$writer = Writer::fetch(1);
		$writer->bio = 'Changed';

		expect(fn () => $writer->commit())->toThrow(Exception::class);
		expectUnchanged();
	});
});

describe('references to a soft-deleted object', function () {
	it('keep returning it, with its data', function () {
		// ★ BUGS-ALREADY-FIXED4 #4
		$article = Article::fetch(1);
		expect($article->author->name)->toBe('Ann');
		$article->author->delete();

		$article = Article::fetch(1);
		$article->reload();
		expect($article->author)->not->toBeNull();
		expect($article->author->id)->toBe(1);
		expect($article->author->name)->toBe('Ann');
		expect($article->author->bio)->toBe('Writes');
	});

	it('keep it in relations', function () {
		Tag::fetch(1)->delete();

		$article = Article::fetch(1);
		$article->reload();
		expect(array_map(fn ($tag) => $tag->id, $article->tags))->toEqualCanonicalizing([ 1, 2 ]);
	});

	it('keep it when a relation is rewritten from its loaded value', function () {
		// ★ BUGS-ALREADY-FIXED3 #1: the rows of soft-deleted objects were dropped
		given([
			'writers' => [ [ 'id' => 1, 'name' => 'Ann' ] ],
			'tags' => [ [ 'id' => 1, 'name' => 'php', 'deleted' => 1 ], [ 'id' => 2, 'name' => 'orm' ], [ 'id' => 3, 'name' => 'new' ] ],
			'articles' => [ [ 'id' => 1, 'title' => 'Hello', 'author_id' => 1 ] ],
			'article_tags' => [ [ 'article_id' => 1, 'tag_id' => 1 ], [ 'article_id' => 1, 'tag_id' => 2 ] ],
		]);

		$article = Article::fetch(1);
		$article->tags = [ ...$article->tags, Tag::fetch(3) ];
		$article->commit();

		expectState([ 'article_tags' => [
			[ 'article_id' => 1, 'tag_id' => 1 ],
			[ 'article_id' => 1, 'tag_id' => 2 ],
			[ 'article_id' => 1, 'tag_id' => 3 ],
		] ]);
	});

	it('can be added', function () {
		Writer::fetch(2)->delete();

		$article = Article::fetch(1);
		$article->author = Writer::fetch(2);
		$article->commit();

		expectState([
			'writers' => [ [ 'id' => 1 ], [ 'id' => 2, 'deleted' => 1 ] ],
			'articles' => [ [ 'id' => 1, 'author_id' => 2 ] ],
		]);
	});
});

describe('ObjectFinder', function () {
	beforeEach(fn () => given([ 'tags' => [ [ 'id' => 1, 'name' => 'php', 'deleted' => 1 ], [ 'id' => 2, 'name' => 'orm' ] ] ]));

	it('skips soft-deleted objects', function () {
		$tags = ObjectFinder::create(Tag::class)->fetch();

		expect(array_map(fn ($tag) => $tag->id, $tags))->toBe([ 2 ]);
		expect(ObjectFinder::create(Tag::class)->count())->toBe(1);
		expect(ObjectFinder::create(Tag::class)->where('name', '=', 'php')->fetchOne())->toBeNull();
	});

	it('includes them when asked', function () {
		$tags = ObjectFinder::create(Tag::class)->includeDeletedRecords(true)->orderBy('id')->fetch();

		expect(array_map(fn ($tag) => $tag->id, $tags))->toBe([ 1, 2 ]);
		expect(ObjectFinder::create(Tag::class)->includeDeletedRecords(true)->count())->toBe(2);
		expect($tags[0]->isDeleted())->toBeTrue();
		expect($tags[1]->isDeleted())->toBeFalse();
	});

	it('marks a soft-deleted object it returns as deleted', function () {
		$tag = ObjectFinder::create(Tag::class, true)->includeDeletedRecords(true)->where('id', '=', 1)->fetchOne();

		expect($tag->isDeleted())->toBeTrue();
		expect(function () use ($tag) { $tag->name = 'x'; })->toThrow(Exception::class);
	});
});

it('stays in the ObjectCache, marked deleted', function () {
	ObjectCache::setEnabled(true);
	$writer = Writer::fetch(1);
	$writer->delete();

	expect(Writer::fetch(1))->toBe($writer);
	expect(Writer::fetch(1)->isDeleted())->toBeTrue();
});

it('can be cloned, and the clone isn\'t deleted', function () {
	given([ 'writers' => [ [ 'id' => 1, 'name' => 'Wendy', 'deleted' => 1 ] ], 'writer_bios' => [ [ 'id' => 1, 'bio' => 'Bio' ] ] ]);
	$writer = Writer::fetch(1);
	expect($writer->name)->toBe('Wendy');
	expect($writer->isDeleted())->toBeTrue();

	$clone = clone $writer;
	expect($clone->isDeleted())->toBeFalse();
	$clone->commit();

	expectState([
		'writers' => [ [ 'id' => 1 ], [ 'id' => 2, 'name' => 'Wendy', 'deleted' => 0 ] ],
		'writer_bios' => [ [ 'id' => 1 ], [ 'id' => 2, 'bio' => 'Bio' ] ],
	]);
});
