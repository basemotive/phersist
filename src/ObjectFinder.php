<?php

namespace PHersist;

/**
 * A tool for finding and retrieving sets of ActiveRecord objects from the
 * database.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 *
 * TODO Improve and formalize method chaining
 */
class ObjectFinder {
	const string DIRECTION_ASC = 'asc';
	const string DIRECTION_DESC = 'desc';

	public function __construct(string $className, bool $full = false) {
		$this->className = $className;
		$this->full = $full;

		// Get the database from the pool
		$meta = ActiveRecord::_getMeta($className);
		$this->PDO = DB\DBConnectionManager::getPDO($meta['database'])
			or $this->error("No database '".$meta['database']."'");

		//$c = new $className();
		$this->rootExpression = new OFCombinedExpression('and', $this);
	}

	public function count() : int {
		list($where, $queryValues) = $this->rootExpression->evaluate();

		$meta = ActiveRecord::_getMeta($this->className);
		$baseTable = $meta['table']; // The base table for the related type
		$idField = $meta['id']; // The id field for the related type

		// Don't count/restore objects that have been softdeleted
		if ($meta['softdelete']) {
			if (trim($where) != '') $where .= ' and ';
			$where .= "`$baseTable`.`deleted` = '0'";
		}

		// Now we build the basic query
		$query = "select count(`$baseTable`.`$idField`) as itemcount\n";
		$query .= " from `$baseTable`\n";
		foreach ($this->tables as $table) {
			$joinOn = $table['join_on'];
			$joinOn = str_replace('`{$source_table}`', "`{$table['source_table']}`", $joinOn);
			$joinOn = str_replace('`{$target_table}`', "`{$table['table_alias']}`", $joinOn);
			$query .= " left join `{$table['table_name']}` as `{$table['table_alias']}`\n";
			$query .= "   on $joinOn\n";
		}
		if ($where != '') $query .= " where $where\n";
		if (count($this->tables)>0)
			$query .= " group by `$baseTable`.`$idField`\n";

		$stmt = $this->PDO->prepare($query);
		foreach ($queryValues as $key => $value)
			$stmt->bindValue(':'.$key, $value, \PDO::PARAM_STR);
		$stmt->execute();
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
			return (int) $row['itemcount'];
		else
			return 0;
	}

	/**
	 * @return array
	 */
	public function fetch(mixed $limit='') : array {
		list($where, $queryValues) = $this->rootExpression->evaluate();

		/*
		echo "WHERE: $where\n";
		echo "VALUES:";
		foreach ($queryValues as $key => $value) {
			echo " $key='$value'";
		}
		echo "\n";
		*/

		$meta = ActiveRecord::_getMeta($this->className);
		$baseTable = $meta['table']; // The base table for the related type
		$idField = $meta['id']; // The id field for the related type

		// Don't count/restore objects that have been softdeleted
		if ($meta['softdelete']) {
			if (trim($where) != '') $where .= ' and ';
			$where .= "`$baseTable`.`deleted` = '0'";
		}

		// If we're constructing full objects, we load the data for the autoload
		// datasets here, so we can populate the new objects with it.
		$extraFields='';
		if ($this->full) {
			foreach ($meta['datasets'] as $dataset) if ($dataset['autoload']) {
				$extraFieldList = [];
				foreach ($dataset['props'] as $prop)
					$extraFieldList = array_merge($extraFieldList, $prop['fieldnames']);
				foreach (array_unique($extraFieldList) as $extraField)
					$extraFields .= ", `$baseTable`.`$extraField`";
				break;
			}
		}

		// Now we build the basic query
		$query = "select `$baseTable`.`$idField`$extraFields\n";
		$query .= " from `$baseTable`\n";
		foreach ($this->tables as $table) {
			$joinOn = $table['join_on'];
			$joinOn = str_replace('`{$source_table}`', "`{$table['source_table']}`", $joinOn);
			$joinOn = str_replace('`{$target_table}`', "`{$table['table_alias']}`", $joinOn);
			$query .= " left join `{$table['table_name']}` as `{$table['table_alias']}`\n";
			$query .= "   on $joinOn\n";
		}
		if ($where != '') $query .= " where $where\n";
		if (count($this->tables)>0)
			$query .= " group by `$baseTable`.`$idField`\n";

		// Sorting
		// TODO This is not optimal because it now only works on properties from the
		// base table, and not properties/values we imported from other tables
		// We should dereference the tables here and prepend the mapped table names
		if (count($this->orderBys)>0) {
			$orderBysTranslated = [];
			foreach ($this->orderBys as $orderBy) {

				$property = $orderBy['property'];
				$direction = $orderBy['direction'];

				$meta = ActiveRecord::_getMeta($this->className);
				$prop = null;
				foreach ($meta['datasets'] as $dataset)
					if (isset($dataset['props'][$property])) {
						$prop = $dataset['props'][$property];
						break;
					}

				if ($prop == null)
					$this->error("Trying to evaluate for nonexistent property $property on class {$this->className}");

				$type = self::_getPropertyType($prop['type']);
				$values = $type->toDBSearch($prop, '');
				foreach ($values as $fieldname => $value)
					$orderBysTranslated[] = "`{$baseTable}`.`{$fieldname}` {$direction}";
			}
			$query .= " order by ".implode(',', $orderBysTranslated)."\n";
		} else {
			// we do this for MSSQL because using OFFSET x ROWS FETCH NEXT y ROWS ONLY
			// isn't accepted unless there is a unique order by
			// (though i haven't tested what custom order stuff does)
			$query .= " order by `{$idField}`\n";
		}

		// Limit
		if ($limit !== '') {
			$query .= " limit $limit\n";
		}

		$objects = [];

		// The final step is fetching the data and constructing objects from it
		$stmt = $this->PDO->prepare($query);
		foreach ($queryValues as $key => $value)
			$stmt->bindValue(':'.$key, $value, \PDO::PARAM_STR);
		$stmt->execute();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$id = $row[$idField];
			$cn = $this->className;
			$objects[] = new $cn($id, $this->full?$row:null);
		}

		return $objects;
	}

	/**
	 * Fetch just one item.
	 *
	 * The return type is ?object instead of ?ActiveRecord because that prevents
	 * PHPStan from finding fault when accessing named properties on the result.
	 */
	public function fetchOne() : ?object {
		$objects = $this->fetch(1);
		return empty($objects) ? null : $objects[0];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function addContext(string $context) : array {
		//echo "Add context: [$context]\n";
		static $counter = 0;
		$contextList = explode('->', $context);

		$currentClassName = $this->className;
		$currentContext = '';
		$lastContext = '';

		if ($context == '') {
			$meta = ActiveRecord::_getMeta($this->className);
			return [
				'table_alias' => $meta['table'],
				'class_name' => $this->className,
			];
		}

		for ($i=0; $i<count($contextList); $i++) {
			$last = $i==count($contextList)-1;
			$propertyName = $contextList[$i];

			$lastContext = $currentContext;
			if ($currentContext != '') $currentContext .= '->';
			$currentContext .= $propertyName;

			if (isset($this->tables[$currentContext])) {
				//echo "Already dereferenced: $currentClassName::$propertyName\n";
				$currentClassName = $this->tables[$currentContext]['class_name'];
				continue;
			}

			//echo "Dereferencing: $currentClassName::$propertyName\n";

			// Find the property information from the metadata
			$meta = ActiveRecord::_getMeta($currentClassName);
			$prop = null;
			foreach ($meta['datasets'] as $dataset)
				if (isset($dataset['props'][$propertyName]))
					$prop = $dataset['props'][$propertyName];
			if ($prop == null)
				$this->error("Class $currentClassName has no property $propertyName");

			// Get the dereferencing info from the ORPropertyType instance
			$type = $this->_getPropertyType($prop['type']);
			if ($derefData = $type->dereference($prop, $meta['table'])) {
				$counter++;

				$sourceTable = $lastContext=='' ? $meta['table'] : $this->tables[$lastContext]['table_alias'];
				$tableName = $derefData['target_table'];
				$tableAlias = "rel{$counter}_{$derefData['target_table']}";
				$joinOn = $derefData['on'];
				$currentClassName = $derefData['class_name'];

				$this->tables[$currentContext] = [
					'source_table' => $sourceTable,
					'table_name' => $tableName,
					'table_alias' => $tableAlias,
					'join_on' => $joinOn,
					'class_name' => $currentClassName,
				];
			} else {
				$this->error("Cannot dereference $currentClassName::$propertyName");
			}

		}
		return $this->tables[$context];
	}

	/**
	 * Returns an ARPropertyType object for the requested type. This method
	 * caches them, so that only one instance of each type is created every time.
	 */
	private static function _getPropertyType(string $type) : Types\ARPropertyType {
		static $propertyTypes = [];
		$fullType = __NAMESPACE__."\\Types\\ARPropertyType$type";
		if (!isset($propertyTypes[$fullType]))
			$propertyTypes[$fullType] = new $fullType();
		return $propertyTypes[$fullType];
	}

	protected $tables = [];

	public function where(string $property, string $operator, mixed $value) : OFCombinedExpression {
		return $this->rootExpression->where($property, $operator, $value);
	}

	public function addAnd() : OFCombinedExpression { return $this->rootExpression->addAnd(); }
	public function addOr() : OFCombinedExpression { return $this->rootExpression->addOr(); }

	/**
	 * Check if $className or the current set class has the property.
	 *
	 * @param string $prop the property name
	 * @param ?string $className the class to check for the property
	 * @return bool if the property exists
	 */
	public function hasProperty(string $prop, ?string $className = null) : bool {
		if ($prop == 'id') return true;

		if ($className == null)
			$className = $this->className;

		$meta = ActiveRecord::_getMeta($className);
		foreach ($meta['datasets'] as $dataset)
			if (isset($dataset['props'][$prop]))
				return true;

		return false;
	}

	/**
	 * Throws an exception when an unrecoverable erorr has occurred.
	 */
	public function error(string $message) : void {
		throw new \Exception("ObjectFinder({$this->className}): $message");
	}

	public function getClassName() : string {
		return $this->className;
	}

	public function getPDO() : ?\PDO {
		return $this->PDO;
	}

	public function generateValueName() : string {
		static $counter = 1;
		return 'field'.$counter++;
	}

	public function orderBy(string $propname, string $direction = ObjectFinder::DIRECTION_ASC) : ObjectFinder {
		if (!$this->hasProperty($propname))
			$this->error("Does not have property $propname");

		$this->orderBys[] = [
			'property' => $propname,
			'direction' => $direction,
		];
		return $this;
	}

	// the name of the class we want to fetch objects for
	protected ?string $className = null;
	// if we want the full set of properties to be loaded immediately
	protected bool $full = false;
	protected ?\PDO $PDO = null;
	protected ?OFCombinedExpression $rootExpression = null;
	protected array $orderBys = [];
}

abstract class OFExpression {
	/**
	 * @return array
	 */
	abstract public function evaluate() : array;
}

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

class OFWhereExpression extends OFExpression {
	protected $allowedOperators = [ '=', 'is', '>', '<', '>=', '<=', '!=', 'LIKE', 'NOT LIKE' ];

	public function __construct(string $property, string $operator, mixed $value, ObjectFinder $of) {
		//if (!$of->hasProperty($property))
			//$of->error("Object does not have property $property");
		if (!in_array($operator, $this->allowedOperators))
			$of->error("Operator '{$operator}' unknown");

		$this->property = $property;
		$this->operator = $operator;
		$this->value = $value;
		$this->of = $of;
	}

	/**
	 * @return array
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
				$tableAlias = $contextData['table_alias'];

				$meta = ActiveRecord::_getMeta($className);
				$prop = null;
				foreach ($meta['datasets'] as $dataset)
					if (isset($dataset['props'][$property])) {
						$prop = $dataset['props'][$property];
						break;
					}

				if ($prop == null)
					$this->of->error("Trying to evaluate for nonexistent property $property on class $className");

				$type = self::_getPropertyType($prop['type']);
				$values = $type->toDBSearch($prop, $this->value);

				$parts = [];
				foreach ($values as $fieldname => $value) {
					// TODO check if the 'is null' type operation will always work
					if ($prop['type'] == 'Class' && ($this->operator == 'is' || $this->operator == '=') && $value === null)
						$parts[] = "`{$tableAlias}`.`{$fieldname}` is null"; // Special 'is null' operation
					elseif ($prop['type'] == 'Class' && ($this->operator == 'not is' || $this->operator == '!=') && $value === null)
						$parts[] = "not `{$tableAlias}`.`{$fieldname}` is null"; // Special 'is null' operation
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
	private static function _getPropertyType(string $type) : Types\ARPropertyType {
		static $propertyTypes = [];
		$fullType = __NAMESPACE__."\\Types\\ARPropertyType$type";
		if (!isset($propertyTypes[$fullType]))
			$propertyTypes[$fullType] = new $fullType();
		return $propertyTypes[$fullType];
	}

	private string $property;
	private string $operator;
	private mixed $value;
	private ObjectFinder $of;
}

// We need some way to tell the OF to join more tables from an expression (because we may need those
// to do our checks). On every join, a new unique alias should be used which would then be sent back
// to the OFE so it can be used for the where clause later.
// Also, it should be noted that some grouping has to be done later, especially when using ?-N relations.

/*

	select
		FIELDLIST
	from
		BASETABLE

	-- where('relationX', 'has', $someObject)
	-- 1-n relation
	inner join
		RELATIONX_TABLE as reltbl1 on BASETABLE.ID=reltbl1.LOCAL_ID and reltbl1.REMOTE_ID={$someObject->id}
		and reltbl1.deleted = '0' -- only for softdelete

	-- where('relationY', 'has', $someObject)
	-- n-n relation
	inner join
		RELATIONY_TABLE as reltbl2 on BASETABLE.ID=reltbl2.LOCAL_ID and reltbl1.REMOTE_ID={$someObject->id}
	inner join
		REL_OBJ_TABLE as reltbl3 on reltbl2.REMOTE_ID.reltbl3.ID
		and reltbl3.deleted = '0' -- only for softdelete

	-- where('propObject1->name', '=', 'Ben')
	-- property on a property object
	-- Note: BASETABLE can be any table that contains the requested property (see the datasets)
	inner join
		PROP_OBJ_TABLE as reltbl4 on BASETABLE.property_id = reltbl4.ID
			and reltbl4.name = 'Ben'

	-- where('propObject1->propObject2->name', '=', 'Fiets')
	-- iterate multiple properties
	inner join
		PROP1_OBJ_TABLE as reltbl5 on BASETABLE.property_id = reltbl5.ID
	inner join
		PROP2_OBJ_TABLE as reltbl6 on reltbl5.property_id = reltbl6.ID
			and reltbl6.name = 'Fiets'




















*/