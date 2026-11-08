<?php

/**
 * Every property type: what an assigned value becomes in memory, what is
 * stored, and what a fresh load returns; invalid values; defaults and
 * update_on. See docs/creating-model-from-xml.md, "Property types".
 */

use PHersist\ActiveRecord;
use Tests\Models\Basic\User;
use Tests\Models\Types\{Item, Owner};

useModel('types', 'basic');

/**
 * A value as a comparable, readable description: dates with their time zone,
 * objects by class and id.
 */
function describeValue(mixed $value) : mixed {
	if ($value instanceof DateTimeInterface)
		return 'DateTime('.$value->format('Y-m-d H:i:s e').')';
	if ($value instanceof ActiveRecord)
		return get_class($value).'#'.$value->id;
	return $value;
}

beforeEach(fn () => given([
	'owners' => [ [ 'id' => 1, 'name' => 'Owner one' ] ],
	'users' => [ [ 'id' => 5, 'name' => 'Ann' ] ],
]));

describe('a valid value', function () {
	it('is converted, stored and loaded back', function (string $property, Closure $value, mixed $inMemory, array $columns) {
		$item = new Item();
		$item->$property = $value();
		expect(describeValue($item->$property))->toBe($inMemory);

		$item->commit();
		expectState([ 'items' => [ [ 'id' => 1, ...$columns ] + [ 'created_at' => now() ] ] ]);

		$item->reload();
		expect(describeValue($item->$property))->toBe($inMemory);
	})->with([
		'Text' => [ 'notes', fn () => 'Some text', 'Some text', [ 'notes' => 'Some text' ] ],
		'Text from an int' => [ 'notes', fn () => 42, '42', [ 'notes' => '42' ] ],
		'Text from a float' => [ 'notes', fn () => 1.5, '1.5', [ 'notes' => '1.5' ] ],
		'Text from a Stringable' => [ 'notes', fn () => new class { public function __toString() : string { return 'str'; } }, 'str', [ 'notes' => 'str' ] ],
		'Text null' => [ 'notes', fn () => null, null, [ 'notes' => null ] ],

		'Int' => [ 'count', fn () => 7, 7, [ 'count' => 7 ] ],
		'Int from a string' => [ 'count', fn () => '-12', -12, [ 'count' => -12 ] ],
		'Int from a string with leading zeros' => [ 'count', fn () => '007', 7, [ 'count' => 7 ] ],
		'Int from a whole float' => [ 'count', fn () => 3.0, 3, [ 'count' => 3 ] ],
		'Int signed minimum' => [ 'count', fn () => -2147483648, -2147483648, [ 'count' => -2147483648 ] ],
		'Int signed maximum' => [ 'count', fn () => 2147483647, 2147483647, [ 'count' => 2147483647 ] ],
		'Int unsigned maximum' => [ 'stock', fn () => 4294967295, 4294967295, [ 'stock' => 4294967295 ] ],
		'Int null' => [ 'count', fn () => null, null, [ 'count' => null ] ],

		'Float' => [ 'ratio', fn () => 0.25, 0.25, [ 'ratio' => 0.25 ] ],
		'Float from an int' => [ 'ratio', fn () => 3, 3.0, [ 'ratio' => 3.0 ] ],
		'Float from a string' => [ 'ratio', fn () => '-1.5e3', -1500.0, [ 'ratio' => -1500.0 ] ],
		'Float with many digits' => [ 'ratio', fn () => 1/3, 1/3, [ 'ratio' => 1/3 ] ],
		'Float large' => [ 'ratio', fn () => 1.5e300, 1.5e300, [ 'ratio' => 1.5e300 ] ],

		'Decimal from a string' => [ 'price', fn () => '12.5', '12.5000', [ 'price' => '12.5000' ] ],
		'Decimal from a float' => [ 'amount', fn () => 12.5, '12.50', [ 'amount' => '12.50' ] ],
		'Decimal from a float, rounded as written' => [ 'amount', fn () => 49.995, '50.00', [ 'amount' => '50.00' ] ],
		'Decimal from a negative float, rounded away from zero' => [ 'amount', fn () => -49.995, '-50.00', [ 'amount' => '-50.00' ] ],
		'Decimal from a float stored just below a half' => [ 'amount', fn () => 1.005, '1.01', [ 'amount' => '1.01' ] ],
		'Decimal from a float that is an exact half' => [ 'amount', fn () => 0.125, '0.13', [ 'amount' => '0.13' ] ],
		'Decimal from a float below a half' => [ 'amount', fn () => 1.0049, '1.00', [ 'amount' => '1.00' ] ],
		'Decimal from an int' => [ 'amount', fn () => 3, '3.00', [ 'amount' => '3.00' ] ],
		'Decimal negative' => [ 'amount', fn () => '-0.5', '-0.50', [ 'amount' => '-0.50' ] ],
		'Decimal negative zero' => [ 'amount', fn () => '-0.00', '0.00', [ 'amount' => '0.00' ] ],
		'Decimal maximum' => [ 'price', fn () => '99999999.9999', '99999999.9999', [ 'price' => '99999999.9999' ] ],
		'Decimal trailing zeros beyond the scale' => [ 'amount', fn () => '1.2300', '1.23', [ 'amount' => '1.23' ] ],

		'Bool true' => [ 'hidden', fn () => true, true, [ 'hidden' => 1 ] ],
		'Bool false' => [ 'hidden', fn () => false, false, [ 'hidden' => 0 ] ],
		'Bool from 1' => [ 'hidden', fn () => 1, true, [ 'hidden' => 1 ] ],
		'Bool from \'0\'' => [ 'hidden', fn () => '0', false, [ 'hidden' => 0 ] ],
		'Bool null' => [ 'hidden', fn () => null, null, [ 'hidden' => null ] ],

		'Date from a string' => [ 'birthday', fn () => '2026-02-28', 'DateTime(2026-02-28 00:00:00 UTC)', [ 'birthday' => '2026-02-28' ] ],
		'Date from a date/time string' => [ 'birthday', fn () => '2026-02-28 13:45:00', 'DateTime(2026-02-28 00:00:00 UTC)', [ 'birthday' => '2026-02-28' ] ],
		'Date from a DateTime' => [ 'birthday', fn () => new DateTime('2024-02-29 08:00'), 'DateTime(2024-02-29 00:00:00 UTC)', [ 'birthday' => '2024-02-29' ] ],
		'Date null' => [ 'birthday', fn () => null, null, [ 'birthday' => null ] ],

		'DateTime from a string' => [ 'startsAt', fn () => '2026-01-01 12:30', 'DateTime(2026-01-01 12:30:00 UTC)', [ 'starts_at' => '2026-01-01 12:30:00' ] ],
		'DateTime from ISO 8601 with T and Z' => [ 'startsAt', fn () => '2026-01-01T12:30:15Z', 'DateTime(2026-01-01 12:30:15 UTC)', [ 'starts_at' => '2026-01-01 12:30:15' ] ],
		'DateTime in another zone, converted' => [ 'startsAt', fn () => '2026-01-01T23:30:00+02:00', 'DateTime(2026-01-01 21:30:00 UTC)', [ 'starts_at' => '2026-01-01 21:30:00' ] ],
		'DateTime without fractions' => [ 'startsAt', fn () => '2026-01-01 12:30:15.75', 'DateTime(2026-01-01 12:30:15 UTC)', [ 'starts_at' => '2026-01-01 12:30:15' ] ],
		'DateTime from a DateTimeImmutable' => [ 'startsAt', fn () => new DateTimeImmutable('2026-06-01 08:00', new DateTimeZone('Europe/Amsterdam')), 'DateTime(2026-06-01 06:00:00 UTC)', [ 'starts_at' => '2026-06-01 06:00:00' ] ],
		'DateTime null' => [ 'startsAt', fn () => null, null, [ 'starts_at' => null ] ],

		'TimestampText with date_format' => [ 'customStamp', fn () => '20-10-2026', '20-10-2026', [ 'custom_stamp' => '20-10-2026' ] ],

		'Class' => [ 'owner', fn () => Owner::fetch(1), 'Tests\Models\Types\Owner#1', [ 'owner_ref' => 1 ] ],
		'Class null' => [ 'owner', fn () => null, null, [ 'owner_ref' => null ] ],

		'DynamicClass, same namespace: short name' => [ 'subject', fn () => Owner::fetch(1), 'Tests\Models\Types\Owner#1', [ 'subject_type' => 'Owner', 'subject_id' => 1 ] ],
		'DynamicClass, other namespace: full name' => [ 'subject', fn () => User::fetch(5), 'Tests\Models\Basic\User#5', [ 'subject_type' => 'Tests\Models\Basic\User', 'subject_id' => 5 ] ],
		'DynamicClass with use_namespace' => [ 'target', fn () => Owner::fetch(1), 'Tests\Models\Types\Owner#1', [ 'target_class' => 'Tests\Models\Types\Owner', 'target_ref' => 1 ] ],
		'DynamicClass null' => [ 'subject', fn () => null, null, [ 'subject_type' => null, 'subject_id' => null ] ],
	]);
});

describe('an invalid value', function () {
	it('is rejected on assignment, and the property keeps its value', function (string $property, Closure $value) {
		$item = new Item();
		$before = describeValue($item->$property);

		expect(function () use ($item, $property, $value) { $item->$property = $value(); })->toThrow(Exception::class, "Invalid value for property {$property}");
		expect(describeValue($item->$property))->toBe($before);
	})->with([
		'Text from an array' => [ 'notes', fn () => [ 'a' ] ],
		'Text from a bool' => [ 'notes', fn () => true ],
		'Text from an object' => [ 'notes', fn () => new stdClass() ],

		'Int from a non-integer string' => [ 'count', fn () => '1.5' ],
		'Int from text' => [ 'count', fn () => 'abc' ],
		'Int from a fractional float' => [ 'count', fn () => 1.5 ],
		'Int from a bool' => [ 'count', fn () => true ],
		'Int below the signed range' => [ 'count', fn () => -2147483649 ],
		'Int above the signed range' => [ 'count', fn () => 2147483648 ],
		'Int negative for unsigned' => [ 'stock', fn () => -1 ],
		'Int above the unsigned range' => [ 'stock', fn () => 4294967296 ],
		'Int from an empty string' => [ 'count', fn () => '' ],

		'Float infinite' => [ 'ratio', fn () => INF ],
		'Float NaN' => [ 'ratio', fn () => NAN ],
		'Float from text' => [ 'ratio', fn () => 'abc' ],
		'Float overflowing string' => [ 'ratio', fn () => '1e999' ],

		'Decimal with too many decimals' => [ 'amount', fn () => '1.234' ],
		'Decimal out of range' => [ 'amount', fn () => '123456789' ],
		'Decimal from a float rounded out of range' => [ 'amount', fn () => 99999999.995 ],
		'Decimal from text' => [ 'amount', fn () => 'abc' ],
		'Decimal from an empty string' => [ 'amount', fn () => '' ],
		'Decimal infinite' => [ 'amount', fn () => INF ],

		'Bool from \'true\'' => [ 'hidden', fn () => 'true' ],
		'Bool from \'false\'' => [ 'hidden', fn () => 'false' ],
		'Bool from 2' => [ 'hidden', fn () => 2 ],
		'Bool from an empty string' => [ 'hidden', fn () => '' ],

		'Date that doesn\'t exist' => [ 'birthday', fn () => '2026-02-30' ],
		'Date from text' => [ 'birthday', fn () => 'tomorrow' ],
		'Date from an int' => [ 'birthday', fn () => 20260101 ],
		'DateTime with an impossible time' => [ 'startsAt', fn () => '2026-01-01 24:00:00' ],
		'DateTime from text' => [ 'startsAt', fn () => 'next monday' ],

		'TimestampText in another format' => [ 'customStamp', fn () => '2026-10-20' ],
		'TimestampText that doesn\'t exist' => [ 'customStamp', fn () => '30-02-2026' ],
		'TimestampText from a DateTime' => [ 'stamp', fn () => new DateTime() ],

		'Class of another class' => [ 'owner', fn () => User::fetch(5) ],
		'Class from an id' => [ 'owner', fn () => 1 ],
		'DynamicClass from a non-ActiveRecord' => [ 'subject', fn () => new stdClass() ],
		'DynamicClass from an id' => [ 'subject', fn () => 1 ],
	]);
});

describe('defaults', function () {
	it('are set on new objects, and stored', function () {
		$item = new Item();
		expect([ $item->title, $item->count, $item->stock, $item->ratio, $item->price, $item->active, $item->hidden ])
			->toBe([ 'Untitled', -3, 0, 1.5, '9.9900', true, null ]);

		$item->commit();

		expectState([ 'items' => [ [
			'id' => 1, 'title' => 'Untitled', 'notes' => null, 'count' => -3, 'stock' => 0, 'ratio' => 1.5,
			'price' => '9.9900', 'amount' => null, 'active' => 1, 'hidden' => null, 'birthday' => null,
			'starts_at' => null, 'created_at' => now(), 'modified_at' => now(), 'stamp' => now(), 'custom_stamp' => null,
			'owner_ref' => null, 'subject_type' => null, 'subject_id' => null, 'target_class' => null, 'target_ref' => null,
		] ] ]);
	});

	it('are the column defaults too', function () {
		db()->exec('insert into items (id) values (1)');

		expectState([ 'items' => [ [ 'id' => 1, 'title' => 'Untitled', 'count' => -3, 'stock' => 0, 'ratio' => 1.5, 'price' => '9.9900', 'active' => 1, 'hidden' => null ] ] ]);
	});
});

describe('update_on', function () {
	beforeEach(fn () => given([
		'items' => [ [ 'id' => 1, 'title' => 'Old', 'created_at' => '2020-01-01 00:00:00', 'modified_at' => '2020-01-01 00:00:00', 'stamp' => '2020-01-01 00:00:00' ] ],
	]));

	it('"modify" sets the time on every commit, "create" only on the first', function () {
		$item = Item::fetch(1);
		$item->title = 'New';
		$item->commit();

		expectState([ 'items' => [ [ 'id' => 1, 'title' => 'New', 'created_at' => '2020-01-01 00:00:00', 'modified_at' => now(), 'stamp' => now() ] ] ]);
		expect($item->modifiedAt->getTimestamp())->toBeGreaterThanOrEqual(time() - 2);
	});

	it('doesn\'t make a commit without changes write anything', function () {
		$item = Item::fetch(1);
		expect($item->title)->toBe('Old');

		expect(queries(fn () => $item->commit()))->toBe([]);
		expectUnchanged();
	});
});

describe('loading', function () {
	it('converts what the database returns', function () {
		given([ 'items' => [ [
			'id' => 1, 'count' => 5, 'ratio' => 2.5, 'price' => '1.5', 'amount' => '7', 'active' => 0, 'hidden' => 1,
			'birthday' => '2000-12-31', 'starts_at' => '2026-03-04 05:06:07', 'owner_ref' => 1,
			'subject_type' => 'Owner', 'subject_id' => 1, 'target_class' => 'Tests\Models\Basic\User', 'target_ref' => 5,
		] ] ]);

		$item = Item::fetch(1);

		expect(array_map('describeValue', [ $item->count, $item->ratio, $item->price, $item->amount, $item->active, $item->hidden,
			$item->birthday, $item->startsAt, $item->owner, $item->subject, $item->target ]))
			->toBe([ 5, 2.5, '1.5000', '7.00', false, true,
				'DateTime(2000-12-31 00:00:00 UTC)', 'DateTime(2026-03-04 05:06:07 UTC)', 'Tests\Models\Types\Owner#1',
				'Tests\Models\Types\Owner#1', 'Tests\Models\Basic\User#5' ]);
	});

	it('rejects a DynamicClass column that names a class that isn\'t an ActiveRecord', function () {
		// ★ BUGS-ALREADY-FIXED2 #14
		given([ 'items' => [ [ 'id' => 1, 'subject_type' => 'stdClass', 'subject_id' => 1 ] ] ]);

		expect(fn () => Item::fetch(1)->subject)->toThrow(Exception::class);
	});

	it('uses the time zone of the moment of loading for dates', function () {
		given([ 'items' => [ [ 'id' => 1, 'starts_at' => '2026-07-01 12:00:00' ] ] ]);
		date_default_timezone_set('Europe/Amsterdam');
		try {
			$item = Item::fetch(1);
			expect(describeValue($item->startsAt))->toBe('DateTime(2026-07-01 12:00:00 Europe/Amsterdam)');
		} finally {
			date_default_timezone_set('UTC');
		}
	});
});

describe('comparing with the current value', function () {
	beforeEach(fn () => given([
		'owners' => [ [ 'id' => 1, 'name' => 'Owner one' ] ],
		'items' => [ [ 'id' => 1, 'title' => 'T', 'starts_at' => '2026-01-01 12:00:00', 'price' => '1.5', 'ratio' => 0.5, 'owner_ref' => 1 ] ],
	]));

	it('treats an equal value as no change', function (string $property, Closure $value) {
		$item = Item::fetch(1);
		expect($item->title)->toBe('T'); // loads the dataset
		$item->$property = $value();

		expect(queries(fn () => $item->commit()))->toBe([]);
	})->with([
		'the same moment in another zone' => [ 'startsAt', fn () => '2026-01-01T13:00:00+01:00' ],
		'the same decimal written differently' => [ 'price', fn () => 1.5 ],
		'the same float from a string' => [ 'ratio', fn () => '0.5' ],
	]);

	it('treats the same object as no change', function () {
		$item = Item::fetch(1);
		$owner = $item->owner;
		$item->owner = $owner;

		expect(queries(fn () => $item->commit()))->toBe([]);
	});

	it('treats another instance of the same object as a change', function () {
		// Compared with ===, as the docs say (and a TODO in __set()); with the
		// ObjectCache, fetch() returns the same instance
		onlyWithoutCache();
		$item = Item::fetch(1);
		expect($item->owner)->not->toBeNull();
		$item->owner = Owner::fetch(1);

		expect(queries(fn () => $item->commit()))->not->toBe([]);
		expectState([ 'items' => [ [ 'id' => 1, 'owner_ref' => 1, 'modified_at' => now(), 'stamp' => now() ] ] ]);
	});
});

describe('the generated property types', function () {
	// Mostly checked by PHPStan, which analyses the tests: the assignments must
	// pass, the values read back must have the narrower read type (the typed
	// closures), and the ignored errors must still be reported
	it('accept more on assignment than they return for Decimal, Float, Date and DateTime', function () {
		$decimal = fn (?string $value) : ?string => $value;
		$float = fn (?float $value) : ?float => $value;
		$date = fn (?DateTimeImmutable $value) : ?string => $value?->format('Y-m-d H:i:s');

		$item = new Item();
		$item->price = 12;
		$item->amount = 12.5;
		$item->ratio = '2.5';
		$item->birthday = '2026-02-28';
		$item->startsAt = new DateTime('2026-01-01 12:30');

		expect([ $decimal($item->price), $decimal($item->amount), $float($item->ratio), $date($item->birthday), $date($item->startsAt) ])
			->toBe([ '12.0000', '12.50', 2.5, '2026-02-28 00:00:00', '2026-01-01 12:30:00' ]);

		$item->amount = null;
		$item->birthday = null;
		expect([ $item->amount, $item->birthday ])->toBe([ null, null ]);
	});

	it('are the same on assignment as they return for other types', function () {
		$item = new Item();
		$item->count = '42'; // @phpstan-ignore assign.propertyType (accepted at runtime, but not declared)
		$item->hidden = 1; // @phpstan-ignore assign.propertyType (accepted at runtime, but not declared)

		expect([ $item->count, $item->hidden ])->toBe([ 42, true ]);
	});
});
