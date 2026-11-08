<?php

/**
 * Maps: reading, writing, removing and replacing entries, the conversions and
 * checks of keys and values, shared map tables, and maps after reload() and
 * delete(). See docs/using-model-in-php.md, "Working with maps", and
 * docs/creating-model-from-xml.md, "Maps".
 */

use PHersist\Maps\Map;
use Tests\Models\Maps\{Account, Team};

useModel('maps');

/**
 * A map's contents as an array with sorted keys, at every level: maps don't
 * keep an order, and databases return the rows in any order.
 *
 * @param Map|array<mixed> $map
 * @return array<mixed>
 */
function sortedMap(Map|array $map) : array {
	$array = $map instanceof Map ? $map->toArray() : $map;
	ksort($array, SORT_STRING);
	foreach ($array as $key => $value)
		if (is_array($value))
			$array[$key] = sortedMap($value);
	return $array;
}

beforeEach(fn () => given([
	'accounts' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
	'teams' => [ [ 'id' => 1, 'name' => 'Team one' ] ],
	'settings' => [
		[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'dark' ],
		[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'ui', 'name' => 'lang', 'value' => 'nl' ],
		[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'mail', 'name' => 'daily', 'value' => '1' ],
		[ 'owner_type' => 'Account', 'owner_id' => 2, 'section' => 'ui', 'name' => 'theme', 'value' => 'light' ],
		[ 'owner_type' => 'Tests\Models\Maps\Team', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'blue' ],
	],
	'account_flags' => [ [ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ] ],
]));

describe('reading', function () {
	it('returns values by key, and null for missing keys', function () {
		$account = Account::fetch(1);

		expect($account->flags['beta'])->toBe('on');
		expect($account->flags['nothing'])->toBeNull();
		expect($account->settings['ui']['theme'])->toBe('dark');
		expect($account->settings['nothing']['theme'])->toBeNull();
		expect($account->flags['nothing'] ?? 'default')->toBe('default');
	});

	it('supports isset(), also on a submap', function () {
		$account = Account::fetch(1);

		expect(isset($account->flags['beta']))->toBeTrue();
		expect(isset($account->flags['nothing']))->toBeFalse();
		expect(isset($account->settings['mail']))->toBeTrue();
		expect(isset($account->settings['nothing']))->toBeFalse();
	});

	it('only reads the rows of its own class from a shared table', function () {
		// Team 1 and Account 1 have the same id; Team stores its class with namespace
		expect(sortedMap(Team::fetch(1)->settings))->toBe(sortedMap([ 'ui' => [ 'theme' => 'blue' ] ]));
		expect(sortedMap(Account::fetch(2)->settings))->toBe(sortedMap([ 'ui' => [ 'theme' => 'light' ] ]));
	});

	it('converts to an array, counts, loops and encodes to JSON', function () {
		$account = Account::fetch(1);

		expect(sortedMap($account->settings))->toBe(sortedMap([ 'ui' => [ 'theme' => 'dark', 'lang' => 'nl' ], 'mail' => [ 'daily' => '1' ] ]));
		expect(sortedMap($account->settings['ui']))->toBe(sortedMap([ 'theme' => 'dark', 'lang' => 'nl' ]));
		expect(count($account->settings))->toBe(2);
		expect(count($account->settings['ui']))->toBe(2);
		$looped = [];
		foreach ($account->settings['ui'] as $name => $value)
			$looped[$name] = $value;
		expect(sortedMap($looped))->toBe([ 'lang' => 'nl', 'theme' => 'dark' ]);
		expect(sortedMap(json_decode(json_encode($account->settings), true)))->toBe(sortedMap([ 'ui' => [ 'theme' => 'dark', 'lang' => 'nl' ], 'mail' => [ 'daily' => '1' ] ]));
	});

	it('encodes an empty map and numeric keys as a JSON object', function () {
		$account = Account::fetch(2);
		expect(json_encode($account->flags))->toBe('{}');

		$account->flags['5'] = 'five';
		expect(json_encode($account->flags))->toBe('{"5":"five"}');
		expect(sortedMap($account->flags))->toBe(sortedMap([ 5 => 'five' ]));
	});

	it('is an object, so empty() is always false; count() tells', function () {
		$account = Account::fetch(2);

		expect($account->flags)->toBeInstanceOf(Map::class);
		expect(empty($account->flags))->toBeFalse();
		expect(count($account->flags))->toBe(0);
	});

	it('gives a copy with toArray()', function () {
		$account = Account::fetch(1);
		$array = $account->flags->toArray();
		$array['beta'] = 'off';

		expect($account->flags['beta'])->toBe('on');
	});

	it('is loaded with one query', function () {
		$account = Account::fetch(1);

		expect(queries(fn () => $account->settings['ui']['theme']))->toHaveCount(1);
		expect(queries(fn () => $account->settings['mail']['daily']))->toBe([]);
	});
});

describe('writing', function () {
	it('stores a new entry', function () {
		$account = Account::fetch(2);
		$account->flags['beta'] = 'off';
		$account->settings['mail']['daily'] = '0';
		$account->commit();

		expectState([
			'account_flags' => [
				[ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ],
				[ 'account_id' => 2, 'flag' => 'beta', 'state' => 'off' ],
			],
			'settings' => [
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'dark' ],
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'ui', 'name' => 'lang', 'value' => 'nl' ],
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'mail', 'name' => 'daily', 'value' => '1' ],
				[ 'owner_type' => 'Account', 'owner_id' => 2, 'section' => 'ui', 'name' => 'theme', 'value' => 'light' ],
				[ 'owner_type' => 'Account', 'owner_id' => 2, 'section' => 'mail', 'name' => 'daily', 'value' => '0' ],
				[ 'owner_type' => 'Tests\Models\Maps\Team', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'blue' ],
			],
		]);
	});

	it('changes an entry', function () {
		$account = Account::fetch(1);
		$account->flags['beta'] = 'off';
		$account->commit();

		expectState([ 'account_flags' => [ [ 'account_id' => 1, 'flag' => 'beta', 'state' => 'off' ] ] ]);
	});

	it('stores the class name with namespace for use_namespace="true"', function () {
		$team = Team::fetch(1);
		$team->settings['mail']['daily'] = '1';
		$team->commit();

		expect(db()->query("select owner_type from settings where section = 'mail' and owner_id = 1 and owner_type <> 'Account'")->fetchColumn())
			->toBe('Tests\Models\Maps\Team');
	});

	it('removes an entry set to null, or unset', function () {
		$account = Account::fetch(1);
		$account->settings['ui']['theme'] = null;
		unset($account->settings['ui']['lang']);
		$account->flags['beta'] = null;
		$account->commit();

		expectState([
			'account_flags' => [],
			'settings' => [
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'mail', 'name' => 'daily', 'value' => '1' ],
				[ 'owner_type' => 'Account', 'owner_id' => 2, 'section' => 'ui', 'name' => 'theme', 'value' => 'light' ],
				[ 'owner_type' => 'Tests\Models\Maps\Team', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'blue' ],
			],
		]);
		expect(isset($account->settings['ui']))->toBeFalse();
	});

	it('removes a submap with unset()', function () {
		$account = Account::fetch(1);
		unset($account->settings['ui']);
		$account->commit();

		expect(sortedMap(Account::fetch(1)->settings))->toBe(sortedMap([ 'mail' => [ 'daily' => '1' ] ]));
	});

	it('replaces the whole map on assignment', function () {
		$account = Account::fetch(1);
		$account->settings = [ 'ui' => [ 'theme' => 'light' ], 'new' => [ 'x' => 'y' ] ];
		$account->commit();

		$account->reload();
		expect(sortedMap($account->settings))->toBe(sortedMap([ 'ui' => [ 'theme' => 'light' ], 'new' => [ 'x' => 'y' ] ]));
		expect(db()->query("select count(*) from settings where owner_type = 'Account' and owner_id = 1")->fetchColumn())->toEqual(2);
	});

	it('replaces a submap on assignment', function () {
		$account = Account::fetch(1);
		$account->settings['ui'] = [ 'font' => 'mono' ];
		$account->commit();

		$account->reload();
		expect(sortedMap($account->settings))->toBe(sortedMap([ 'ui' => [ 'font' => 'mono' ], 'mail' => [ 'daily' => '1' ] ]));
	});

	it('is emptied by assigning an empty array', function () {
		$account = Account::fetch(1);
		$account->flags = [];
		$account->commit();

		expectState([ 'account_flags' => [] ]);
	});

	it('does the same with set()', function () {
		$account = Account::fetch(1);
		$account->settings['ui']->set([ 'font' => 'mono' ]);
		$account->flags->set([ 'alpha' => 'on' ]);
		$account->commit();

		$account->reload();
		expect(sortedMap($account->settings))->toBe(sortedMap([ 'ui' => [ 'font' => 'mono' ], 'mail' => [ 'daily' => '1' ] ]));
		expect(sortedMap($account->flags))->toBe(sortedMap([ 'alpha' => 'on' ]));
	});

	it('accepts another map on assignment', function () {
		$team = Team::fetch(1);
		$account = Account::fetch(2);
		$account->settings = $team->settings;
		$account->commit();

		$account->reload();
		expect(sortedMap($account->settings))->toBe(sortedMap([ 'ui' => [ 'theme' => 'blue' ] ]));
		expect(sortedMap($team->settings))->toBe(sortedMap([ 'ui' => [ 'theme' => 'blue' ] ]));
	});

	it('stores many keys, also without a unique index', function () {
		$account = Account::fetch(2);
		$account->matrix['1']['2']['3']['4']['5'] = 'deep';
		$account->matrix['1']['2']['3']['4']['6'] = 'deeper';
		$account->commit();

		expectState([ 'account_matrix' => [
			[ 'account_id' => 2, 'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'v' => 'deep' ],
			[ 'account_id' => 2, 'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '6', 'v' => 'deeper' ],
		] ]);
		$account->reload();
		expect(sortedMap($account->matrix['1']['2']['3']['4']))->toBe(sortedMap([ 5 => 'deep', 6 => 'deeper' ]));
	});

	it('is stored for a new object', function () {
		$account = new Account();
		$account->name = 'New';
		$account->flags['beta'] = 'on';
		$account->commit();

		expectState([
			'accounts' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3, 'name' => 'New' ] ],
			'account_flags' => [
				[ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ],
				[ 'account_id' => 3, 'flag' => 'beta', 'state' => 'on' ],
			],
		]);
	});

	it('isn\'t written when it didn\'t change', function () {
		$account = Account::fetch(1);
		expect($account->flags['beta'])->toBe('on'); // loads the map

		expect(queries(fn () => $account->commit()))->toBe([]);
	});

	it('can\'t be committed for an object without a row', function () {
		// ★ BUGS-ALREADY-FIXED4 #8: wrote map rows for a missing object
		$account = Account::fetch(99);
		$account->flags['beta'] = 'on';

		expect(fn () => $account->commit())->toThrow(Exception::class);
		expectUnchanged();
	});
});

describe('keys and values', function () {
	it('are converted to strings', function () {
		$account = Account::fetch(2);
		// Map declares string keys (ArrayAccess<string, mixed>), but ints are converted, as the docs say
		$account->flags[7] = 1.5; // @phpstan-ignore offsetAssign.dimType
		$account->flags['obj'] = new class { public function __toString() : string { return 'stringable'; } };
		$account->commit();

		expectState([ 'account_flags' => [
			[ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ],
			[ 'account_id' => 2, 'flag' => '7', 'state' => '1.5' ],
			[ 'account_id' => 2, 'flag' => 'obj', 'state' => 'stringable' ],
		] ]);
	});

	it('are case-sensitive, and trailing spaces count, on every database', function () {
		// ★ 2f76455 / BUGS-ALREADY-FIXED3 #6
		$account = Account::fetch(2);
		$account->flags['key'] = 'lower';
		$account->flags['KEY'] = 'upper';
		$account->flags['key '] = 'trailing space';
		$account->flags['kéy'] = 'accent';
		$account->commit();

		$account->reload();
		expect($account->flags->toArray())->toEqualCanonicalizing([ 'key' => 'lower', 'KEY' => 'upper', 'key ' => 'trailing space', 'kéy' => 'accent' ]);
		expect(count($account->flags))->toBe(4);
	});

	it('keep multibyte text and trailing spaces in values', function () {
		$account = Account::fetch(2);
		$account->flags['emoji'] = '🎉 Zoë  ';
		$account->commit();

		$account->reload();
		expect($account->flags['emoji'])->toBe('🎉 Zoë  ');
	});

	it('are rejected when they aren\'t string-like, and the map stays unchanged', function (Closure $change) {
		// ★ 1f661bd / BUGS-ALREADY-FIXED3 #5
		$account = Account::fetch(1);
		$before = $account->settings->toArray();

		expect(fn () => $change($account))->toThrow(InvalidArgumentException::class);
		expect(sortedMap($account->settings))->toBe(sortedMap($before));
		expect(sortedMap($account->flags))->toBe(sortedMap([ 'beta' => 'on' ]));
	})->with([
		'bool value' => [ function ($account) { $account->flags['x'] = false; } ],
		'array value at the deepest level' => [ function ($account) { $account->flags['x'] = [ 'a' => 'b' ]; } ],
		'append without a key' => [ function ($account) { $account->flags[] = 'x'; } ],
		'invalid UTF-8 value' => [ function ($account) { $account->flags['x'] = "\xff\xfe"; } ],
		'invalid UTF-8 key' => [ function ($account) { $account->flags["\xff"] = 'x'; } ],
		'too shallow' => [ function ($account) { $account->settings = [ 'ui' => 'dark' ]; } ],
		'too deep' => [ function ($account) { $account->settings = [ 'ui' => [ 'theme' => [ 'x' => 'dark' ] ] ]; } ],
		'string for a submap' => [ function ($account) { $account->settings['ui'] = 'dark'; } ],
		'object value' => [ function ($account) { $account->flags['x'] = new stdClass(); } ],
	]);

	it('can\'t be set to something else than an array', function () {
		// ★ 2b2f3b9
		$account = Account::fetch(1);

		expect(function () use ($account) { $account->flags = 'on'; })->toThrow(Exception::class);
		expect(function () use ($account) { $account->flags = null; })->toThrow(Exception::class);
	});
});

describe('after reload()', function () {
	it('a map read before can still be read, but not changed', function () {
		// ★ 09c2908 / BUGS-ALREADY-FIXED3 #3
		$account = Account::fetch(1);
		$flags = $account->flags;
		expect($flags['beta'])->toBe('on');
		$account->reload();

		expect($flags['beta'])->toBe('on');
		expect(function () use ($flags) { $flags['beta'] = 'off'; })->toThrow(Exception::class);
		expect(queries(fn () => $account->commit()))->toBe([]);
		expectUnchanged();
	});

	it('the property returns a fresh map', function () {
		$account = Account::fetch(1);
		expect($account->flags['beta'])->toBe('on'); // loads the map
		db()->exec("update account_flags set state = 'off'");
		$account->reload();

		expect($account->flags['beta'])->toBe('off');
	});
});

describe('delete()', function () {
	it('removes the map rows of the object, also of maps that weren\'t read', function () {
		// ★ BUGS-ALREADY-FIXED1 #4: delete() crashed if a map was never read
		Account::fetch(1)->delete();

		expectState([
			'accounts' => [ [ 'id' => 2 ] ],
			'account_flags' => [],
			'settings' => [
				[ 'owner_type' => 'Account', 'owner_id' => 2, 'section' => 'ui', 'name' => 'theme', 'value' => 'light' ],
				[ 'owner_type' => 'Tests\Models\Maps\Team', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'blue' ],
			],
		]);
	});

	it('only removes the rows of its own class from a shared table', function () {
		Team::fetch(1)->delete();

		expectState([
			'teams' => [],
			'settings' => [
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'ui', 'name' => 'theme', 'value' => 'dark' ],
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'ui', 'name' => 'lang', 'value' => 'nl' ],
				[ 'owner_type' => 'Account', 'owner_id' => 1, 'section' => 'mail', 'name' => 'daily', 'value' => '1' ],
				[ 'owner_type' => 'Account', 'owner_id' => 2, 'section' => 'ui', 'name' => 'theme', 'value' => 'light' ],
			],
		]);
	});

	it('makes the map of the deleted object read-only', function () {
		$account = Account::fetch(1);
		$flags = $account->flags;
		$account->delete();

		expect(function () use ($flags) { $flags['beta'] = 'off'; })->toThrow(Exception::class);
	});
});
