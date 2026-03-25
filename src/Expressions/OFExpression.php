<?php

namespace PHersist\Expressions;

/**
 * The abstract OFExpression class.
 *
 * These OFExpression classes are created by the ObjectFinder and provide an
 * intuitive way to build queries to select sets of objects.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
abstract class OFExpression {
	/**
	 * @return array
	 */
	abstract public function evaluate() : array;
}