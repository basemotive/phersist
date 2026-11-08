<?php

/**
 * The connections DBConnectionManager makes, and classes with their own
 * database. See docs/using-model-in-php.md, "Runtime setup".
 */

use PHersist\DB\DBConnectionManager;
use Tests\Models\Connections\{ConnLocal, ConnRemote};
use Tests\Support\Database;
use Tests\Support\Models;

useModel('connections');

describe('the target database', function () {
	it('is reachable', function () {
		expect(db()->query('select 1')->fetchColumn())->toEqual(1);
	});

	it('is the intended server version', function () {
		$expected = target()->expectedVersion;
		if ($expected === null)
			skip('No expected version for target '.target()->name);

		// MariaDB versions look like 11.8.3-MariaDB-ubu2404 (or 5.5.5-11.8.3-MariaDB with old clients)
		$version = preg_replace('/^5\.5\.5-/', '', target()->serverVersion());
		expect($version)->toStartWith($expected);
		expect(str_contains($version, 'MariaDB'))->toBe(str_starts_with(target()->name, 'mariadb'));
	});
});

describe('DBConnectionManager', function () {
	it('returns a registered connection, and null for an unknown id', function () {
		expect(DBConnectionManager::getPDO('main'))->toBe(db());
		expect(DBConnectionManager::getPDO('nothing'))->toBeNull();
	});

	it('makes connections that throw on errors', function () {
		expect(db()->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION);
	});

	it('makes connections with the same default fetch mode for every database', function () {
		if (target()->dialect === 'sqlite')
			knownBug('newSQLiteConnection() doesn\'t set PDO::FETCH_ASSOC as the default fetch mode, like newMySQLConnection() does');
		expect(db()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE))->toBe(PDO::FETCH_ASSOC);
	});
});

/**
 * The connection 'other' to a second database, with the connections model's
 * schema; skips the test for a target with one database.
 */
function otherDatabase() : PDO {
	$other = target()->otherPdo('other') ?? skip('The target has only one database');
	(new Database($other, target()->dialect))->loadSchema(Models::sql('connections'));
	return $other;
}

describe('a class with its own database', function () {

	it('is stored in and loaded from that database', function () {
		$other = otherDatabase();
		$remote = new ConnRemote();
		$remote->name = 'Far away';
		$remote->commit();
		$local = new ConnLocal();
		$local->name = 'Close by';
		$local->commit();

		expect($other->query('select name from conn_remotes')->fetchAll(PDO::FETCH_COLUMN))->toBe([ 'Far away' ]);
		expect($other->query('select count(*) from conn_locals')->fetchColumn())->toEqual(0);
		expectState([ 'conn_locals' => [ [ 'id' => 1, 'name' => 'Close by' ] ] ]);

		$remote->reload();
		expect($remote->name)->toBe('Far away');
	});

	it('has its references in the main database cleaned up', function () {
		$other = otherDatabase();
		$other->exec("insert into conn_remotes (id, name) values (1, 'Far away')");
		given([ 'conn_locals' => [ [ 'id' => 1, 'name' => 'Close by', 'remote_id' => 1 ] ] ]);

		ConnRemote::fetch(1)->delete();

		expectState([ 'conn_locals' => [ [ 'id' => 1, 'remote_id' => null ] ] ]);
		expect($other->query('select count(*) from conn_remotes')->fetchColumn())->toEqual(0);
	});

	it('leaves its references alone when the delete fails', function () {
		knownBug('Reference cleanup on another connection isn\'t part of the delete\'s transaction (BUGS.md #13): the null is stored although a restrict stops the delete');
		$other = otherDatabase();
		$other->exec("insert into conn_remotes (id, name) values (1, 'Far away')");
		given([
			'conn_locals' => [ [ 'id' => 1, 'name' => 'Close by', 'remote_id' => 1 ] ],
			'conn_blockers' => [ [ 'id' => 1, 'remote_id' => 1 ] ],
		]);

		expect(fn () => ConnRemote::fetch(1)->delete())->toThrow(Exception::class);

		expectUnchanged();
		expect($other->query('select count(*) from conn_remotes')->fetchColumn())->toEqual(1);
	});
});
