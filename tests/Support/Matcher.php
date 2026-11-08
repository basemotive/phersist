<?php

namespace Tests\Support;

use Closure;
use PHersist\Generator\Schema\ColumnType;

/**
 * A value in an expected state that isn't known exactly, like any() or now().
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Matcher {
	/**
	 * @param string $description what is expected, shown when it doesn't match
	 * @param Closure(mixed, ColumnType): bool $matches checks a normalized value of a column
	 */
	public function __construct(
		public readonly string $description,
		private readonly Closure $matches,
	) {}

	public function matches(mixed $value, ColumnType $type) : bool {
		return ($this->matches)($value, $type);
	}

	/**
	 * Any value but null.
	 */
	public static function any() : Matcher {
		return new Matcher('any()', fn ($value) => $value !== null);
	}

	/**
	 * A date and time during the test (whole seconds), or for a Date column,
	 * the date of a moment during the test. Text columns (TimestampText) are
	 * parsed as a date and time.
	 */
	public static function now() : Matcher {
		return new Matcher('now()', function ($value, ColumnType $type) {
			if (!is_string($value) || ($time = strtotime($value)) === false)
				return false;
			$start = State::testStart();
			if ($type === ColumnType::Date)
				return $value >= date('Y-m-d', $start) && $value <= date('Y-m-d');
			return $time >= $start && $time <= time();
		});
	}

	/**
	 * A value for which the function returns true.
	 *
	 * @param Closure(mixed): bool $fn gets the normalized value
	 */
	public static function that(Closure $fn, string $description = 'that(...)') : Matcher {
		return new Matcher($description, fn ($value) => $fn($value));
	}
}
