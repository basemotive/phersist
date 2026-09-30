<?php

namespace PHersist\Expressions;

use PHersist\ObjectFinder;
use PHersist\ActiveRecord;
use PHersist\Types\ARPropertyType;

/**
 * Defines an expression that checks an object's property value against a fixed
 * value.
 *
 * These OFExpression classes are created by the ObjectFinder and provide an
 * intuitive way to build queries to select sets of objects.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class OFWhereExpression extends OFExpression {
	/** @var list<string> */
	protected array $allowedOperators = [ '=', 'IS', '>', '<', '>=', '<=', '!=', 'LIKE', 'NOT LIKE' ];

	/**
	 * @param ObjectFinder<*> $of
	 * @internal
	 */
	public function __construct(string $property, string $operator, mixed $value, ObjectFinder $of) {
		// this check is disabled for now because hasProperty cannot handle
		// properties that need to be dereferenced like otherObject->prop
		//if (!$of->hasProperty($property))
			//$of->error("Object does not have property $property");
		if (!in_array(strtoupper($operator), $this->allowedOperators))
			$of->error("Operator '{$operator}' unknown");
		// TODO check if operator can work with the property's type
		// TODO if the property is required, checking for NULL is nonsense

		$this->property = $property;
		$this->operator = $operator;
		$this->value = $value;
		$this->of = $of;
	}

	/**
	 * @internal
	 * @return array{string, array<string, mixed>}
	 */
	public function evaluate() : array {
		$context = '';
		$className = $this->of->getClassName();

		$propertyList = explode('->', $this->property);

		$lastContext = '';

		$result = '';
		$resultValues = [];

		for ($i=0; $i<count($propertyList); $i++) {
			$last = $i==count($propertyList)-1; // Check if this is the last item
			$property = $propertyList[$i];

			$lastContext = $context;
			if ($context!='') $context .= '->';
			$context .= $property;

			if ($last) {
				$contextData = $this->of->addContext($lastContext);
				$className = $contextData['class_name'];
				// The property may live in a dataset table, which is then joined
				$tableAlias = $this->of->addDatasetTable($lastContext, $property);

				$meta = ActiveRecord::_getMeta($className);
				$prop = null;
				foreach ($meta['datasets'] as $dataset)
					if (isset($dataset['props'][$property])) {
						$prop = $dataset['props'][$property];
						break;
					}

				// The id is not part of any dataset, so it maps to the id field directly
				$values = [ $meta['id'] => $this->value ];
				if ($prop != null) {
					$type = self::_getPropertyType($prop['type']);
					$values = $type->toDBSearch($prop, $this->value);
				} elseif ($property != 'id')
					$this->of->error("Trying to evaluate for nonexistent property $property on class $className");

				$parts = [];
				foreach ($values as $fieldname => $value) {
					// Comparing with null using = or != never matches in SQL, so use 'is null'
					$operator = strtoupper($this->operator);
					if ($value === null && ($operator == 'IS' || $operator == '='))
						$parts[] = "`{$tableAlias}`.`{$fieldname}` is null";
					elseif ($value === null && $operator == '!=')
						$parts[] = "not `{$tableAlias}`.`{$fieldname}` is null";
					else {
						$valueName = $this->of->generateValueName();
						$parts[] = "`{$tableAlias}`.`{$fieldname}` {$this->operator} :{$valueName}";
						$resultValues[$valueName] = $value;
					}
				}

				$result = implode(' and ', $parts);
				if (count($parts) > 1) $result = "($result)";
			}
		}
		return [ $result, $resultValues ];
	}

	/**
	 * Returns an ARPropertyType object for the requested type. This method
	 * caches them, so that only one instance of each type is created every time.
	 */
	private static function _getPropertyType(string $type) : ARPropertyType {
		static $propertyTypes = [];
		$fullType = "\\PHersist\\Types\\ARPropertyType{$type}";
		if (!isset($propertyTypes[$fullType]))
			$propertyTypes[$fullType] = new $fullType();
		return $propertyTypes[$fullType];
	}

	private string $property;
	private string $operator;
	private mixed $value;
	/** @var ObjectFinder<*> */
	private ObjectFinder $of;
}