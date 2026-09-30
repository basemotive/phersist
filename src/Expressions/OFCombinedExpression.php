<?php

namespace PHersist\Expressions;

use PHersist\ActiveRecord;
use PHersist\ObjectFinder;

/**
 * An expression that may contain sub-expressions using 'and' or 'or'.

/**
 * Defines an expression that can contain sub-expressions and chains them
 * together using AND or OR.
 *
 * These OFExpression classes are created by the ObjectFinder and provide an
 * intuitive way to build queries to select sets of objects.
 *
 * @template T of ActiveRecord
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class OFCombinedExpression extends OFExpression {
	/**
	 * @param ObjectFinder<T> $of
	 * @param ?OFCombinedExpression<T> $parent
	 */
	public function __construct(string $operator, ObjectFinder $of, ?OFCombinedExpression $parent = null) {
		$this->operator = $operator;
		$this->of = $of;
		$this->parent = $parent;
	}

	/**
	 * @return OFCombinedExpression<T>
	 */
	public function addAnd() : OFCombinedExpression {
		return $this->items[] = new OFCombinedExpression('and', $this->of, $this);
	}

	/**
	 * @return OFCombinedExpression<T>
	 */
	public function addOr() : OFCombinedExpression {
		return $this->items[] = new OFCombinedExpression('or', $this->of, $this);
	}

	/**
	 * @return OFCombinedExpression<T>
	 */
	public function where(string $property, string $operator, mixed $value) : OFCombinedExpression {
		$this->items[] = new OFWhereExpression($property, $operator, $value, $this->of);
		return $this;
	}

	/**
	 * @return OFCombinedExpression<T>
	 */
	public function end() : OFCombinedExpression {
		return $this->parent ?? $this;
	}

	/**
	 * @return array{string, array<string, mixed>}
	 */
	public function evaluate() : array {
		$parts = [];
		$values = [];
		foreach ($this->items as $item) {
			list($subParts, $subValues) = $item->evaluate();
			if ($subParts === '') continue; // empty group
			$parts[] = $subParts;
			$values = array_merge($values, $subValues);
		}
		$result = implode(" {$this->operator} ", $parts); // operator is AND/OR
		if (count($parts)>1) $result = "($result)";
		return [ $result, $values ];
	}

	/**
	 * Calls the ObjectFinder's orderBy function.
	 *
	 * This exists for convenience in method chaining.
	 *
	 * @return ObjectFinder<T>
	 */
	public function orderBy(string $propname, string $direction = ObjectFinder::DIRECTION_ASC) : ObjectFinder {
		return $this->of->orderBy($propname, $direction);
	}

	/**
	 * Calls the ObjectFinder's fetch function.
	 *
	 * This exists for convenience in method chaining.
	 *
	 * @return list<T>
	 */
	public function fetch(?int $limit = null, int $offset = 0) : array {
		return $this->of->fetch($limit, $offset);
	}

	/**
	 * Calls the ObjectFinder's fetchOne function.
	 *
	 * This exists for convenience in method chaining.
	 *
	 * @return ?T
	 */
	public function fetchOne() : ?object {
		return $this->of->fetchOne();
	}

	/**
	 * Calls the ObjectFinder's count function.
	 *
	 * This exists for convenience in method chaining.
	 */
	public function count() : int {
		return $this->of->count();
	}

	private string $operator;
	/** @var ObjectFinder<T> */
	private ObjectFinder $of;
	/** @var ?OFCombinedExpression<T> */
	private ?OFCombinedExpression $parent;
	/** @var list<OFExpression> */
	private array $items = [];
}