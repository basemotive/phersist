<?php

/**
 * Foreign keys in the database, next to PHersist's own cleanup. See
 * docs/creating-model-from-xml.md, "Foreign keys".
 *
 * A run with PHERSIST_TEST_FOREIGN_KEYS=on (the -fk targets) loads every
 * model with foreign keys, so all runtime tests show that PHersist works with
 * them: it deletes and inserts rows in an order the keys allow. The tests
 * here are the ones that matter most for that, and those of the keys
 * themselves, which need such a run.
 */

use PHersist\Transaction;
use Tests\Models\References\{CascadeRef, ChainRef, CycleA, CycleB, Target};
use Tests\Models\Softdelete\{Article, Tag, Writer};

useModel('references', 'softdelete');

describe('new objects that refer to each other', function () {
	it('are committed in one transaction, also in a cycle', function () {
		Transaction::run('main', function () {
			$target = new Target();
			$target->name = 'T';
			$target->commit();

			$ref = new CascadeRef();
			$ref->target = $target;
			$ref->commit();

			$chain = new ChainRef();
			$chain->parent = $ref;
			$chain->commit();

			$a = new CycleA();
			$a->commit();
			$b = new CycleB();
			$b->a = $a;
			$b->commit();
			$a->b = $b;
			$a->commit();
		});

		expectState([
			'targets' => [ [ 'id' => 1, 'name' => 'T' ] ],
			'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'chain_refs' => [ [ 'id' => 1, 'parent_id' => 1 ] ],
			'cycle_as' => [ [ 'id' => 1, 'b_id' => 1 ] ],
			'cycle_bs' => [ [ 'id' => 1, 'a_id' => 1 ] ],
		]);
	});

	it('are gone after a rollback', function () {
		expect(fn () => Transaction::run('main', function () {
			$a = new CycleA();
			$a->commit();
			$b = new CycleB();
			$b->a = $a;
			$b->commit();
			$a->b = $b;
			$a->commit();
			throw new RuntimeException('stop');
		}))->toThrow(RuntimeException::class, 'stop');

		expectUnchanged();
	});
});

describe('a soft-deleted object', function () {
	beforeEach(fn () => given([
		'writers' => [ [ 'id' => 1, 'name' => 'Ann' ] ],
		'writer_bios' => [ [ 'id' => 1, 'bio' => 'Writes' ] ],
		'tags' => [ [ 'id' => 1, 'name' => 'php' ] ],
		'articles' => [ [ 'id' => 1, 'title' => 'Hello', 'author_id' => 1 ] ],
		'article_tags' => [ [ 'article_id' => 1, 'tag_id' => 1 ] ],
	]));

	it('keeps its rows and what refers to it, so it can be undeleted', function () {
		Writer::fetch(1)->delete();
		Tag::fetch(1)->delete();

		// PHersist has no undelete; a project does it like this
		db()->exec('update writers set deleted = 0 where id = 1');
		db()->exec('update tags set deleted = 0 where id = 1');

		expectUnchanged();
		$article = Article::fetch(1);
		expect($article->author->isDeleted())->toBeFalse();
		expect($article->author->bio)->toBe('Writes');
		expect(array_map(fn ($tag) => $tag->name, array_values($article->tags)))->toBe([ 'php' ]);
	});
});

describe('a row deleted with plain SQL', function () {
	beforeEach(function () {
		onlyWithForeignKeys();
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T' ], [ 'id' => 2, 'name' => 'Other' ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => 2 ] ],
			'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => 2 ] ],
			'chain_refs' => [ [ 'id' => 1, 'parent_id' => 1 ], [ 'id' => 2, 'parent_id' => 2 ] ],
			'soft_refs' => [ [ 'id' => 1, 'target_id' => 1, 'deleted' => 1 ] ],
			'soft_cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'dyn_refs' => [ [ 'id' => 1, 'subject_type' => 'Target', 'subject_id' => 1 ] ],
		]);
	});

	it('is cleaned up by the database like PHersist does, as far as it has keys', function () {
		db()->exec('delete from targets where id = 1');

		expectState([
			'targets' => [ [ 'id' => 2 ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => null ], [ 'id' => 2 ] ],
			'cascade_refs' => [ [ 'id' => 2 ] ],
			'chain_refs' => [ [ 'id' => 2 ] ],
			'soft_refs' => [ [ 'id' => 1, 'target_id' => null ] ],
			// No keys: a softdelete class with cascade, and a DynamicClass
		]);
	});

	it('is refused while a restrict reference exists', function (string $table) {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T' ] ],
			$table => [ [ 'id' => 1, 'target_id' => 1 ] ],
		]);

		expect(fn () => db()->exec('delete from targets where id = 1'))->toThrow(PDOException::class, 'foreign key constraint fails');
		expectUnchanged();
	})->with([ 'restrict_refs', 'optional_restrict_refs' ]);

	it('can\'t be referred to once it\'s gone', function () {
		expect(fn () => db()->exec('insert into null_refs (target_id) values (99)'))->toThrow(PDOException::class, 'foreign key constraint fails');
		expectUnchanged();
	});
});

it('is deleted by PHersist the same with and without foreign keys', function () {
	given([
		'targets' => [ [ 'id' => 1, 'name' => 'T' ] ],
		'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
		'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
		'chain_refs' => [ [ 'id' => 1, 'parent_id' => 1 ] ],
		'soft_refs' => [ [ 'id' => 1, 'target_id' => 1, 'deleted' => 1 ] ],
		'soft_cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => 1, 'deleted' => 1 ] ],
		'dyn_refs' => [ [ 'id' => 1, 'subject_type' => 'Target', 'subject_id' => 1 ] ],
		'cycle_as' => [ [ 'id' => 1, 'b_id' => 1 ] ],
		'cycle_bs' => [ [ 'id' => 1, 'a_id' => 1 ] ],
	]);

	Target::fetch(1)->delete();
	CycleA::fetch(1)->delete();

	expectState([
		'targets' => [],
		'null_refs' => [ [ 'id' => 1, 'target_id' => null ] ],
		'cascade_refs' => [],
		'chain_refs' => [],
		'soft_refs' => [ [ 'id' => 1, 'target_id' => null ] ],
		// Softdeleted along, keeping their reference: this is why they have no key
		'soft_cascade_refs' => [ [ 'id' => 1, 'deleted' => 1 ], [ 'id' => 2 ] ],
		'dyn_refs' => [ [ 'id' => 1, 'subject_type' => null, 'subject_id' => null ] ],
		'cycle_as' => [],
		'cycle_bs' => [],
	]);
});
