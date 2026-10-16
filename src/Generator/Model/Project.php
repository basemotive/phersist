<?php

namespace PHersist\Generator\Model;

use PHersist\Generator\XMLLoader;

/**
 * A model XML: the <project> element, with the classes in it.
 *
 * Like all model objects, it holds the values from the XML with their
 * defaults resolved and names translated through the table style, and they
 * have been validated. XMLLoader builds it.
 *
 * A model XML can depend on others (<depends>), like a plugin on the core.
 * Their classes are context: the classes of this one can refer to them, and
 * the checks see them, but only this one's classes are generated.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Project {
	/**
	 * @param string $namespace the namespace of the classes, without leading or trailing backslash; '' for none
	 * @param string $database the database (connection id) of the classes, unless a class has its own
	 * @param string $tableStyle the table style, like 'SnakeCase' for TSSnakeCase
	 * @param bool $longIds whether id columns are named after the class (id_style="long") instead of 'id'
	 * @param array<string, array<string, string>> $schemaSettings the attributes of the <schema> elements, by dialect
	 * @param string $file the path of the model XML
	 * @param list<Project> $dependencies the model XMLs this one depends on
	 * @throws \Exception if there's no converter class for the table style
	 */
	public function __construct(
		public readonly string $namespace,
		public readonly string $database,
		public readonly string $tableStyle,
		public readonly bool $longIds,
		private readonly array $schemaSettings,
		public readonly string $file,
		public readonly array $dependencies = [],
	) {
		$converter = 'PHersist\\Generator\\TS'.$tableStyle;
		if (!class_exists($converter))
			throw new \Exception("Cannot find table style converter class {$converter}");
		$this->converter = $converter;
	}

	/**
	 * Adds a class to the project; for XMLLoader, while building the model.
	 *
	 * @internal
	 */
	public function addRecord(Record $record) : void {
		$this->records[] = $record;
	}

	/**
	 * Returns the classes in the project, without those of its dependencies.
	 *
	 * @return list<Record>
	 */
	public function records() : array {
		return $this->records;
	}

	/**
	 * Returns this project and the ones it depends on, directly or not.
	 *
	 * @return list<Project> the projects, dependencies before the projects that depend on them
	 */
	public function allProjects() : array {
		$projects = [];
		foreach ($this->dependencies as $dependency)
			foreach ($dependency->allProjects() as $project)
				$projects[spl_object_id($project)] ??= $project;
		$projects[spl_object_id($this)] = $this;
		return array_values($projects);
	}

	/**
	 * Returns the classes of this project and the ones it depends on.
	 *
	 * @return list<Record> the classes, those of dependencies first
	 */
	public function allRecords() : array {
		return array_merge(...array_map(fn(Project $project) => $project->records(), $this->allProjects()));
	}

	/**
	 * Finds a class of this project or one it depends on.
	 *
	 * @param string $name the class name, unqualified (for one in this project's namespace) or with namespace
	 * @return ?Record the class, or null if none of the projects has it
	 */
	public function findRecord(string $name) : ?Record {
		$qualified = $this->qualifyClass($name);
		foreach ($this->allRecords() as $record)
			if ($record->qualifiedName() === $qualified)
				return $record;
		return null;
	}

	/**
	 * Returns the fully qualified name of a class referred to in the XML.
	 *
	 * @param string $class the class name; without a namespace, the project's is used
	 * @return string the class name with namespace, without leading backslash
	 */
	public function qualifyClass(string $class) : string {
		if (strpos($class, '\\') !== false)
			return ltrim($class, '\\');
		return $this->namespace == '' ? $class : $this->namespace.'\\'.$class;
	}

	/**
	 * Returns the shortest name for a class in the project's namespace: without
	 * the namespace if the class is directly in it.
	 *
	 * @param string $class the class name with namespace, without leading backslash
	 * @return string the class name without the project's namespace, or as given
	 *   if the class is in another namespace
	 */
	public function relativeClass(string $class) : string {
		$prefix = $this->namespace == '' ? '' : $this->namespace.'\\';
		if (strncmp($class, $prefix, strlen($prefix)) == 0 && strpos(substr($class, strlen($prefix)), '\\') === false)
			return substr($class, strlen($prefix));
		return $class;
	}

	/**
	 * Returns the class that a table belongs to, in this project or one it depends on.
	 *
	 * @param string $table the table name
	 * @return ?Record the class that has the table as its base table or a
	 *   dataset table, or null if no class has it
	 */
	public function tableOwner(string $table) : ?Record {
		foreach ($this->allRecords() as $record)
			if (in_array($table, $record->tables()))
				return $record;
		return null;
	}

	/**
	 * Returns the settings for a database dialect, from <schema dialect="...">.
	 *
	 * @param string $dialect the dialect, like 'mysql'
	 * @return array<string, string> the attributes as written, without 'dialect'; [] if there are none
	 */
	public function schemaSettings(string $dialect) : array {
		return $this->schemaSettings[$dialect] ?? [];
	}

	/**
	 * Uses the table style converter to convert class and property names into
	 * table and column names.
	 *
	 * @param string $term what kind of term to translate: table | id | fieldname | relation_id | relation_combo
	 * @param string $name the name to translate
	 * @return string the converted name; for relation_combo two names, separated by a comma
	 * @throws \Exception if the converted name is invalid (see XMLLoader::checkName())
	 */
	public function translate(string $term, string $name) : string {
		// With the default short id style, the id column is just 'id'; the long
		// one uses the converted class name + '_id'
		if ($term == 'id' && !$this->longIds)
			return 'id';

		$result = $this->converter::translate($term, $name);
		$what = $term == 'table' ? 'table name' : 'column name';
		foreach (explode(',', $result) as $converted)
			if (($problem = XMLLoader::checkName($converted)) !== null)
				throw new \Exception("'{$name}' converts to an invalid {$what}: {$problem}. Rename it, or set the {$what} explicitly");
		return $result;
	}

	/**
	 * Returns the references from the classes of the project (not those of its
	 * dependencies) to objects: their Class and DynamicClass properties, and
	 * their relations through a join  table. Rows in a class's own tables are
	 * its objects, so only the rows of a join table are just about the relation.
	 *
	 * @return list<Reference> the references, by class, properties first
	 */
	public function references() : array {
		$references = [];
		foreach ($this->records as $record) {
			foreach ($record->datasets as $dataset)
				foreach ($dataset->properties as $property) {
					if ($property->type == 'Class')
						$references[] = new Reference($record, $dataset->table, $property->columns[0], $property->class, $property);
					elseif ($property->type == 'DynamicClass')
						$references[] = new Reference($record, $dataset->table, $property->columns[1], null, $property);
				}

			foreach ($record->relations as $relation) {
				$relatedTables = $this->findRecord($relation->class)?->tables() ?? [];
				if (in_array($relation->table, $record->tables()) || in_array($relation->table, $relatedTables))
					continue;
				$references[] = new Reference($record, $relation->table, $relation->remoteId, $relation->class, null, $relation);
			}
		}
		return $references;
	}

	/** @var class-string the table style converter */
	private string $converter;

	/** @var list<Record> */
	private array $records = [];
}
