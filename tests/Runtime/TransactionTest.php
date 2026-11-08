<?php

/**
 * Transactions: commit and rollback, nesting with savepoints, restoring the
 * objects in memory on rollback, and failing statements inside commit(). See
 * docs/using-model-in-php.md, "Transactions".
 */

use PHersist\Transaction;
use Tests\Models\Basic\{Group, User};
use Tests\Models\Datasets\Person;
use Tests\Models\Maps\Account;

useModel('basic', 'datasets', 'maps');

beforeEach(fn () => given([
	'users' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
	'groups' => [ [ 'id' => 1, 'name' => 'Admins' ] ],
	'accounts' => [ [ 'id' => 1, 'name' => 'Acc' ] ],
	'account_flags' => [ [ 'account_id' => 1, 'flag' => 'beta', 'state' => 'on' ] ],
]));

/**
 * Makes the next statement on person_extras fail, by renaming the table, and
 * runs the function; then puts the table back. Not inside a transaction: on
 * MySQL, DDL commits it.
 */
function withBrokenExtrasTable(Closure $fn) : void {
	db()->exec('alter table person_extras rename to person_extras_away');
	try {
		$fn();
	} finally {
		db()->exec('alter table person_extras_away rename to person_extras');
	}
}

class RollbackPlease extends Exception {}

describe('Transaction::run()', function () {
	it('commits when the function returns, and returns its value', function () {
		$result = Transaction::run('main', function () {
			$user = new User();
			$user->name = 'Cy';
			$user->commit();
			$bob = User::fetch(2);
			$bob->name = 'Bobby';
			$bob->commit();
			return 'done';
		});

		expect($result)->toBe('done');
		expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2, 'name' => 'Bobby' ], [ 'id' => 3, 'name' => 'Cy' ] ] ]);
	});

	it('rolls back and rethrows when the function throws', function () {
		expect(fn () => Transaction::run('main', function () {
			$user = new User();
			$user->name = 'Cy';
			$user->commit();
			User::fetch(1)->delete();
			throw new RollbackPlease();
		}))->toThrow(RollbackPlease::class);

		expectUnchanged();
	});

	it('fails for an unknown connection', function () {
		expect(fn () => Transaction::run('nothing', fn () => null))->toThrow(Exception::class);
	});
});

describe('a rollback', function () {
	it('makes a new object new again, with its changes pending', function () {
		$user = new User();
		$user->name = 'Cy';
		try {
			Transaction::run('main', function () use ($user) {
				$user->commit();
				expect($user->id)->toBe(3);
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expect($user->id)->toBeNull();
		expect($user->name)->toBe('Cy');
		$user->commit();
		expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => $user->id, 'name' => 'Cy' ] ] ]);
	});

	it('makes the changes of a committed object pending again, and undoes later ones', function () {
		$user = User::fetch(1);
		$user->name = 'Anna';
		try {
			Transaction::run('main', function () use ($user) {
				$user->commit();
				$user->email = 'later@example.com'; // after the first commit: undone
				$user->commit();
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expectUnchanged();
		expect($user->name)->toBe('Anna');
		expect($user->email)->toBeNull();
		$user->commit();
		expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Anna', 'email' => null ], [ 'id' => 2 ] ] ]);
	});

	it('makes a deleted object not deleted', function () {
		$user = User::fetch(2);
		try {
			Transaction::run('main', function () use ($user) {
				$user->delete();
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expect($user->isDeleted())->toBeFalse();
		expect($user->id)->toBe(2);
		expect($user->name)->toBe('Bob');
		$user->name = 'Bobby';
		$user->commit();
		expectState([ 'users' => [ [ 'id' => 1 ], [ 'id' => 2, 'name' => 'Bobby' ] ] ]);
	});

	it('restores relations and maps', function () {
		$user = User::fetch(1);
		$account = Account::fetch(1);
		$account->flags['beta'] = 'off';
		try {
			Transaction::run('main', function () use ($user, $account) {
				$user->groups = [ Group::fetch(1) ];
				$user->commit();
				$account->commit();
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expectUnchanged();
		expect(array_map(fn ($group) => $group->id, $user->groups))->toBe([ 1 ]); // pending again
		expect($account->flags['beta'])->toBe('off');
		$account->commit();
		expectState([ 'account_flags' => [ [ 'account_id' => 1, 'flag' => 'beta', 'state' => 'off' ] ] ]);
	});

	it('makes a map read during the transaction read-only', function () {
		// ★ 09c2908: a map loaded inside a rolled-back transaction must not be committed
		$account = Account::fetch(1);
		$account->name = 'Changed';
		$flags = null;
		try {
			Transaction::run('main', function () use ($account, &$flags) {
				$account->commit();
				$flags = $account->flags;
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expect(function () use ($flags) { $flags['beta'] = 'off'; })->toThrow(Exception::class);
		expect($account->flags['beta'])->toBe('on');
	});

	it('of a delete with cascades restores every deleted object', function () {
		// ★ BUGS-ALREADY-FIXED2 #8; the cascades themselves are in ReferenceCleanupTest
		$users = [ User::fetch(1), User::fetch(2) ];
		try {
			Transaction::run('main', function () use ($users) {
				foreach ($users as $user)
					$user->delete();
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expect(array_map(fn ($user) => [ $user->id, $user->isDeleted() ], $users))->toBe([ [ 1, false ], [ 2, false ] ]);
	});
});

describe('Transaction::begin()', function () {
	it('commits with commit()', function () {
		$transaction = Transaction::begin('main');
		$user = User::fetch(1);
		$user->name = 'Anna';
		$user->commit();
		$transaction->commit();

		expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Anna' ], [ 'id' => 2 ] ] ]);
	});

	it('rolls back with rollBack(), restoring the objects', function () {
		$transaction = Transaction::begin('main');
		$user = new User();
		$user->name = 'Cy';
		$user->commit();
		$transaction->rollBack();

		expectUnchanged();
		expect($user->id)->toBeNull();
	});

	it('can\'t be ended twice', function () {
		$transaction = Transaction::begin('main');
		$transaction->commit();

		expect(fn () => $transaction->commit())->toThrow(Exception::class);
		expect(fn () => $transaction->rollBack())->toThrow(Exception::class);
	});
});

describe('nested transactions', function () {
	it('roll back only their own changes', function () {
		Transaction::run('main', function () {
			$ann = User::fetch(1);
			$ann->name = 'Anna';
			$ann->commit();
			try {
				Transaction::run('main', function () {
					$bob = User::fetch(2);
					$bob->name = 'Bobby';
					$bob->commit();
					throw new RollbackPlease();
				});
			} catch (RollbackPlease) {
			}
		});

		expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Anna' ], [ 'id' => 2, 'name' => 'Bob' ] ] ]);
	});

	it('are rolled back with the outer transaction after committing', function () {
		$bob = User::fetch(2);
		$bob->name = 'Bobby';
		try {
			Transaction::run('main', function () use ($bob) {
				Transaction::run('main', fn () => $bob->commit());
				throw new RollbackPlease();
			});
		} catch (RollbackPlease) {
		}

		expectUnchanged();
		expect($bob->name)->toBe('Bobby'); // pending again
	});

	it('must be ended before the outer one', function () {
		$outer = Transaction::begin('main');
		$inner = Transaction::begin('main');

		expect(fn () => $outer->commit())->toThrow(Exception::class);
		expect(fn () => $outer->rollBack())->toThrow(Exception::class);
		$inner->rollBack();
		$outer->rollBack();
	});
});

describe('a statement that fails', function () {
	it('makes commit() store nothing and throw the PDOException', function () {
		$person = new Person();
		$person->name = 'Ann';
		$person->shoeSize = 40;

		withBrokenExtrasTable(fn () => expect(fn () => $person->commit())->toThrow(PDOException::class));

		expectUnchanged();
		expect($person->id)->toBeNull();
		$person->commit(); // the changes are still pending
		// MySQL and MariaDB don't reuse the id of the rolled-back insert
		$id = $person->id;
		expectState([
			'persons' => [ [ 'person_id' => $id, 'name' => 'Ann' ] ],
			'person_details' => [ [ 'person_id' => $id, 'bio' => null, 'age' => null ] ],
			'person_extras' => [ [ 'person_id' => $id, 'shoe_size' => 40 ] ],
		]);
	});

	it('inside a transaction only rolls back that commit(); the transaction goes on', function () {
		// Not with withBrokenExtrasTable(): DDL would end the transaction on MySQL.
		// A leftover row with the id the new person gets makes its insert fail.
		given([
			'users' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
			'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 1 ] ],
		]);
		$person = new Person();
		$person->name = 'Ann';

		Transaction::run('main', function () use ($person) {
			expect(fn () => $person->commit())->toThrow(PDOException::class);
			$user = User::fetch(1);
			$user->name = 'Anna';
			$user->commit();
		});

		expectState([ 'users' => [ [ 'id' => 1, 'name' => 'Anna' ], [ 'id' => 2 ] ] ]);
		expect($person->id)->toBeNull();
	});

	it('throws even with ERRMODE_SILENT, and the error mode is restored', function () {
		$person = new Person();
		$person->name = 'Ann';
		db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		try {
			withBrokenExtrasTable(fn () => expect(fn () => $person->commit())->toThrow(PDOException::class));
			expect(db()->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_SILENT);
		} finally {
			db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		expectUnchanged();
	});

	it('makes delete() delete nothing and leaves the object as it was', function () {
		given([
			'persons' => [ [ 'person_id' => 1, 'name' => 'Ann' ] ],
			'person_details' => [ [ 'person_id' => 1, 'bio' => 'Bio' ] ],
			'person_extras' => [ [ 'person_id' => 1, 'shoe_size' => 40 ] ],
		]);
		$person = Person::fetch(1);

		withBrokenExtrasTable(fn () => expect(fn () => $person->delete())->toThrow(PDOException::class));

		expectUnchanged();
		expect($person->isDeleted())->toBeFalse();
		expect($person->id)->toBe(1);
	});
});

describe('a transaction started on the PDO object', function () {
	it('makes commit() use a savepoint; rolling it back keeps the object\'s id', function () {
		// Documented: PHersist can't see that transaction end
		db()->beginTransaction();
		$user = new User();
		$user->name = 'Cy';
		$user->commit();
		db()->rollBack();

		expectUnchanged();
		expect($user->id)->toBe(3);
	});
});
