<?php

/**
 * The test helpers themselves: given(), expectState(), expectUnchanged(), the
 * matchers, normalization, the query log and the at-rest checks. If these are
 * wrong, every other runtime test is meaningless.
 */

use PHersist\Generator\Schema\Column;
use PHersist\Generator\Schema\ColumnType;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Models\Basic\User;
use Tests\Support\Internals;
use Tests\Support\State;

useModel('basic');

describe('given()', function () {
	it('starts every test with empty tables', function () {
		expect(db()->query('select count(*) from users')->fetchColumn())->toEqual(0);
	});

	it('inserts the rows, with column defaults for left-out columns', function () {
		given([ 'users' => [ [ 'id' => 3, 'name' => 'Ann' ] ] ]);

		expect(db()->query('select id, name, email from users')->fetchAll(PDO::FETCH_ASSOC))
			->toEqual([ [ 'id' => 3, 'name' => 'Ann', 'email' => null ] ]);
	});

	it('resets the ids, so new rows get predictable ones', function () {
		db()->exec("insert into users (name) values ('a')");
		db()->exec("insert into users (name) values ('b')");
		given([]);
		db()->exec("insert into users (name) values ('c')");

		expect(db()->query('select id from users')->fetchColumn())->toEqual(1);
	});

	it('rejects unknown tables and columns', function () {
		expect(fn () => given([ 'user' => [] ]))->toThrow(InvalidArgumentException::class, "Unknown table 'user'");
		expect(fn () => given([ 'users' => [ [ 'id' => 1, 'nmae' => 'x' ] ] ]))->toThrow(InvalidArgumentException::class, "Table 'users' has no column 'nmae'");
	});
});

describe('expectState()', function () {
	beforeEach(fn () => given([
		'users' => [
			[ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30 ],
			[ 'id' => 2, 'name' => 'Bob' ],
		],
		'posts' => [ [ 'id' => 10, 'title' => 'Hi', 'author_id' => 1 ] ],
	]));

	it('passes when the listed rows match, in any order', function () {
		expectState([ 'users' => [
			[ 'id' => 2, 'name' => 'Bob', 'email' => null, 'age' => null ],
			[ 'id' => 1, 'name' => 'Ann', 'email' => 'ann@example.com', 'age' => 30 ],
		] ]);
	});

	it('compares left-out columns with the before-state', function () {
		db()->exec("update users set email = 'bob@example.com' where id = 2");

		expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2, 'email' => 'bob@example.com' ] ] ]);
		expect(fn () => expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2 ] ] ]))->toThrow(ExpectationFailedException::class);
	});

	it('fails for a missing or an extra row', function () {
		expect(fn () => expectState([ 'users' => [ [ 'id' => 1 ] ] ]))->toThrow(ExpectationFailedException::class);
		expect(fn () => expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3, 'name' => 'Cy' ] ] ]))->toThrow(ExpectationFailedException::class);
	});

	it('checks only the listed columns of new rows', function () {
		db()->exec("insert into users (name, email) values ('Cy', 'cy@example.com')");

		expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3, 'name' => 'Cy' ] ] ]);
	});

	it('fails when a table that isn\'t listed changed', function () {
		db()->exec("update posts set title = 'Bye'");

		expect(fn () => expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2 ] ] ]))
			->toThrow(ExpectationFailedException::class, "Table 'posts' should be unchanged");
	});

	it('checks an empty table when it\'s listed without rows', function () {
		db()->exec('delete from posts');

		expectState([ 'posts' => [] ]);
		expect(fn () => expectUnchanged())->toThrow(ExpectationFailedException::class);
	});

	it('rejects an empty expected state as ambiguous', function () {
		expect(fn () => expectState([]))->toThrow(LogicException::class, 'expectUnchanged()');
	});

	it('needs the id of expected rows, and every column of tables without one', function () {
		expect(fn () => expectState([ 'users' => [ [ 'name' => 'Ann' ] ] ]))->toThrow(LogicException::class, "need their 'id'");
		expect(fn () => expectState([ 'user_groups' => [ [ 'user_id' => 1 ] ] ]))->toThrow(LogicException::class, 'missing: group_id');
	});

	it('compares rows of tables without an id, in any order', function () {
		db()->exec('insert into user_groups (user_id, group_id) values (2, 5), (1, 5), (1, 4)');

		expectState([ 'user_groups' => [
			[ 'user_id' => 1, 'group_id' => 4 ],
			[ 'user_id' => 2, 'group_id' => 5 ],
			[ 'user_id' => 1, 'group_id' => 5 ],
		] ]);
	});

	it('accepts matchers', function () {
		db()->exec("insert into users (name, email) values ('Cy', '".date('Y-m-d H:i:s')."')");

		expectState([ 'users' => [ [ 'id' => 1, 'email' => any() ], [ 'id' => 2 ], [ 'id' => 3, 'email' => now(), 'name' => that(fn ($v) => strlen($v) === 2) ] ] ]);
		expect(fn () => expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2, 'email' => any() ], [ 'id' => 3 ] ] ]))
			->toThrow(ExpectationFailedException::class);
	});
});

describe('expectUnchanged()', function () {
	it('passes when nothing changed, and fails otherwise', function () {
		given([ 'groups' => [ [ 'id' => 1, 'name' => 'Admins' ] ] ]);
		expectUnchanged();

		db()->exec("insert into user_groups (user_id, group_id) values (1, 1)");
		expect(fn () => expectUnchanged())->toThrow(ExpectationFailedException::class, "Table 'user_groups' should be unchanged");
	});
});

describe('normalization', function () {
	it('normalizes values by column type', function (ColumnType $type, mixed $value, mixed $expected, ?int $scale = null) {
		expect(State::normalize($value, new Column('c', $type, true, null, false, $scale === null ? null : 12, $scale)))->toBe($expected);
	})->with([
		'int from a string' => [ ColumnType::Int, '-12', -12 ],
		'int stays' => [ ColumnType::Int, 7, 7 ],
		'int from a non-integer string stays a string' => [ ColumnType::Int, '7.5', '7.5' ],
		'bool true' => [ ColumnType::Bool, true, 1 ],
		'bool from a string' => [ ColumnType::Bool, '0', 0 ],
		'float from a string' => [ ColumnType::Float, '1.5', 1.5 ],
		'float rounding noise' => [ ColumnType::Float, 0.1 + 0.2, 0.3 ],
		'decimal padded' => [ ColumnType::Decimal, '9.99', '9.9900', 4 ],
		'decimal from a float' => [ ColumnType::Decimal, 9.99, '9.9900', 4 ],
		'decimal leading zeros' => [ ColumnType::Decimal, '-0009.5', '-9.5000', 4 ],
		'decimal negative zero' => [ ColumnType::Decimal, '-0.00', '0.00', 2 ],
		'decimal big, no float' => [ ColumnType::Decimal, '12345678901234567890.12', '12345678901234567890.12', 2 ],
		'text from an int' => [ ColumnType::Text, 5, '5' ],
		'text kept exactly' => [ ColumnType::Text, " Ä b ", " Ä b " ],
		'date from a DateTime' => [ ColumnType::Date, new DateTimeImmutable('2026-01-02 03:04:05'), '2026-01-02' ],
		'datetime from a DateTime' => [ ColumnType::DateTime, new DateTimeImmutable('2026-01-02 03:04:05'), '2026-01-02 03:04:05' ],
		'datetime string kept as is' => [ ColumnType::DateTime, '2026-01-02T03:04:05', '2026-01-02T03:04:05' ],
		'null' => [ ColumnType::Int, null, null ],
	]);
});

describe('queries()', function () {
	it('returns the statements PHersist ran', function () {
		given([ 'users' => [ [ 'id' => 1, 'name' => 'Ann' ] ] ]);

		$log = queries(fn () => User::fetch(1)->name);

		expect($log)->not->toBeEmpty();
		expect($log[0])->toContain('users');
	});
});

describe('the at-rest check', function () {
	it('reports an open transaction and closes it', function () {
		db()->beginTransaction();

		expect(Internals::problemsAtRest(db()))->toBe([ 'a database transaction is still open' ]);
		expect(db()->inTransaction())->toBeFalse();
	});

	it('reports a changed error mode and restores it', function () {
		db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

		expect(Internals::problemsAtRest(db()))->toBe([ 'the PDO error mode was changed' ]);
		expect(db()->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION);
	});
});
