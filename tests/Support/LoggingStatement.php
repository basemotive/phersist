<?php

namespace Tests\Support;

/**
 * The statement class of the test connection: tells QueryLog about every
 * executed statement.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class LoggingStatement extends \PDOStatement {
	protected function __construct() {}

	/**
	 * @param ?array<int|string, mixed> $params
	 */
	public function execute(?array $params = null) : bool {
		QueryLog::add($this->queryString);
		return parent::execute($params);
	}
}
