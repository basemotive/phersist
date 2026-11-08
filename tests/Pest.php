<?php

/**
 * Bootstrap and helpers for the PHersist test suite. See tests/README.md.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */

use Pest\TestSuite;
use PHPUnit\Framework\Assert;
use Tests\Support\Files;
use Tests\Support\Internals;
use Tests\Support\Matcher;
use Tests\Support\Models;
use Tests\Support\QueryLog;
use Tests\Support\State;
use Tests\Support\Target;

// Tests that depend on the time zone set their own
date_default_timezone_set('UTC');

/**
 * The target database of this run.
 */
function target() : Target {
	return Target::current();
}

/**
 * The connection to the target database.
 */
function db() : PDO {
	return Target::current()->pdo();
}

/**
 * A new, empty directory for a model XML and what's generated from it.
 */
function tempModelDir() : string {
	return Files::tempDir();
}

/**
 * Skips the current test.
 */
function skip(string $reason) : never {
	Assert::markTestSkipped($reason);
}

/*
 * Runtime tests: every test starts with empty tables and a fresh PHersist
 * state, and must leave PHersist at rest.
 */
pest()->in('Runtime')
	->beforeEach(function () {
		Models::loadFor(TestSuite::getInstance()->getFilename());
		Internals::reset();
		State::reset();
	})
	->afterEach(function () {
		$problems = Internals::problemsAtRest(db());
		expect($problems)->toBe([], 'PHersist should be at rest after the test');
	});

/**
 * Uses shared models from tests/Runtime/models/ in this test file: generates
 * them (once per process), and loads their schema on a fresh database before
 * the file's first test.
 */
function useModel(string ...$names) : void {
	foreach ($names as $name)
		Models::generate($name);
	Models::useIn(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'], $names);
}

/**
 * Uses a model that's defined in the test file itself, for an edge case that
 * would only clutter the shared models.
 *
 * @param string $name a name for it, unique in the suite
 */
function useInlineModel(string $name, string $xml) : void {
	Models::generate($name, $xml);
	Models::useIn(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'], [ $name ]);
}

/**
 * Sets up the before-state: empties all tables and inserts the given rows.
 *
 * @param array<string, list<array<string, mixed>>> $tables rows by table name
 */
function given(array $tables) : void {
	State::given($tables);
}

/**
 * Checks the listed tables exactly (a left-out column keeps its before-state
 * value), and that every other table is unchanged.
 *
 * @param array<string, list<array<string, mixed>>> $tables expected rows by table name
 */
function expectState(array $tables) : void {
	State::expect($tables);
}

/**
 * Checks that no table changed since given().
 */
function expectUnchanged() : void {
	State::expectUnchanged();
}

/**
 * In an expected state: any value but null.
 */
function any() : Matcher {
	return Matcher::any();
}

/**
 * In an expected state: a date/time during the test.
 */
function now() : Matcher {
	return Matcher::now();
}

/**
 * In an expected state: a value for which the function returns true.
 */
function that(Closure $fn, string $description = 'that(...)') : Matcher {
	return Matcher::that($fn, $description);
}

/**
 * Runs a function and returns the SQL statements it executed.
 *
 * @return list<string>
 */
function queries(callable $fn) : array {
	return QueryLog::record($fn);
}

/**
 * Marks the current test as a known bug: it's skipped, unless
 * PHERSIST_RUN_BUGS=1. The rest of the test is the real test, so fixing the
 * bug means removing this call.
 */
function knownBug(string $description) : void {
	if (!getenv('PHERSIST_RUN_BUGS'))
		skip("Known bug: {$description}");
}

/**
 * Skips the current test unless the target uses one of the given dialects.
 */
function onlyOn(string ...$dialects) : void {
	if (!in_array(target()->dialect, $dialects, true))
		skip('Only on '.implode(', ', $dialects));
}

/**
 * Skips the current test unless this run uses ObjectCache.
 */
function onlyWithCache() : void {
	if (!Target::cacheEnabled())
		skip('Only with ObjectCache (PHERSIST_TEST_CACHE=on)');
}

/**
 * Skips the current test when this run uses ObjectCache.
 */
function onlyWithoutCache() : void {
	if (Target::cacheEnabled())
		skip('Only without ObjectCache');
}
