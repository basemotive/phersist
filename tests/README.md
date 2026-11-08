# PHersist test suite

The tests for PHersist, written with [Pest](https://pestphp.com/) 4. They cover
the generators (model XML → classes and schemas) and everything PHersist does
at runtime, on every supported database, which run in Docker.

## Running the tests

```sh
composer test                       # everything, on SQLite, on this machine (fast; needs PHP 8.3+ with pdo_sqlite)
composer test:all                   # everything, on every database, in Docker
composer test:db -- mysql-8.4       # the given targets, in Docker
tests/docker/run.sh mariadb-11.8 -- --filter=Maps   # pest arguments after --
tests/docker/run.sh --down          # stop the database containers
```

`vendor/bin/pest` works too, with the usual options (`--filter`,
`--testsuite=Generator`, a file name). It runs on the target in
`PHERSIST_TEST_TARGET` (default `sqlite`).

`run.sh` builds the PHP runner image, starts the database containers that the
targets need, runs the suite once per target, and prints which targets passed
and failed. The containers keep running for quick re-runs. The generator tests
don't depend on the database, so they only run with the first target (unless
you pass pest arguments, which go to every run unchanged).

Only Docker is needed for `test:all`: PHP runs in a container too. If
`vendor/` is missing, `run.sh` installs it with the container's Composer.

### Targets

A target is a database server plus the schema dialect PHersist generates for
it. They're defined in `Support/Target.php`, and the servers in
`docker/compose.yml`.

| Target               | Server        | Dialect | Port on localhost |
|----------------------|---------------|---------|-------------------|
| `sqlite`             | (in-process)  | sqlite  | —                 |
| `sqlite-cache`       | (in-process)  | sqlite  | — (with ObjectCache) |
| `mysql-8.0`          | MySQL 8.0     | mysql   | 33080             |
| `mysql-8.4`          | MySQL 8.4     | mysql   | 33084             |
| `mysql-9`            | MySQL 9       | mysql   | 33090             |
| `mariadb-10.6`       | MariaDB 10.6  | mariadb | 33106             |
| `mariadb-10.11`      | MariaDB 10.11 | mariadb | 33111             |
| `mariadb-11.4`       | MariaDB 11.4  | mariadb | 33114             |
| `mariadb-11.8`       | MariaDB 11.8  | mariadb | 33118             |
| `mariadb-11.8-mysql` | MariaDB 11.8  | mysql   | 33118 (database `phersist_mysql`) |
| `local`              | your own      | mariadb | —                 |

- Every run checks that the server reports the expected version, so a run
  can't silently use another server.
- The containers keep their data in memory: every start is a clean server.
- From the host, the Docker targets are reached on their port on localhost,
  so `PHERSIST_TEST_TARGET=mysql-8.4 vendor/bin/pest` works once the
  containers are up (`run.sh` starts them; so does
  `docker compose -f tests/docker/compose.yml up -d --wait`).
- MySQL 5.7 isn't a target: the MySQL dialect needs MySQL 8.0 (the map
  collation `utf8mb4_0900_bin`).
- `local` is a server outside Docker, only on the host, for checking
  PHersist against an existing setup. It needs `PHERSIST_TEST_LOCAL_USER` and
  `PHERSIST_TEST_LOCAL_DATABASE`, and optionally `PHERSIST_TEST_LOCAL_HOST`
  (default `localhost`), `_PASSWORD` and `_DIALECT` (default `mariadb`).
  **Every table in that database gets dropped**, so point it at a throwaway
  database only. It isn't part of `test:all`; run it with
  `tests/docker/run.sh local` or `PHERSIST_TEST_TARGET=local vendor/bin/pest`.

### Environment variables

| Variable                    | Effect |
|-----------------------------|--------|
| `PHERSIST_TEST_TARGET`      | The target (default `sqlite`) |
| `PHERSIST_TEST_CACHE=on`    | Runs with `ObjectCache` enabled (the `sqlite-cache` target does this on SQLite) |
| `PHERSIST_RUN_BUGS=1`       | Runs the tests of known bugs instead of skipping them |
| `PHERSIST_UPDATE_EXPECTED=1`| Rewrites the expected generator output (see below) |
| `PHERSIST_TEST_LOCAL_*`     | The `local` target |

## Layout

```
phpunit.xml                  the Generator and Runtime test suites (in the repository root)
tests/
  Pest.php                   bootstrap, global hooks, and the helper functions
  phpstan-bootstrap.php      generates the shared models for PHPStan
  Support/                   the helpers: Target, Models, State, Matcher, QueryLog, Internals, Database, Generate
  Generator/                 tests of the generators, without a database
    fixtures/<name>/         model.xml and the expected/ output: <Class>.php, <dialect>.sql
  Runtime/                   tests that need the database
    models/                  the shared model XMLs of the runtime tests
  docker/                    compose.yml, php.Dockerfile, run.sh
  .generated/                what the tests generate, per target, kept for inspection (ignored by git)
```

## Generator tests

- **GeneratorTest**: each `Generator/fixtures/<name>/model.xml` must generate
  exactly the files in its `expected/` directory: the classes (including
  `PHersistReferences.php`) and the schema of every dialect. After an
  intended change in the output, run `PHERSIST_UPDATE_EXPECTED=1 vendor/bin/pest
  --testsuite=Generator`, and review the diff of the expected files before
  committing. Regenerate `examples/basic/sample-model.sql` too; ExamplesTest
  checks it.
- **InvalidModelTest**: invalid model XMLs with the exact error they must give.
  Each case is inline: the XML, the expected error, and the XMLs it
  `<depends>` on, if any. `<dir>` in an error stands for the directory the
  files are written to.
- **CliTest**: `bin/phersist`: its options, the files it writes, its errors
  and exit codes.
- **ExamplesTest**: `examples/basic/sample-model.sql` is up to date, and the
  `sample` fixture is the example's XML.

`Runtime/SchemaLoadTest` belongs with these, but needs the database: every
fixture's schema must load on the target, twice, with exactly the tables and
columns of the schema model.

## Runtime tests

Each test sets up a before-state in the database, does something with
PHersist, and checks the after-state, all in the test itself:

```php
<?php

use Tests\Models\Basic\{Post, User};

useModel('basic');

describe('deleting a user', function () {
	beforeEach(fn () => given([
		'users' => [
			[ 'id' => 1, 'name' => 'Ann' ],
			[ 'id' => 2, 'name' => 'Bob' ],
		],
		'posts' => [ [ 'id' => 10, 'title' => 'Hi', 'author_id' => 1 ] ],
	]));

	it('sets the author of their posts to null', function () {
		User::fetch(1)->delete();

		expectState([
			'users' => [ [ 'id' => 2 ] ],
			'posts' => [ [ 'id' => 10, 'author_id' => null ] ],
		]);
	});

	it('leaves the users alone when a post is deleted', function () {
		Post::fetch(10)->delete();

		expectState([ 'posts' => [] ]);
	});
});
```

### Models

The runtime tests use the shared models in `Runtime/models/`, each built
around one area:

| Model        | For |
|--------------|-----|
| `basic`      | Users, groups and posts: create, restore, update, delete, finder |
| `datasets`   | Datasets in other tables, named and positional; a class without properties; long ids; defaults and `update_on` |
| `types`      | Every property type |
| `softdelete` | Soft-deleted classes and what refers to them |
| `relations`  | Every relation setup |
| `maps`       | Maps with one and several keys, shared map tables |
| `references` | Every `on_remote_delete`, cascade chains and a cycle |
| `core`, `plugin` | Two XMLs, the plugin referring to the core (`<depends>`) |
| `global`     | Classes without a namespace |

Each XML starts with a comment listing its tables and columns, so you can
check a test's states against it without generating anything.

`useModel('basic', 'datasets')` at the top of a test file generates the models
(once per run) into `.generated/<target>/models/<name>/`, loads the generated
classes, and before the file's first test drops all tables and loads the
models' schemas. So every test file starts on a fresh database.

- Each model has its own namespace (`Tests\Models\<Model>`), so all of them
  can be loaded in one PHP process.
- Models used together in a file can't have a table with the same name;
  `useModel()` throws if they do.
- For an edge case that would only clutter a shared model,
  `useInlineModel('name', $xml)` takes the XML in the test file itself. PHPStan
  doesn't know the classes of such a model (see below), so prefer a shared one.

### Before-state: `given()`

`given([ 'table' => [ rows ] ])` empties **every** table of the loaded models,
then inserts the rows with plain SQL (not through PHersist), and remembers the
result as the before-state.

- Columns left out of a row get their column default.
- Tables that aren't listed are empty; `'table' => []` makes that explicit.
- Auto-increment counters are reset, so new objects get predictable ids (the
  next after the highest one given). Note that MySQL and MariaDB don't reuse
  the id of an insert that was rolled back.
- Every test starts with empty tables, also without `given()`.
- A `given()` replaces the whole state, also one in an outer `beforeEach`; it
  doesn't add to it.
- Put everything the test starts from in `given()`, rather than changing the
  database with SQL afterwards: those changes aren't in the before-state, so
  the checks below would report them.

### Expected state: `expectState()` and `expectUnchanged()`

`expectState([ 'table' => [ rows ] ])` is strict:

- A listed table must have exactly the listed rows, no more and no fewer, in
  any order. Rows are matched by primary key.
- A column left out of an expected row must still have its before-state value,
  so `[ 'id' => 10, 'author_id' => null ]` means "only `author_id` changed".
  For a new row (an id that wasn't in the before-state), left-out columns
  aren't checked.
- Expected rows need their id. Tables without a primary key (join and map
  tables) need every column in every row.
- Every table that isn't listed must be unchanged, so stray writes fail the
  test without anyone having to think of them.
- `'table' => []` means the table is empty now.
- `expectUnchanged()` means nothing changed at all. `expectState([])` is
  rejected, since it would read as "everything is empty".
- Unknown tables and columns throw, with the names that do exist.

Values that aren't known exactly can be matchers:

| Matcher        | Matches |
|----------------|---------|
| `any()`        | any value but `null` |
| `now()`        | a date/time during the test (whole seconds), or for a `DATE` column, a date during the test; for `update_on` |
| `that(fn ($v) => ..., 'description')` | a value for which the function returns `true` |

### Normalization

Every database driver returns values differently (ints as strings, `9.9900`
for `9.99`, `0`/`1` for booleans). Before comparing, the actual and the
expected values are normalized by their column's logical type, from the
generator's schema model:

- ids, references and ints become `int`; booleans become `0`/`1` (so `true`
  may be written in a test);
- decimals become a string with the column's scale, without going through a
  float;
- floats are rounded to 12 significant digits;
- a `DateTimeInterface` in a test becomes `Y-m-d` or `Y-m-d H:i:s`; date
  strings from the database are left as they are, so a database that stores
  something else shows up;
- text, class names, map keys and values are compared exactly, byte for byte.

### Other helpers

| Helper | Does |
|---|---|
| `queries(fn () => ...)` | Returns the SQL statements the function ran (everything PHersist runs with `prepare()`, which is all but savepoints) |
| `knownBug('description')` | Skips the test as a known bug, unless `PHERSIST_RUN_BUGS=1` (see below) |
| `skip('reason')` | Skips the test (instead of `$this->markTestSkipped()`, see below) |
| `onlyOn('mysql', 'mariadb')` | Skips the test unless the target has one of these dialects |
| `onlyWithCache()`, `onlyWithoutCache()` | Skips the test unless the run does or doesn't use `ObjectCache` |
| `db()`, `target()` | The test connection (registered as `main`) and the target |

Values in memory are checked with plain `expect()`. Both kinds of checks are
often needed, because some behaviour is only in memory (pending changes,
relations, maps after a rollback, the cache).

### Every test starts and ends clean

Before each runtime test, the tables are emptied, `ObjectCache` is emptied and
switched on or off per `PHERSIST_TEST_CACHE`, and the namespaces registered
with `ReferenceRegistry` are forgotten. This goes through reflection
(`Support/Internals.php`), so `src/` has nothing just for the tests.

After each test, the suite checks that PHersist is at rest: no open
transaction, no internal flag left set (`ActiveRecord::$_fetching`,
`$_deleting`), the PDO error mode back to `ERRMODE_EXCEPTION`. If not, the test
fails, and the state is reset so the next tests aren't affected.

### With and without ObjectCache

`ObjectCache` changes which instances PHersist hands out, so every runtime test
must pass both with the cache off (PHersist's default) and on. `test:all` runs
every database with the cache off, plus SQLite with the cache on
(`sqlite-cache`). A test whose expectation really depends on the cache says so
with `onlyWithCache()` or `onlyWithoutCache()`. `ObjectCacheTest` switches the
cache itself, so it covers both states in every run.

### Regression tests

Tests for earlier bug fixes are marked with ★ and the commit or bug report,
like `// ★ 28b90a5 / BUGS-ALREADY-FIXED4 #8`, in the file of the feature they
cover.

### Known bugs

A test that shows a bug that isn't fixed yet starts with `knownBug('...')`. It's
skipped with the description as the reason, so `vendor/bin/pest` lists the
known bugs among the skipped tests. The rest of the test is the real test:
fixing the bug means removing that line. `PHERSIST_RUN_BUGS=1` runs them
anyway, to check a fix or see that a bug is still there.

The suite doesn't fix bugs; finding one means adding a `knownBug()` test.

Current known bugs:

- `$obj->relation[] = $x` does nothing for NN relations; assign a full array
  (`RelationsTest`).
- Relations defined from both sides aren't kept in sync in memory
  (`RelationsTest`).
- `ObjectCache::evict()` does nothing while the cache is disabled, so after
  enabling it again, `fetch()` returns an object that was deleted in the
  meantime, without an id (`ObjectCacheTest`).
- `DBConnectionManager::newSQLiteConnection()` doesn't set `PDO::FETCH_ASSOC`
  as the default fetch mode, like `newMySQLConnection()` does
  (`ConnectionTest`, SQLite only).
- Reference cleanup on another connection isn't part of the delete's
  transaction (`BUGS.md` #13; `ConnectionTest`).
- A `TimestampText` with the default `date_format` is a `DATETIME` column on
  MySQL and MariaDB, so comparing it with a partial timestamp like `'2026-11'`
  (which the docs allow for `<`, `>`, `<=`, `>=`) throws on MySQL and matches
  nothing on MariaDB (`FinderTest`, MySQL and MariaDB only).

## Static analysis

PHPStan analyses the tests like `src/` (level 6, `phpstan.neon`): the helpers
in `Support/` and the test files themselves. `tests/phpstan-bootstrap.php`
generates the shared models first, so PHPStan knows their classes and their
`@property` declarations; a typo like `$user->nmae` or `$user->comit()` in a
test is reported.

Pest runs the test closures bound to a test case object, but PHPStan sees
`$this` in them as Pest's `TestCall`. So the test files don't use `$this`:

- No state in `$this` set by `beforeEach()`. Use a small helper function that
  builds the state, and call it at the start of each test (like
  `deletedWriter()` in `SoftDeleteTest`).
- `skip('reason')` instead of `$this->markTestSkipped()`.

A few lines do on purpose what PHPStan rightly reports, like reading a
property that doesn't exist to check that it throws. Those have a
`@phpstan-ignore` with the exact error identifier and the reason. Don't use
one for anything else.

## Adding tests

- Put a test in the file of the feature it's about; add a file for a new
  feature, with a docblock that says what it covers and which docs it follows.
- The docs are the spec: behaviour described in `docs/` should have a test,
  and where the code and the docs disagree, that's a finding (a bug or a docs
  fix).
- Prefer a shared model. Extend one when a test needs something it doesn't
  have, and update its header comment.
- New property types, relation setups or dialects usually need a generator
  fixture as well as runtime tests.
