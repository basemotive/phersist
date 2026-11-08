<?php

/**
 * ObjectFinder: conditions per type and operator, groups, paths through
 * references, ordering, limits, counting and loading. See
 * docs/using-model-in-php.md, "Querying with ObjectFinder" and "Chainability
 * and query flow".
 */

use PHersist\ObjectFinder;
use Tests\Models\Basic\{Post, User};
use Tests\Models\Datasets\Person;
use Tests\Models\Types\{Item, Owner};

useModel('basic', 'types', 'datasets');

/**
 * The ids of the found objects, in order.
 *
 * @param list<\PHersist\ActiveRecord> $objects
 * @return list<?int>
 */
function ids(array $objects) : array {
	return array_map(fn ($object) => $object->id, $objects);
}

/**
 * The ids of the users matching one condition, sorted by id.
 *
 * @return list<?int>
 */
function usersWhere(string $property, string $operator, mixed $value) : array {
	return ids(ObjectFinder::create(User::class)->where($property, $operator, $value)->orderBy('id')->fetch());
}

beforeEach(fn () => given([
	'users' => [
		[ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30 ],
		[ 'id' => 2, 'name' => 'Bob', 'email' => null, 'age' => 25 ],
		[ 'id' => 3, 'name' => 'Cy', 'email' => 'cy@example.org', 'age' => null ],
		[ 'id' => 4, 'name' => 'Ann', 'email' => 'ann2@example.org', 'age' => 41 ],
	],
	'posts' => [
		[ 'id' => 10, 'title' => 'Hello', 'body' => 'First', 'author_id' => 1 ],
		[ 'id' => 11, 'title' => 'World', 'author_id' => 2 ],
		[ 'id' => 12, 'title' => 'Orphan', 'author_id' => null ],
		[ 'id' => 13, 'title' => 'Hello again', 'author_id' => 1 ],
	],
	'owners' => [ [ 'id' => 1, 'name' => 'O1' ], [ 'id' => 2, 'name' => 'O2' ] ],
	'items' => [
		[ 'id' => 1, 'title' => 'A', 'price' => '1.00', 'hidden' => 0, 'active' => 1, 'birthday' => '2026-10-01', 'custom_stamp' => '01-10-2026', 'stamp' => '2026-10-01 10:00:00', 'owner_ref' => 1, 'subject_type' => 'Owner', 'subject_id' => 1, 'ratio' => 0.5 ],
		[ 'id' => 2, 'title' => 'B', 'price' => '2.50', 'hidden' => 1, 'active' => 0, 'birthday' => '2026-11-15', 'custom_stamp' => '15-11-2026', 'stamp' => '2026-11-15 10:00:00', 'owner_ref' => 2, 'subject_type' => 'Owner', 'subject_id' => 2, 'ratio' => 1.5 ],
		[ 'id' => 3, 'title' => 'C', 'price' => null, 'hidden' => null, 'active' => 1, 'birthday' => null, 'owner_ref' => null, 'ratio' => null ],
	],
	'persons' => [ [ 'person_id' => 1, 'name' => 'P1' ], [ 'person_id' => 2, 'name' => 'P2' ], [ 'person_id' => 3, 'name' => 'P3' ] ],
	'person_details' => [ [ 'person_id' => 1, 'bio' => 'x', 'age' => 50 ], [ 'person_id' => 2, 'bio' => 'y', 'age' => 20 ], [ 'person_id' => 3, 'bio' => 'x', 'age' => 35 ] ],
]));

describe('operators', function () {
	it('compare values', function (string $property, string $operator, mixed $value, array $expected) {
		expect(usersWhere($property, $operator, $value))->toBe($expected);
	})->with([
		'= text' => [ 'name', '=', 'Ann', [ 1, 4 ] ],
		'= int' => [ 'age', '=', 25, [ 2 ] ],
		'= int from a string' => [ 'age', '=', '25', [ 2 ] ],
		'= null' => [ 'email', '=', null, [ 2 ] ],
		'!= null' => [ 'email', '!=', null, [ 1, 3, 4 ] ],
		'!= value, also matching null' => [ 'age', '!=', 30, [ 2, 3, 4 ] ],
		'>' => [ 'age', '>', 25, [ 1, 4 ] ],
		'<' => [ 'age', '<', 30, [ 2 ] ],
		'>=' => [ 'age', '>=', 30, [ 1, 4 ] ],
		'<=' => [ 'age', '<=', 30, [ 1, 2 ] ],
		'> out of the column range' => [ 'age', '>', -9999999999, [ 1, 2, 4 ] ],
		'LIKE' => [ 'email', 'LIKE', '%.org', [ 3, 4 ] ],
		'LIKE is not a regex' => [ 'name', 'LIKE', 'A.n', [] ],
		'NOT LIKE, also matching null' => [ 'email', 'NOT LIKE', '%.org', [ 1, 2 ] ],
		'= id' => [ 'id', '=', 3, [ 3 ] ],
		'> id from a string' => [ 'id', '>', '2', [ 3, 4 ] ],
	]);

	it('compare with the case and accents of the database collation', function () {
		// What = on text matches beyond exact equality depends on the database's
		// collation; this pins down the current behaviour per dialect
		$found = usersWhere('name', '=', 'ann');
		expect($found)->toBe(target()->dialect === 'sqlite' ? [] : [ 1, 4 ]);
	});

	it('reject invalid values', function (string $property, string $operator, mixed $value) {
		expect(fn () => ObjectFinder::create(User::class)->where($property, $operator, $value))->toThrow(InvalidArgumentException::class);
	})->with([
		'an array' => [ 'name', '=', [ 'Ann', 'Bob' ] ], // ★ BUGS-ALREADY-FIXED3 #7
		'text for an int' => [ 'age', '=', 'five' ],
		'LIKE with a non-string' => [ 'name', 'LIKE', 5 ],
		'text for the id' => [ 'id', '=', 'abc' ],
	]);

	it('reject unknown properties and operators, and operators the type doesn\'t support', function (string $property, string $operator, mixed $value = 1) {
		expect(fn () => ObjectFinder::create(User::class)->where($property, $operator, $value))->toThrow(Exception::class);
	})->with([
		'unknown property' => [ 'nothing', '=' ],
		'unknown path' => [ 'groups->nothing', '=' ],
		'unknown operator' => [ 'age', '<>' ],
		'SQL as operator' => [ 'age', '= 1 or 1 =' ],
		'LIKE on an int' => [ 'age', 'LIKE' ],
		'LIKE on the id' => [ 'id', 'LIKE' ],
		'> with null' => [ 'age', '>', null ],
	]);

	it('treat the deprecated IS as =', function () {
		// ★ 346aac9 / BUGS-ALREADY-FIXED2 #11
		$deprecations = [];
		set_error_handler(function (int $errno, string $message) use (&$deprecations) {
			$deprecations[] = $message;
			return true;
		}, E_USER_DEPRECATED);
		try {
			expect(usersWhere('age', 'IS', 25))->toBe([ 2 ]);
			expect(usersWhere('email', 'IS', null))->toBe([ 2 ]);
		} finally {
			restore_error_handler();
		}
		expect($deprecations)->toHaveCount(2);
	});
});

describe('types', function () {
	it('are converted and compared like on assignment', function (string $property, string $operator, mixed $value, array $expected) {
		$value = $value instanceof Closure ? $value() : $value;
		expect(ids(ObjectFinder::create(Item::class)->where($property, $operator, $value)->orderBy('id')->fetch()))->toBe($expected);
	})->with([
		'Decimal = from a float' => [ 'price', '=', 2.5, [ 2 ] ],
		'Decimal = from a string' => [ 'price', '=', '1', [ 1 ] ],
		'Decimal > with more decimals than the scale' => [ 'price', '>', '1.005', [ 2 ] ],
		'Float <' => [ 'ratio', '<', 1, [ 1 ] ],
		'Bool true' => [ 'hidden', '=', true, [ 2 ] ],
		'Bool false' => [ 'hidden', '=', false, [ 1 ] ],
		'Bool from \'0\'' => [ 'active', '=', '0', [ 2 ] ],
		'Bool != true, also matching null' => [ 'hidden', '!=', true, [ 1, 3 ] ],
		'Date range' => [ 'birthday', '>=', '2026-11-01', [ 2 ] ],
		'Date from a DateTime' => [ 'birthday', '=', new DateTimeImmutable('2026-10-01'), [ 1 ] ],
		'TimestampText LIKE' => [ 'stamp', 'LIKE', '2026-11%', [ 2 ] ],
		'Class = object' => [ 'owner', '=', fn () => Owner::fetch(2), [ 2 ] ],
		'Class = id' => [ 'owner', '=', 1, [ 1 ] ],
		'Class = id as a string' => [ 'owner', '=', '1', [ 1 ] ], // ★ BUGS-ALREADY-FIXED1 #15
		'Class = null' => [ 'owner', '=', null, [ 3 ] ],
		'Class != object, also matching null' => [ 'owner', '!=', fn () => Owner::fetch(1), [ 2, 3 ] ],
		'DynamicClass = object' => [ 'subject', '=', fn () => Owner::fetch(2), [ 2 ] ],
		'DynamicClass = null' => [ 'subject', '=', null, [ 3 ] ],
	]);

	it('compare a TimestampText with a partial timestamp', function () {
		// docs: with <, >, <= and >=, "a TimestampText may be any string (like '2026-10')"
		if (target()->dialect !== 'sqlite')
			knownBug('A TimestampText with the default date_format is a DATETIME column on MySQL and MariaDB, so comparing it with a partial timestamp like \'2026-11\' throws on MySQL ("Incorrect DATETIME value") and matches nothing on MariaDB');

		expect(ids(ObjectFinder::create(Item::class)->where('stamp', '<', '2026-11')->orderBy('id')->fetch()))->toBe([ 1 ]);
		expect(ids(ObjectFinder::create(Item::class)->where('customStamp', '<', '15')->orderBy('id')->fetch()))->toBe([ 1 ]); // a TEXT column
	});

	it('reject values that don\'t suit the type', function (string $property, string $operator, mixed $value) {
		expect(fn () => ObjectFinder::create(Item::class)->where($property, $operator, $value instanceof Closure ? $value() : $value))->toThrow(InvalidArgumentException::class);
	})->with([
		'Bool from \'false\'' => [ 'hidden', '=', 'false' ], // ★ BUGS-ALREADY-FIXED1 #20
		'Decimal = with more decimals than the scale' => [ 'price', '=', '1.00001' ],
		'Decimal > from text' => [ 'price', '>', 'abc' ],
		'Date that doesn\'t exist' => [ 'birthday', '=', '2026-02-30' ],
		'Class = object of another class' => [ 'owner', '=', fn () => User::fetch(1) ],
		'Class = new object' => [ 'owner', '=', fn () => new Owner() ],
		'Class = text' => [ 'owner', '=', 'abc' ],
		'DynamicClass = id' => [ 'subject', '=', 1 ], // ★ BUGS-ALREADY-FIXED1 #23
		'DynamicClass = new object' => [ 'subject', '=', fn () => new Owner() ],
	]);
});

describe('paths through references', function () {
	it('compare properties of the referenced object', function () {
		$posts = ObjectFinder::create(Post::class)->where('author->name', '=', 'Ann')->orderBy('id')->fetch();

		expect(ids($posts))->toBe([ 10, 13 ]);
	});

	it('match objects without a reference with !=', function () {
		$posts = ObjectFinder::create(Post::class)->where('author->name', '!=', 'Ann')->orderBy('id')->fetch();

		expect(ids($posts))->toBe([ 11, 12 ]);
	});

	it('end in an id', function () {
		$posts = ObjectFinder::create(Post::class)->where('author->id', '=', 2)->fetch();

		expect(ids($posts))->toBe([ 11 ]);
	});

	it('are counted once per object', function () {
		// ★ 7799c53 / BUGS-ALREADY-FIXED1 #2
		expect(ObjectFinder::create(Post::class)->where('author->name', '=', 'Ann')->count())->toBe(2);
		expect(ObjectFinder::create(User::class)->where('name', '=', 'Ann')->count())->toBe(2);
	});
});

describe('datasets in other tables', function () {
	it('can be searched and ordered by', function () {
		// ★ eed6deb / BUGS-ALREADY-FIXED1 #8
		$persons = ObjectFinder::create(Person::class)->where('bio', '=', 'x')->orderBy('age', ObjectFinder::DIRECTION_DESC)->fetch();

		expect(ids($persons))->toBe([ 1, 3 ]);
	});

	it('can be counted', function () {
		expect(ObjectFinder::create(Person::class)->where('age', '>', 30)->count())->toBe(2);
	});
});

describe('groups', function () {
	it('combine conditions with AND by default', function () {
		$users = ObjectFinder::create(User::class)->where('name', '=', 'Ann')->where('age', '>', 35)->fetch();

		expect(ids($users))->toBe([ 4 ]);
	});

	it('combine conditions with OR in addOr()', function () {
		$users = ObjectFinder::create(User::class)->addOr()->where('name', '=', 'Bob')->where('name', '=', 'Cy')->end()->orderBy('id')->fetch();

		expect(ids($users))->toBe([ 2, 3 ]);
	});

	it('continue at the parent level after end()', function () {
		// ★ TODO.md: an extra where() after end() messed up the evaluation
		$users = ObjectFinder::create(User::class)
			->where('email', '!=', null)
			->addOr()
				->where('name', '=', 'Ann')
				->where('name', '=', 'Bob')
			->end()
			->where('age', '<', 40)
			->orderBy('id')
			->fetch();

		expect(ids($users))->toBe([ 1 ]);
	});

	it('nest', function () {
		// ★ 7b6edb4: nested combined expressions
		$users = ObjectFinder::create(User::class)
			->addOr()
				->where('name', '=', 'Cy')
				->addAnd()
					->where('name', '=', 'Ann')
					->where('age', '>', 35)
				->end()
			->end()
			->orderBy('id')
			->fetch();

		expect(ids($users))->toBe([ 3, 4 ]);
	});

	it('may be empty', function () {
		// ★ BUGS-ALREADY-FIXED1 #7: an empty group produced invalid SQL
		$users = ObjectFinder::create(User::class)->addOr()->end()->orderBy('id')->fetch();

		expect(ids($users))->toBe([ 1, 2, 3, 4 ]);
		expect(ObjectFinder::create(User::class)->addAnd()->end()->count())->toBe(4);
	});

	it('pass fetch(), fetchOne(), count() and orderBy() through to the finder', function () {
		$group = ObjectFinder::create(User::class)->addOr()->where('name', '=', 'Bob')->where('name', '=', 'Cy');

		expect($group->count())->toBe(2);
		expect(ids($group->orderBy('id', ObjectFinder::DIRECTION_DESC)->fetch()))->toBe([ 3, 2 ]);
		expect($group->fetchOne()?->id)->toBe(3);
	});

	it('give the same results when the finder is kept in a variable', function () {
		// ★ a7c056c: fetch() on chained queries returned no results
		$finder = ObjectFinder::create(User::class);
		$finder->where('name', '=', 'Ann');
		$finder->orderBy('id');

		expect(ids($finder->fetch()))->toBe([ 1, 4 ]);
		expect($finder->count())->toBe(2);
	});
});

describe('orderBy()', function () {
	it('sorts ascending and descending, by several properties', function () {
		$users = ObjectFinder::create(User::class)->orderBy('name', ObjectFinder::DIRECTION_DESC)->orderBy('age')->fetch();

		expect(ids($users))->toBe([ 3, 2, 1, 4 ]);
	});

	it('sorts by id', function () {
		// ★ BUGS-ALREADY-FIXED1 #6
		expect(ids(ObjectFinder::create(User::class)->orderBy('id', ObjectFinder::DIRECTION_DESC)->fetch()))->toBe([ 4, 3, 2, 1 ]);
	});

	it('rejects an unknown direction, property or path', function (Closure $call) {
		// ★ BUGS-ALREADY-FIXED1 #16: SQL injection through the direction
		expect($call)->toThrow(Exception::class);
	})->with([
		'direction' => [ fn () => ObjectFinder::create(User::class)->orderBy('name', 'desc; drop table users') ],
		'property' => [ fn () => ObjectFinder::create(User::class)->orderBy('nothing') ],
		'path' => [ fn () => ObjectFinder::create(Post::class)->orderBy('author->name') ],
	]);
});

describe('fetch()', function () {
	it('limits and skips', function () {
		$finder = fn () => ObjectFinder::create(User::class)->orderBy('id');

		expect(ids($finder()->fetch(2)))->toBe([ 1, 2 ]);
		expect(ids($finder()->fetch(2, 1)))->toBe([ 2, 3 ]);
		expect(ids($finder()->fetch(10, 3)))->toBe([ 4 ]);
		expect(ids($finder()->fetch(0)))->toBe([]);
	});

	it('rejects a negative limit or offset, and an offset without a limit', function (Closure $call) {
		expect($call)->toThrow(Exception::class);
	})->with([
		'negative limit' => [ fn () => ObjectFinder::create(User::class)->fetch(-1) ],
		'negative offset' => [ fn () => ObjectFinder::create(User::class)->fetch(1, -1) ],
		'offset without a limit' => [ fn () => ObjectFinder::create(User::class)->fetch(null, 1) ],
	]);

	it('returns an empty list when nothing matches', function () {
		expect(ObjectFinder::create(User::class)->where('name', '=', 'Nobody')->fetch())->toBe([]);
		expect(ObjectFinder::create(User::class)->where('name', '=', 'Nobody')->fetchOne())->toBeNull();
		expect(ObjectFinder::create(User::class)->where('name', '=', 'Nobody')->count())->toBe(0);
	});

	it('returns objects that are marked as existing', function () {
		$post = new Post();
		$post->title = 'New';
		$post->author = ObjectFinder::create(User::class)->where('id', '=', 3)->fetchOne();

		// The author's row was seen, so commit() doesn't query it again
		$log = queries(fn () => $post->commit());
		expect(array_filter($log, fn ($sql) => str_contains($sql, 'users')))->toBe([]);
	});
});

describe('loading', function () {
	it('without full, loads the data when it\'s read', function () {
		$users = ObjectFinder::create(User::class)->orderBy('id')->fetch();

		expect(queries(fn () => $users[0]->name))->toHaveCount(1);
	});

	it('with full, loads every autoload dataset along', function () {
		// ★ BUGS-ALREADY-FIXED2 #10: only the first autoload dataset was preloaded
		$posts = ObjectFinder::create(Post::class, true)->orderBy('id')->fetch();

		expect(queries(fn () => [ $posts[0]->title, $posts[0]->author, $posts[3]->title ]))->toBe([]);
		expect($posts[0]->title)->toBe('Hello');
		expect($posts[0]->author->id)->toBe(1);
		expect(queries(fn () => $posts[0]->body))->toHaveCount(1);
		expect($posts[0]->body)->toBe('First');
	});

	it('with full, gives the same objects as without', function () {
		$full = ObjectFinder::create(User::class, true)->where('name', '=', 'Ann')->orderBy('id')->fetch();
		$lazy = ObjectFinder::create(User::class)->where('name', '=', 'Ann')->orderBy('id')->fetch();

		expect(array_map(fn ($user) => [ $user->id, $user->name, $user->email, $user->age ], $full))
			->toBe(array_map(fn ($user) => [ $user->id, $user->name, $user->email, $user->age ], $lazy));
	});
});
