<?php

namespace PHersist;

use PHersist\Expressions\OFCombinedExpression;

/**
 * A tool for finding and retrieving sets of ActiveRecord objects from the
 * database.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ObjectFinder {
	/** @var string for ascending order */
	const string DIRECTION_ASC = 'asc';
	/** @var string for descending order */
	const string DIRECTION_DESC = 'desc';

	/**
	 * Creates a new ObjectFinder instance.
	 *
	 * @see ObjectFinder::create() for a more convenient way to create
	 *   ObjectFinder instances if you want to use method chaining
	 *
	 * @param string $className the name of the class to find
	 * @param bool $full if you want to retrieve the full records
	 */
	public function __construct(string $className, bool $full = false) {
		$this->className = $className;
		$this->full = $full;

		// Get the database from the pool
		$meta = ActiveRecord::_getMeta($className);
		$this->PDO = DB\DBConnectionManager::getPDO($meta['database'])
			or $this->error("No database '".$meta['database']."'");

		$this->rootExpression = new OFCombinedExpression('and', $this);
	}

	/**
	 * Creates a new ObjectFinder instance and returns it.
	 *
	 * Recommended over the constructor if you want to use method chaining.
	 *
	 * @param string $className the name of the class to find
	 * @param bool $full if you want to retrieve the full records
	 * @return ObjectFinder a new ObjectFinder instance
	 */
	public static function create(string $className, bool $full = false) : ObjectFinder {
		return new ObjectFinder($className, $full);
	}

	// ---------------------------------------------------------------------------
	// Building a query
	// ---------------------------------------------------------------------------

	public function where(string $property, string $operator, mixed $value) : OFCombinedExpression {
		return $this->rootExpression->where($property, $operator, $value);
	}

	public function addAnd() : OFCombinedExpression {
		return $this->rootExpression->addAnd();
	}

	public function addOr() : OFCombinedExpression {
		return $this->rootExpression->addOr();
	}

	/**
	 * Indicates results should be ordered by a certain property.
	 *
	 * This function may be called multiple times for different properties, where
	 * subsequent calls have a lower priority for ordering.
	 *
	 * @see ObjectFinder::DIRECTION_ASC
	 * @see ObjectFinder::DIRECTION_DESC
	 *
	 * @param string $propname the name of the property to order by
	 * @param string $direction the direction to order in, either
	 *   ObjectFinder::DIRECTION_ASC or ObjectFinder::DIRECTION_DESC
	 * @return ObjectFinder this instance, so methods may be chained
	 */
	public function orderBy(string $propname, string $direction = ObjectFinder::DIRECTION_ASC) : ObjectFinder {
		if (!$this->hasProperty($propname))
			$this->error("Does not have property $propname");

		$this->orderBys[] = [
			'property' => $propname,
			'direction' => $direction,
		];
		return $this;
	}

	// ---------------------------------------------------------------------------
	// Getting results
	// ---------------------------------------------------------------------------

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
			// we use the fetchObject method instead of the constructor so the
			// ActiveRecord can handle the caching
			$objects[] = ActiveRecord::fetchObject(
				$this->className,
				$row[$idField],
				$this->full ? $row : null
			);
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

	// ---------------------------------------------------------------------------
	// Internal stuff
	// ---------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 * @internal
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

			// We're trying to dereference $currentClassName::$propertyName

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

	/**
	 * Generates a unique value name for code that generates partial SQL queries.
	 *
	 * If the code generates something like where `tablename`.`fieldname` =
	 * :valueName the valueName needs to be unique, so it's generated here.
	 *
	 * @see OFWhereExpression::evaluate() where it is used
	 * @internal only used by the OFWhereExpression class
	 *
	 * @return string a unique name for a value name for an SQL query
	 */
	public function generateValueName() : string {
		static $counter = 1;
		return 'field'.$counter++;
	}

	/**
	 * @internal
	 */
	public function getClassName() : string {
        return $this->className;
    }

	/**
	 * @var array tables we need for the query
	 * @see ObjectFinder::addContext() where it is populated
	 * @see ObjectFinder::count() where it is used
	 * @see ObjectFinder::fetch() where it is used
	 */
	protected $tables = [];

	/** @var string the name of the class we want to fetch objects for */
	protected ?string $className = null;

	/** @var bool if we want the full set of properties to be retrieved */
	protected bool $full = false;

	/** @var ?\PDO the database connection */
	protected ?\PDO $PDO = null;

	/** @var ?OFCombinedExpression the root of the current expression */
	protected ?OFCombinedExpression $rootExpression = null;

	/** @var array which properties to order by and in wich direction */
	protected array $orderBys = [];
}