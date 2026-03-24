<?php

namespace PHersist\Expressions;

abstract class OFExpression {
	/**
	 * @return array
	 */
	abstract public function evaluate() : array;
}