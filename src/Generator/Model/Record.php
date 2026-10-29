<?php

namespace PHersist\Generator\Model;

/**
 * A class in the model XML: the <class> element, which becomes an
 * ActiveRecord class. It's not called Class, because that's a reserved word.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Record {
	/**
	 * @param Project $project the project the class is in
	 * @param string $name the class name, without namespace
	 * @param string $idColumn the id column, in each of its tables
	 * @param string $table the base table, which hands out the ids
	 * @param string $database the database (connection id)
	 * @param bool $softdelete whether deleting sets the 'deleted' column instead of deleting the rows
	 * @param ?string $trait the trait from the trait attribute, as written; null if not set
	 * @param list<Dataset> $datasets
	 * @param list<Relation> $relations
	 * @param list<Map> $maps
	 * @param list<Index> $indexes the indexes from the XML; SchemaBuilder adds the automatic ones
	 * @param int $line the line of the element in the XML
	 */
	public function __construct(
		public readonly Project $project,
		public readonly string $name,
		public readonly string $idColumn,
		public readonly string $table,
		public readonly string $database,
		public readonly bool $softdelete,
		public readonly ?string $trait,
		public readonly array $datasets,
		public readonly array $relations,
		public readonly array $maps,
		public readonly array $indexes,
		public readonly int $line,
	) {}

	/**
	 * Returns the class name with namespace, without leading backslash.
	 */
	public function qualifiedName() : string {
		return $this->project->qualifyClass($this->name);
	}

	/**
	 * Returns the tables of the class: its base table, then those of its datasets.
	 *
	 * @return non-empty-list<string> the table names
	 */
	public function tables() : array {
		$tables = [$this->table];
		foreach ($this->datasets as $dataset)
			$tables[] = $dataset->table;
		return array_values(array_unique($tables));
	}

	/**
	 * Returns the properties of all datasets.
	 *
	 * @return list<Property>
	 */
	public function properties() : array {
		$properties = [];
		foreach ($this->datasets as $dataset)
			array_push($properties, ...$dataset->properties);
		return $properties;
	}

	/**
	 * Finds the property that stores a column of one of the class's tables.
	 *
	 * @param string $table the table, one of the class's tables
	 * @param string $column the column name, compared case-insensitively
	 * @return ?Property the property, or null if no property has the column
	 */
	public function findColumnProperty(string $table, string $column) : ?Property {
		foreach ($this->datasets as $dataset) {
			if ($dataset->table != $table)
				continue;
			foreach ($dataset->properties as $property)
				if (in_array(strtolower($column), array_map('strtolower', $property->columns)))
					return $property;
		}
		return null;
	}
}
