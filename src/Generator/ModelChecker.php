<?php

namespace PHersist\Generator;

use PHersist\Generator\Model\Project;
use PHersist\Generator\Model\Record;
use PHersist\Generator\Model\Relation;

/**
 * Checks the model for problems between its parts, like names that aren't
 * unique, or relations and maps that use a table they can't. XMLLoader has
 * already checked each element on its own.
 *
 * The checks include the model XMLs that the project depends on, since their
 * classes share the database.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ModelChecker {
	/**
	 * Checks the model.
	 *
	 * @param Project $project the model
	 * @throws \Exception describing the problems
	 */
	public static function check(Project $project) : void {
		self::checkDependencies($project);
		self::checkUniqueNames($project);
		self::checkRelations($project);
		self::checkMaps($project);
	}

	/**
	 * Checks the model XMLs that the project depends on:
	 *
	 * - Each has its own namespace, since the generated PHersistReferences
	 *   class with the references between the classes is per namespace.
	 * - A Class property or relation that refers to a class of another model
	 *   XML is in the same database (connection id) as that class, since a
	 *   delete cleans up the references in one transaction.
	 *
	 * @param Project $project the model
	 * @throws \Exception if a model XML breaks these rules
	 */
	private static function checkDependencies(Project $project) : void {
		$namespaces = [];
		foreach ($project->allProjects() as $other) {
			if (isset($namespaces[$other->namespace]))
				throw new \Exception("Model XMLs '{$namespaces[$other->namespace]}' and '{$other->file}' have the same namespace"
					.($other->namespace === '' ? ' (none)' : " '{$other->namespace}'").'. Give each model XML its own namespace:'
					.' the references from its classes are generated into a class in that namespace');
			$namespaces[$other->namespace] = $other->file;
		}

		foreach ($project->records() as $record) {
			$targets = [];
			foreach ($record->properties() as $property)
				if ($property->class !== null)
					$targets[] = [ "property '{$property->name}'", $property->class ];
			foreach ($record->relations as $relation)
				$targets[] = [ "relation '{$relation->name}'", $relation->class ];

			foreach ($targets as [ $description, $class ]) {
				$target = $project->findRecord($class);
				if ($target !== null && $target->project !== $project && $target->database !== $record->database)
					throw new \Exception(ucfirst($description)." of class '{$record->name}' refers to class '{$target->qualifiedName()}' of model XML"
						." '{$target->project->file}', which uses database '{$target->database}' instead of '{$record->database}'."
						.' References between model XMLs need the same database (connection id), because a delete cleans them up in one transaction');
			}
		}
	}

	/**
	 * Returns where an element of the model is, for an error message: its line,
	 * and its file if that's another model XML than the project's.
	 */
	private static function where(Project $project, Record $record, int $line) : string {
		return $record->project === $project ? "line {$line}" : "line {$line} of '{$record->project->file}'";
	}

	/**
	 * Checks that names are unique, since the generators would otherwise let
	 * one definition silently replace or share another:
	 *
	 * - class names in the project (case-insensitive, like PHP class names)
	 * - property, relation and map names in a class, which are all accessed
	 *   as $object->name. They can't be 'id' either, nor the name of the id
	 *   column, which the object stores its id under.
	 * - the tables of the classes (case-insensitive, since MySQL table names
	 *   are on Windows and macOS): a table holds the objects of one class.
	 *   Two classes on one table would see each other's rows as their own
	 *   objects, and with dataset tables their IDs would even overlap.
	 * - column names per table of a class (case-insensitive, like MySQL
	 *   column names): the id column, the properties' columns, and the
	 *   softdelete column 'deleted' in the base table.
	 *
	 * @param Project $project the model
	 * @throws \Exception listing all the duplicates, with their line numbers
	 */
	private static function checkUniqueNames(Project $project) : void {
		$problems = [];

		$classNames = [];
		$tableClasses = [];
		foreach ($project->allRecords() as $record) {
			$at = fn(int $line) : string => self::where($project, $record, $line);
			$description = "<class> '{$record->name}'";
			$other = $classNames[strtolower($record->qualifiedName())] ?? null;
			if ($other !== null)
				$problems[] = "{$at($record->line)}: {$description} has the same name as <class> '{$other->name}' on ".self::where($project, $other, $other->line);
			else
				$classNames[strtolower($record->qualifiedName())] = $record;

			$members = [];
			foreach ($record->properties() as $property)
				$members[] = [ "<property> '{$property->name}' of class '{$record->name}'", $property->name, $property->line ];
			foreach ($record->relations as $relation)
				$members[] = [ "<relation> '{$relation->name}' of class '{$record->name}'", $relation->name, $relation->line ];
			foreach ($record->maps as $map)
				$members[] = [ "<map> '{$map->name}' of class '{$record->name}'", $map->name, $map->line ];

			$memberNames = [];
			foreach ($members as $member) {
				[ $memberDescription, $name, $line ] = $member;
				if ($name == 'id')
					$problems[] = "{$at($line)}: {$memberDescription} can't be named 'id', it is reserved for the object's id";
				elseif ($name == $record->idColumn)
					$problems[] = "{$at($line)}: {$memberDescription} can't have the name of the class's id column '{$record->idColumn}',"
						.' the object stores its id under that name';
				elseif (isset($memberNames[$name]))
					$problems[] = "{$at($line)}: {$memberDescription} has the same name as {$memberNames[$name][0]} on line {$memberNames[$name][2]}";
				else
					$memberNames[$name] = $member;
			}

			// The columns of each table: [ table => [ lowercase column => what uses it ] ]
			$tables = $record->tables();
			foreach ($tables as $table) {
				$other = $tableClasses[strtolower($table)] ??= $record;
				if ($other !== $record)
					$problems[] = "{$at($record->line)}: {$description} uses table '{$table}', which is already a table of"
						." <class> '{$other->name}' on ".self::where($project, $other, $other->line).": give each class its own tables";
			}
			$columns = [];
			foreach ($tables as $table)
				$columns[$table] = [ strtolower($record->idColumn) => "the id column of {$description}" ];

			if ($record->softdelete) {
				if (isset($columns[$record->table]['deleted']))
					$problems[] = "{$at($record->line)}: {$description} has softdelete=\"true\", which needs a column 'deleted'"
						." in table '{$record->table}', but that is already {$columns[$record->table]['deleted']}";
				else
					$columns[$record->table]['deleted'] = "the softdelete column of {$description}";
			}

			foreach ($record->datasets as $dataset)
				foreach ($dataset->properties as $property) {
					$propertyDescription = "<property> '{$property->name}' of class '{$record->name}'";
					foreach ($property->columns as $column) {
						$key = strtolower($column);
						if (isset($columns[$dataset->table][$key])) {
							$problems[] = "{$at($property->line)}: {$propertyDescription} uses column '{$column}' in table '{$dataset->table}',"
								." which is already {$columns[$dataset->table][$key]}: set another column name with fieldname or fieldnames";
						} else
							$columns[$dataset->table][$key] = "used by {$propertyDescription} on line {$property->line}";
					}
				}
		}

		if ($problems)
			throw new \Exception("Invalid model XML:\n- ".implode("\n- ", $problems));
	}

	/**
	 * Checks the tables of the relations:
	 *
	 * - A relation can't use one of its own class's tables, unless that table
	 *   also holds the related class (like a parent/children relation). Its rows
	 *   would be the object itself, so it holds at most one related object,
	 *   which a Class property already does.
	 * - A relation can't own a table that holds a class. Such a relation is
	 *   derived: its rows are the objects of that class, so writing the
	 *   relation would delete their data.
	 * - The local_id and remote_id columns of a relation on a class's table
	 *   must refer to the class with the relation and the related class,
	 *   when they belong to that class (see checkDerivedRelation()).
	 * - Relations on the same join table (one that holds no class) and with
	 *   the same local_id column select their rows by the same object ID:
	 *   - Relations of different classes would read each other's rows, since
	 *     their IDs overlap, so they need the same local_type column that
	 *     tells their rows apart, whether they own the table or not.
	 *   - Relations of the same class can't have the same remote_id column
	 *     too: nothing in the table tells their rows apart, so they are one
	 *     relation defined twice. And they can't both own the table, because
	 *     each replaces all rows of its object on commit, so it deletes the
	 *     rows of the other one.
	 *   The two directions of one relation, with local_id and remote_id
	 *   swapped, use different columns and are fine.
	 * - The columns of a relation (local_id, remote_id, local_type and
	 *   order_field) must be different, or its queries name a column twice.
	 * - All columns of a join table are required, and each relation that owns
	 *   it inserts rows with only its own columns. So all owners must use the
	 *   same columns: the same local_type and order_field, and the same
	 *   local_id and remote_id, possibly swapped. With an order_field they
	 *   can't be swapped, though: the positions are per local_id, so the two
	 *   directions would overwrite each other's positions. A relation that
	 *   doesn't own the table can only use columns that its owners create; a
	 *   table without owners isn't generated, so its columns aren't checked.
	 *
	 * @param Project $project the model
	 * @throws \Exception if a relation uses a table that it can't
	 */
	private static function checkRelations(Project $project) : void {
		$joinTables = [];
		$joinRelations = [];
		foreach ($project->allRecords() as $record)
			foreach ($record->relations as $relation) {
				$className = $record->name;
				$table = $relation->table;

				$columns = $relation->columns();
				$seen = [];
				foreach ($columns as $attribute => $column) {
					if (isset($seen[strtolower($column)]))
						throw new \Exception("Relation '{$relation->name}' of class '{$className}' uses column '{$column}' as both"
							." {$seen[strtolower($column)]} and {$attribute}: each needs a column of its own");
					$seen[strtolower($column)] = $attribute;
				}

				// checkUniqueNames() has made sure that a table belongs to one class at most
				$relatedTables = $project->findRecord($relation->class)?->tables() ?? [];
				if (in_array($table, $record->tables()) && !in_array($table, $relatedTables))
					throw new \Exception("Relation '{$relation->name}' of class '{$className}' cannot use its own class's table '{$table}'."
						.' Its rows would be the object itself, so it holds at most one related object: use a Class property instead');

				$tableClass = $project->tableOwner($table);
				if ($relation->tableOwner && $tableClass !== null)
					throw new \Exception("Relation '{$relation->name}' of class '{$className}'"
						." cannot have table_owner=\"true\", because its table '{$table}' is a table of class '{$tableClass->name}'."
						.' A relation on a class\'s table is derived and must be read-only: use table_owner="false",'
						.' and change the property of the related objects instead');

				if ($tableClass !== null) {
					self::checkDerivedRelation($project, $relation, $record, $tableClass);
					continue;
				}

				$localType = $relation->localType ?? '';
				$description = "Relation '{$relation->name}' of class '{$className}'";
				foreach ($joinTables[$table][$relation->localId] ?? [] as $other) {
					$otherDescription = "relation '{$other['relation']}' of class '{$other['class']}'";
					if ($other['class'] == $className) {
						if ($relation->remoteId == $other['remote_id'])
							throw new \Exception("{$description} is the same as {$otherDescription}: both use table '{$table}'"
								." with local_id '{$relation->localId}' and remote_id '{$relation->remoteId}'. Nothing in the table tells their rows apart,"
								.' so they would hold the same objects: give each relation its own table');
						if ($relation->tableOwner && $other['owner'])
							throw new \Exception("{$description} cannot own table '{$table}' with local_id '{$relation->localId}', because {$otherDescription} already does."
								.' Each would delete the other\'s rows on commit: give each relation its own table');
					} elseif ($localType === '' || $other['local_type'] === '')
						throw new \Exception("{$description} uses table '{$table}' with local_id '{$relation->localId}', like {$otherDescription},"
							.' so both need a local_type attribute: the local_type column stores the class name that tells their rows apart');
					elseif ($localType != $other['local_type'])
						throw new \Exception("{$description} uses table '{$table}' with local_id '{$relation->localId}', like {$otherDescription},"
							.' so it must have the same local_type column');
				}
				$joinTables[$table][$relation->localId][] = [
					'class' => $className, 'relation' => $relation->name, 'remote_id' => $relation->remoteId,
					'local_type' => $localType, 'owner' => $relation->tableOwner,
				];
				$joinRelations[$table][] = [ 'description' => $description, 'owner' => $relation->tableOwner, 'columns' => $columns ];
			}

		foreach ($joinRelations as $table => $relations) {
			$owners = array_values(array_filter($relations, fn($relation) => $relation['owner']));
			if (!$owners)
				continue;

			// Compare the columns case-insensitively, like MySQL, with local_id and remote_id in any order
			$normalize = function(array $columns) : array {
				$columns = array_map('strtolower', $columns);
				$ids = [ $columns['local_id'], $columns['remote_id'] ];
				sort($ids);
				return [ $ids, $columns['local_type'] ?? null, $columns['order_field'] ?? null ];
			};
			$first = $owners[0];
			foreach (array_slice($owners, 1) as $other)
				if ($normalize($other['columns']) != $normalize($first['columns']))
					throw new \Exception("{$other['description']} owns table '{$table}' with columns ".self::describeColumns($other['columns'])
						.", but ".lcfirst($first['description'])." owns it with columns ".self::describeColumns($first['columns']).'.'
						.' All columns of a join table are required, and each owner inserts rows with only its own columns:'
						.' give them the same local_type and order_field, and the same local_id and remote_id (swapped is fine)');

			// The positions in the order field are per local_id, so an owner in the
			// other direction would renumber the rows by its own order on commit
			if (isset($first['columns']['order_field']))
				foreach (array_slice($owners, 1) as $other)
					if (strtolower($other['columns']['local_id']) != strtolower($first['columns']['local_id']))
						throw new \Exception("{$other['description']} owns table '{$table}' in the other direction than ".lcfirst($first['description'])
							.", but the table has order_field '{$first['columns']['order_field']}'. Its positions are per local_id,"
							.' so each owner would overwrite the other\'s positions on commit: let one of them own the table,'
							.' and give the other table_owner="false"');

			$tableColumns = array_map('strtolower', $first['columns']);
			foreach ($relations as $relation)
				foreach ($relation['columns'] as $attribute => $column)
					if (!in_array(strtolower($column), $tableColumns))
						throw new \Exception("{$relation['description']} uses column '{$column}' as {$attribute}, which table '{$table}' doesn't have:"
							." ".lcfirst($first['description'])." owns the table with columns ".self::describeColumns($first['columns']));
		}
	}

	/**
	 * Describes the columns of a relation for an error message, like "local_id 'user_id', remote_id 'tag_id'".
	 *
	 * @param array<string, string> $columns the columns, see Relation::columns()
	 * @return string the description
	 */
	private static function describeColumns(array $columns) : string {
		return implode(', ', array_map(fn($attribute, $column) => "{$attribute} '{$column}'", array_keys($columns), $columns));
	}

	/**
	 * Checks the columns of a relation on a class's table. The generator
	 * doesn't create columns for such a relation, so all of them (local_id,
	 * remote_id, and local_type and order_field if set) must exist in the
	 * table: be the id column, the column of a property in that table, or the
	 * softdelete column 'deleted' in the base table. Then:
	 *
	 * - local_id must refer to the class with the relation: be a Class
	 *   property of that class, or the id column of a DynamicClass property,
	 *   with the relation's local_type on its class-name column and the same
	 *   use_namespace. It can't be the id column of the table's class: the
	 *   rows would be the object itself, which a Class property does better.
	 * - remote_id must refer to the related class: be the id column of that
	 *   class, or a Class property of that class. A DynamicClass property
	 *   can't be used, since a relation has no column to select the remote
	 *   class by.
	 *
	 * Otherwise the relation would list objects that refer to something else
	 * with the same ID. Column names are compared case-insensitively, like
	 * MySQL does.
	 *
	 * @param Project $project the model
	 * @param Relation $relation the relation
	 * @param Record $record the class with the relation
	 * @param Record $tableClass the class whose table the relation uses
	 * @throws \Exception if a column doesn't exist or refers to something else
	 */
	private static function checkDerivedRelation(Project $project, Relation $relation, Record $record, Record $tableClass) : void {
		$table = $relation->table;
		$className = $record->name;
		$description = "Relation '{$relation->name}' of class '{$className}'";
		$idColumn = $tableClass->idColumn;
		$softdeleteColumn = $tableClass->softdelete && $table == $tableClass->table;

		foreach ($relation->columns() as $attribute => $column)
			if (strcasecmp($column, $idColumn) != 0 && $tableClass->findColumnProperty($table, $column) === null
					&& !($softdeleteColumn && strcasecmp($column, 'deleted') == 0))
				throw new \Exception("{$description} uses column '{$column}' as {$attribute}, but table '{$table}' of class '{$tableClass->name}'"
					." doesn't have it: use its id column '{$idColumn}' or the column of a property in that table");

		$sides = [
			'local_id' => [ $relation->localId, $record->qualifiedName() ],
			'remote_id' => [ $relation->remoteId, $relation->class ],
		];
		foreach ($sides as $attribute => [ $column, $refersTo ]) {
			$prefix = "{$description} uses column '{$column}' of table '{$table}' as {$attribute}, which";
			$fix = "use a column that refers to class '{$project->relativeClass($refersTo)}'";

			if (strcasecmp($column, $idColumn) == 0) {
				$sameClass = $tableClass->qualifiedName() == $refersTo;
				if ($attribute == 'local_id' && $sameClass)
					throw new \Exception("{$prefix} is the id column of class '{$tableClass->name}'."
						.' Its rows would be the object itself, so it holds at most one related object: use a Class property instead');
				if (!$sameClass)
					throw new \Exception("{$prefix} is the id column of class '{$tableClass->name}': {$fix}");
				continue;
			}

			$property = $tableClass->findColumnProperty($table, $column);
			if ($property === null)
				throw new \Exception("{$prefix} is the softdelete column of class '{$tableClass->name}': {$fix}");

			$prefix .= " belongs to property '{$property->name}' of class '{$tableClass->name}'";
			if ($property->type == 'Class') {
				$propertyClass = $project->relativeClass((string)$property->class);
				if ($property->class != $refersTo)
					throw new \Exception("{$prefix}, a reference to class '{$propertyClass}'."
						." The relation would use the {$propertyClass} with the same ID: {$fix}");
			} elseif ($property->type != 'DynamicClass')
				throw new \Exception("{$prefix}, of type {$property->type}, which doesn't refer to an object: {$fix}");
			elseif ($attribute == 'remote_id')
				throw new \Exception("{$prefix}, a DynamicClass that can refer to any class."
					." A relation can't select its related objects by class name: {$fix}");
			elseif (strcasecmp($column, $property->columns[1]) != 0)
				throw new \Exception("{$prefix}, as its class-name column. Use its id column '{$property->columns[1]}' as local_id,"
					." and its class-name column '{$property->columns[0]}' as local_type");
			elseif (strcasecmp($relation->localType ?? '', $property->columns[0]) != 0)
				throw new \Exception("{$prefix}, a DynamicClass that can refer to any class."
					." Set local_type=\"{$property->columns[0]}\", its class-name column, so the relation only lists the objects that refer to a {$className}");
			elseif ($relation->useNamespace != $property->useNamespace)
				throw new \Exception("{$description} must have the same use_namespace value as property '{$property->name}'"
					." of class '{$tableClass->name}', so its local_type matches the stored class names");
		}
	}

	/**
	 * Checks the tables of the maps. A map selects its rows by owner (and by
	 * class name, with a type column), and replaces all of them on commit, so
	 * it needs a table of its own:
	 *
	 * - A map can't use a table of a class or a relation. Its rows would be
	 *   read as map data and deleted, and the map's columns are required.
	 * - Maps of the same class can't share a table: they would load and delete
	 *   each other's rows.
	 * - Maps of different classes can share a table only with a type column
	 *   that tells their rows apart, and with the same columns.
	 *
	 * @param Project $project the model
	 * @throws \Exception if a map uses a table that it can't
	 */
	private static function checkMaps(Project $project) : void {
		$usedTables = [];
		foreach ($project->allRecords() as $record) {
			foreach ($record->tables() as $table)
				$usedTables[$table] ??= "a table of class '{$record->name}'";
			foreach ($record->relations as $relation)
				$usedTables[$relation->table] ??= "the table of relation '{$relation->name}' of class '{$record->name}'";
		}

		$mapTables = [];
		foreach ($project->allRecords() as $record)
			foreach ($record->maps as $map) {
				$className = $record->name;
				$table = $map->table;
				$description = "Map '{$map->name}' of class '{$className}'";

				if (isset($usedTables[$table]))
					throw new \Exception("{$description} cannot use table '{$table}', because it is {$usedTables[$table]}."
						.' A map replaces all rows of its owner on commit, so it needs a table of its own');

				$columns = [ $map->idColumn, $map->typeColumn, $map->keys, $map->value ];
				if (!isset($mapTables[$table])) {
					$mapTables[$table] = [ 'class' => $className, 'map' => $map->name, 'columns' => $columns ];
					continue;
				}

				$other = $mapTables[$table];
				$otherDescription = "map '{$other['map']}' of class '{$other['class']}'";
				if ($other['class'] == $className)
					throw new \Exception("{$description} cannot use table '{$table}', because {$otherDescription} already does."
						.' Maps of the same class would load and delete each other\'s rows, so each needs a table of its own');
				if ($map->typeColumn === null || $other['columns'][1] === null)
					throw new \Exception("{$description} shares table '{$table}' with {$otherDescription},"
						.' so both need a type attribute: the type column stores the class name that tells their rows apart');
				if ($columns != $other['columns'])
					throw new \Exception("{$description} shares table '{$table}' with {$otherDescription},"
						.' so it must have the same id, type, <key> and <value> column names');
			}
	}
}
