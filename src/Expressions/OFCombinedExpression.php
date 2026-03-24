<?php

namespace PHersist\Expressions;

use PHersist\ObjectFinder;
use PHersist\ActiveRecord;

/**
 * An expression that may contain sub-expressions using 'and' or 'or'.
 */
class OFCombinedExpression extends OFExpression {
	public function __construct(string $operator, ObjectFinder $of) {
		$this->operator = $operator;
		$this->of = $of;
	}

	public function addAnd() : OFCombinedExpression { return $this->items[] = new OFCombinedExpression('and', $this->of); }
	public function addOr() : OFCombinedExpression { return $this->items[] = new OFCombinedExpression('or', $this->of); }

	public function where(string $property, string $operator, mixed $value) : OFCombinedExpression {
		$this->items[] = new OFWhereExpression($property, $operator, $value, $this->of);
		return $this;
	}

	/**
	 * @return array
	 */
	public function evaluate() : array {
		$parts = [];
		$values = [];
		foreach ($this->items as $item) {
			list($subParts, $subValues) = $item->evaluate();
			$parts[] = $subParts;
			$values = array_merge($values, $subValues);
		}
		$result = implode(" {$this->operator} ", $parts); // operator is AND/OR
		if (count($parts)>1) $result = "($result)";
		return [ $result, $values ];
	}

	// These two are for chaining
	public function orderBy(string $x, string $y = ObjectFinder::DIRECTION_ASC) : ObjectFinder { return $this->of->orderBy($x, $y); }
	/**
	 * @return array<int,ActiveRecord>
	 */
	public function fetch(mixed $limit=0) : array { return $this->of->fetch($limit=0); }

	private string $operator;
	private ObjectFinder $of;
	private $items = [];
}