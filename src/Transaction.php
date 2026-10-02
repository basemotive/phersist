<?php

namespace PHersist;

/**
 * A database transaction that also rolls back the objects in memory.
 *
 * When a transaction is rolled back, every object that was committed or
 * deleted in it gets back the state it had before: its pending changes, its
 * id, and whether it is deleted and in the ObjectCache. commit() and delete()
 * use this themselves as well, so a failed cascading delete doesn't leave
 * objects marked as deleted while their rows are still there.
 *
 * Transactions can be nested. A nested transaction uses a savepoint, so
 * rolling it back only undoes what happened inside it, and the outer
 * transaction can still be committed. The same goes for a transaction started
 * directly on the PDO connection: PHersist can't see that one end, so objects
 * are only restored when a PHersist transaction inside it is rolled back.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class Transaction {
	/** @var ?\WeakMap<\PDO, list<Transaction>> the open transactions per connection, outermost first */
	private static ?\WeakMap $_stacks = null;

	/**
	 * Runs the given function in a transaction on a connection.
	 *
	 * The transaction is committed when the function returns, and rolled back
	 * when it throws, after which the exception is thrown again.
	 *
	 * @template T
	 * @param string $database the identifier of the connection in DBConnectionManager
	 * @param callable(): T $fn the function to run
	 * @return T what the function returns
	 */
	public static function run(string $database, callable $fn) : mixed {
		return self::_run(self::_getPDO($database), $fn);
	}

	/**
	 * Starts a transaction on a connection. End it with commit() or rollBack().
	 *
	 * Transactions must be ended in the reverse order they were started in. If
	 * you can, use run() instead, which takes care of this.
	 *
	 * @param string $database the identifier of the connection in DBConnectionManager
	 * @return Transaction the transaction
	 */
	public static function begin(string $database) : Transaction {
		return self::_begin(self::_getPDO($database));
	}

	/**
	 * Commits this transaction.
	 *
	 * For a nested transaction, the changes become part of the outer
	 * transaction, which still decides whether they are stored. If committing
	 * fails, the transaction is rolled back and the exception is thrown.
	 */
	public function commit() : void {
		$this->_checkActive();

		try {
			if ($this->savepoint === null) {
				self::_call($this->PDO, fn() => $this->PDO->commit());
			} elseif (!self::_isSQLServer($this->PDO)) // SQL Server has no release
				self::_call($this->PDO, fn() => $this->PDO->exec("release savepoint {$this->savepoint}"));
		} catch (\Throwable $e) {
			$this->rollBack();
			throw $e;
		}

		$this->_pop();

		// An outer transaction has to be able to restore these objects as well,
		// unless it already has an older state of them
		$parent = $this->_parent();
		if ($parent !== null)
			foreach ($this->undoLog as $objectId => $entry)
				if (!isset($parent->undoLog[$objectId]))
					$parent->undoLog[$objectId] = $entry;
		$this->undoLog = [];
	}

	/**
	 * Rolls back this transaction, and restores the objects that were committed
	 * or deleted in it to the state they had before.
	 */
	public function rollBack() : void {
		$this->_checkActive();

		try {
			if ($this->savepoint === null) {
				// Some databases end the transaction themselves on certain errors
				if ($this->PDO->inTransaction())
					self::_call($this->PDO, fn() => $this->PDO->rollBack());
			} elseif ($this->PDO->inTransaction()) {
				$statement = self::_isSQLServer($this->PDO) ? 'rollback transaction' : 'rollback to savepoint';
				self::_call($this->PDO, fn() => $this->PDO->exec("$statement {$this->savepoint}"));
			}
		} finally {
			$this->_pop();

			// Restore the most recently recorded objects first
			foreach (array_reverse($this->undoLog) as [$object, $restore])
				$restore();
			$this->undoLog = [];
		}
	}

	/**
	 * Runs the given function in a transaction on a connection.
	 *
	 * @internal
	 * @template T
	 * @param \PDO $PDO the connection
	 * @param callable(): T $fn the function to run
	 * @return T what the function returns
	 */
	public static function _run(\PDO $PDO, callable $fn) : mixed {
		$transaction = self::_begin($PDO);
		try {
			$result = $fn();
		} catch (\Throwable $e) {
			$transaction->rollBack();
			throw $e;
		}
		$transaction->commit();
		return $result;
	}

	/**
	 * Records the state of an object, so the innermost transaction on the
	 * connection can restore it when it is rolled back. Only the first state
	 * recorded in a transaction is kept.
	 *
	 * @internal
	 * @param \PDO $PDO the connection
	 * @param object $object the object that is about to change
	 * @param callable(): (callable(): void) $snapshot creates a function that
	 *   restores the current state of the object
	 */
	public static function _record(\PDO $PDO, object $object, callable $snapshot) : void {
		$stack = self::$_stacks[$PDO] ?? [];
		if (count($stack) == 0)
			return;

		$transaction = $stack[count($stack) - 1];
		$objectId = spl_object_id($object);
		// The entry keeps the object alive, so its id can't be reused by another object
		if (!isset($transaction->undoLog[$objectId]))
			$transaction->undoLog[$objectId] = [$object, $snapshot()];
	}

	/**
	 * Starts a transaction, or a savepoint if a transaction is active already.
	 *
	 * @param \PDO $PDO the connection
	 * @return Transaction the transaction
	 */
	private static function _begin(\PDO $PDO) : Transaction {
		self::$_stacks ??= new \WeakMap();
		$stack = self::$_stacks[$PDO] ?? [];

		if ($PDO->inTransaction()) {
			// Nested in one of ours or in one the application started on the PDO
			$savepoint = 'phersist_'.count($stack);
			$statement = self::_isSQLServer($PDO) ? 'save transaction' : 'savepoint';
			self::_call($PDO, fn() => $PDO->exec("$statement $savepoint"));
		} else {
			// Left behind by transactions ended directly on the PDO
			$stack = [];
			$savepoint = null;
			self::_call($PDO, fn() => $PDO->beginTransaction());
		}

		$transaction = new Transaction($PDO, $savepoint);
		$stack[] = $transaction;
		self::$_stacks[$PDO] = $stack;
		return $transaction;
	}

	/**
	 * @param \PDO $PDO the connection
	 * @param ?string $savepoint the name of the savepoint, or null for a real transaction
	 */
	private function __construct(\PDO $PDO, ?string $savepoint) {
		$this->PDO = $PDO;
		$this->savepoint = $savepoint;
	}

	/**
	 * Checks that this is the innermost active transaction on its connection.
	 */
	private function _checkActive() : void {
		$stack = self::$_stacks[$this->PDO] ?? [];
		if (!in_array($this, $stack, true))
			throw new \Exception('Transaction has already ended');
		if ($stack[count($stack) - 1] !== $this)
			throw new \Exception('Cannot end a transaction while a transaction inside it is still active');
	}

	/**
	 * Removes this transaction from the stack of its connection.
	 */
	private function _pop() : void {
		$stack = self::$_stacks[$this->PDO];
		array_pop($stack);
		self::$_stacks[$this->PDO] = $stack;
	}

	/**
	 * @return ?Transaction the transaction this one is nested in, if it's ours
	 */
	private function _parent() : ?Transaction {
		$stack = self::$_stacks[$this->PDO] ?? [];
		return count($stack) > 0 ? $stack[count($stack) - 1] : null;
	}

	/**
	 * Calls a function on the connection, making sure a failure throws.
	 *
	 * @param \PDO $PDO the connection
	 * @param callable(): mixed $fn the function
	 */
	private static function _call(\PDO $PDO, callable $fn) : void {
		$errorMode = $PDO->getAttribute(\PDO::ATTR_ERRMODE);
		$PDO->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		try {
			if ($fn() === false)
				throw new \PDOException('Transaction statement failed');
		} finally {
			$PDO->setAttribute(\PDO::ATTR_ERRMODE, $errorMode);
		}
	}

	/**
	 * @param \PDO $PDO the connection
	 * @return bool if the connection is to SQL Server, which has its own savepoint syntax
	 */
	private static function _isSQLServer(\PDO $PDO) : bool {
		return in_array($PDO->getAttribute(\PDO::ATTR_DRIVER_NAME), ['sqlsrv', 'dblib']);
	}

	/**
	 * @param string $database the identifier of the connection
	 * @return \PDO the connection
	 */
	private static function _getPDO(string $database) : \PDO {
		$PDO = DB\DBConnectionManager::getPDO($database);
		if ($PDO === null)
			throw new \Exception("No database '$database'");
		return $PDO;
	}

	/** The connection */
	private \PDO $PDO;

	/** The name of the savepoint, or null if this is the outermost transaction */
	private ?string $savepoint;

	/** @var array<int, array{object, callable(): void}> the objects to restore on rollback, by spl_object_id() */
	private array $undoLog = [];
}
