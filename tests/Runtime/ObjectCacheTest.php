<?php

/**
 * ObjectCache itself: its API, weak references, and every way PHersist hands
 * out objects. Each test switches the cache on or off itself, so this file
 * covers both, whatever PHERSIST_TEST_CACHE says. See
 * docs/advanced-features.md, "ObjectCache".
 */

use PHersist\ActiveRecord;
use PHersist\ObjectCache;
use PHersist\ObjectFinder;
use PHersist\Transaction;
use Tests\Models\Basic\{Group, Post, User};
use Tests\Models\Relations\{Article, Author, Photo};
use Tests\Support\Internals;

useModel('basic', 'relations', 'global');

beforeEach(function () {
	ObjectCache::setEnabled(true);
	given([
		'users' => [ [ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com' ], [ 'id' => 2, 'name' => 'Bob' ] ],
		'groups' => [ [ 'id' => 1, 'name' => 'Admins' ] ],
		'user_groups' => [ [ 'user_id' => 1, 'group_id' => 1 ] ],
		'posts' => [ [ 'id' => 10, 'title' => 'Hi', 'author_id' => 1 ] ],
		'authors' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
		'articles' => [ [ 'id' => 1, 'title' => 'First', 'author_id' => 1 ] ],
		'photos' => [ [ 'id' => 1, 'url' => 'a.jpg' ] ],
		'likes' => [ [ 'liked_id' => 1, 'liked_type' => 'Article', 'author_id' => 2 ], [ 'liked_id' => 1, 'liked_type' => 'Photo', 'author_id' => 2 ] ],
		'comments' => [ [ 'id' => 1, 'body' => 'C', 'subject_type' => 'Photo', 'subject_id' => 1 ] ],
		'global_gadgets' => [ [ 'id' => 1, 'name' => 'G' ] ],
		'global_widgets' => [ [ 'id' => 1, 'gadget_id' => 1, 'thing_type' => 'GlobalGadget', 'thing_id' => 1 ] ],
	]);
});

describe('the API', function () {
	it('is off by default', function () {
		expect((new ReflectionProperty(ObjectCache::class, 'enabled'))->getDefaultValue())->toBeFalse();
	});

	it('stores and returns objects by class and id', function () {
		$user = User::fetch(1);
		$group = Group::fetch(1);
		Internals::set(ObjectCache::class, 'cache', []);

		ObjectCache::put($user);
		ObjectCache::put($group);

		expect(ObjectCache::get(User::class, 1))->toBe($user);
		expect(ObjectCache::get(Group::class, 1))->toBe($group);
		expect(ObjectCache::get(User::class, 2))->toBeNull();
	});

	it('ignores null and objects without an id', function () {
		ObjectCache::put(null);
		ObjectCache::put(new User());
		ObjectCache::evict(null);

		expect(Internals::get(ObjectCache::class, 'cache'))->toBe([]);
	});

	it('evicts an object, but not another instance with the same class and id', function () {
		$user = User::fetch(1);
		ObjectCache::evict(new User()); // no id: nothing happens
		expect(ObjectCache::get(User::class, 1))->toBe($user);

		ObjectCache::evict($user);
		expect(ObjectCache::get(User::class, 1))->toBeNull();
	});

	it('stores and returns nothing while disabled', function () {
		ObjectCache::setEnabled(false);
		$user = User::fetch(1);
		ObjectCache::put($user);

		expect(ObjectCache::get(User::class, 1))->toBeNull();
		expect(User::fetch(1))->not->toBe($user);
	});

	it('doesn\'t keep objects alive', function () {
		$user = User::fetch(1);
		expect(ObjectCache::get(User::class, 1))->toBe($user);

		unset($user);
		gc_collect_cycles();

		expect(ObjectCache::get(User::class, 1))->toBeNull();
		expect(User::fetch(1))->toBeInstanceOf(User::class);
	});
});

describe('with the cache enabled', function () {
	it('fetch() returns the same instance', function () {
		expect(User::fetch(1))->toBe(User::fetch(1));
		expect(ActiveRecord::fetchObject(User::class, 1))->toBe(User::fetch(1));
		expect(ActiveRecord::fetchObject('\\'.User::class, 1))->toBe(User::fetch(1));
	});

	it('ObjectFinder returns the instances in use', function () {
		$user = User::fetch(1);

		expect(ObjectFinder::create(User::class)->where('id', '=', 1)->fetchOne())->toBe($user);
		expect(ObjectFinder::create(User::class, true)->orderBy('id')->fetch()[0])->toBe($user);
	});

	it('Class and DynamicClass properties return the instances in use', function () {
		$user = User::fetch(1);
		$photo = Photo::fetch(1);

		expect(Post::fetch(10)->author)->toBe($user);
		expect(\Tests\Models\Relations\Comment::fetch(1)->subject)->toBe($photo);
	});

	it('relations return the instances in use, with and without load_objects', function () {
		$bob = Author::fetch(2);
		$group = Group::fetch(1);

		expect(Article::fetch(1)->likers[0])->toBe($bob);
		expect(Photo::fetch(1)->likers[0])->toBe($bob); // load_objects="true"
		expect(User::fetch(1)->groups[0])->toBe($group);
		expect(Author::fetch(1)->articles[0])->toBe(Article::fetch(1));
	});

	it('saves the queries for objects that are loaded already', function () {
		$user = User::fetch(1);
		expect($user->name)->toBe('Ann'); // loads the dataset

		expect(queries(fn () => Post::fetch(10)->author->name))->toHaveCount(1); // only the post
	});

	it('works for classes without a namespace', function () {
		// ★ ebf5f0d / BUGS-ALREADY-FIXED2 #2
		$gadget = GlobalGadget::fetch(1);

		expect(GlobalWidget::fetch(1)->gadget)->toBe($gadget);
		expect(GlobalWidget::fetch(1)->thing)->toBe($gadget);
		expect(ObjectFinder::create(GlobalGadget::class)->fetchOne())->toBe($gadget);
		expect(ActiveRecord::fetchObject('\GlobalGadget', 1))->toBe($gadget);
	});

	it('adds a new object on its first commit', function () {
		$user = new User();
		$user->name = 'Cy';
		$user->commit();

		expect(User::fetch($user->id))->toBe($user);
	});

	it('doesn\'t add a clone until it is committed', function () {
		$user = User::fetch(1);
		$clone = clone $user;

		expect(User::fetch(1))->toBe($user);
		$clone->commit();
		expect(User::fetch($clone->id))->toBe($clone);
		expect(User::fetch(1))->toBe($user);
	});

	it('evicts a deleted object', function () {
		$user = User::fetch(2);
		$user->delete();

		$again = User::fetch(2);
		expect($again)->not->toBe($user);
		expect($again->exists())->toBeFalse();
	});

	// A soft-deleted object stays in the cache: see SoftDeleteTest

	it('evicts an object whose insert was rolled back', function () {
		$user = new User();
		$user->name = 'Cy';
		try {
			Transaction::run('main', function () use ($user) {
				$user->commit();
				throw new RuntimeException('rollback');
			});
		} catch (RuntimeException) {
		}

		expect($user->id)->toBeNull();
		expect(Internals::get(ObjectCache::class, 'cache'))->not->toHaveKey(User::class.':3');
	});

	it('puts an object whose delete was rolled back back in', function () {
		$user = User::fetch(2);
		try {
			Transaction::run('main', function () use ($user) {
				$user->delete();
				throw new RuntimeException('rollback');
			});
		} catch (RuntimeException) {
		}

		expect(User::fetch(2))->toBe($user);
	});

	it('keeps the instance on reload()', function () {
		$user = User::fetch(1);
		$user->reload();

		expect(User::fetch(1))->toBe($user);
	});

	it('gives a cached object the values the finder loads, but keeps uncommitted changes', function () {
		// docs: "an object that is already in use gets the values from the row"
		$user = User::fetch(1);
		expect($user->name)->toBe('Ann');
		$user->email = 'changed@example.com';
		db()->exec("update users set name = 'Anna', email = 'db@example.com' where id = 1");

		$found = ObjectFinder::create(User::class, true)->where('id', '=', 1)->fetchOne();

		expect($found)->toBe($user);
		expect($user->name)->toBe('Anna');
		expect($user->email)->toBe('changed@example.com');
	});

	it('returns a stale object after it was switched off and on again', function () {
		knownBug('ObjectCache::evict() does nothing while the cache is disabled, so after enabling it again, fetch() returns an object that was deleted in the meantime, without an id');
		$user = User::fetch(2);
		ObjectCache::setEnabled(false);
		$user->delete();
		ObjectCache::setEnabled(true);

		$again = User::fetch(2);
		expect($again)->not->toBe($user);
		expect($again->id)->toBe(2);
	});
});

describe('unserializing', function () {
	it('puts the object in the cache, replacing the instance in use', function () {
		// ★ BUGS-ALREADY-FIXED2 #23, documented in "Boundaries"
		$user = User::fetch(1);
		expect($user->name)->toBe('Ann'); // loads the dataset
		$copy = unserialize(serialize($user));

		expect($copy)->not->toBe($user);
		expect(User::fetch(1))->toBe($copy);
		expect($user->name)->toBe('Ann'); // the original keeps working
	});

	it('doesn\'t let the original evict the unserialized object', function () {
		$user = User::fetch(1);
		$copy = unserialize(serialize($user));
		unset($user);
		gc_collect_cycles();

		expect(User::fetch(1))->toBe($copy);
	});
});
