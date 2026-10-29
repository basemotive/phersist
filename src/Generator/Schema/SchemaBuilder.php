<?php

namespace PHersist\Generator\Schema;

use PHersist\Generator\Model\Map;
use PHersist\Generator\Model\Project;
use PHersist\Generator\Model\Property;
use PHersist\Generator\Model\Record;
use PHersist\Generator\Model\Relation;

/**
 * Works out the tables that a model needs: the columns and indexes, but not
 * their SQL, which is up to the schema generator for each database.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class SchemaBuilder {
	/**
	 * Builds the schema for a model.
	 *
	 * A table can be shared by multiple classes (a join table that is defined
	 * from both sides, or one that holds the relations or maps of several
	 * classes), so each class adds the columns that the table doesn't have yet.
	 *
	 * @param Project $project the model
	 * @return Schema the tables
	 */
	public static function build(Project $project) : Schema {
		$schema = new Schema();
		foreach ($project->records() as $record)
			self::addRecord($schema, $record);
		return $schema;
	}

	/**
	 * Adds the tables of a class.
	 */
	private static function addRecord(Schema $schema, Record $record) : void {
		// The base table always exists, even if no dataset stores its properties
		// there, because it hands out the ids and holds the softdelete column
		$schema->table($record->table)->addColumn(new Column($record->idColumn, ColumnType::Id, autoIncrement: true), true);

		foreach ($record->datasets as $dataset) {
			// Other dataset tables receive their ids from the base table
			$table = $schema->table($dataset->table);
			$table->addColumn(new Column($record->idColumn, ColumnType::Id), true);

			foreach ($dataset->properties as $property)
				self::addProperty($table, $property, $record);
		}

		// The softdelete column belongs in the base table, because that's where
		// ActiveRecord and ObjectFinder look for it
		if ($record->softdelete)
			$schema->table($record->table)->addColumn(new Column('deleted', ColumnType::UnsignedInt, default: 0));

		// XMLLoader has checked the columns and names
		foreach ($record->indexes as $index)
			$schema->table($index->table)->addIndex(new Index($index->name, $index->columns, $index->unique,
				lengths: $index->lengths, explicit: true));

		foreach ($record->relations as $relation)
			self::addRelation($schema, $relation);

		foreach ($record->maps as $map)
			self::addMap($schema, $map);
	}

	/**
	 * Adds the columns of a property to its dataset's table.
	 */
	private static function addProperty(Table $table, Property $property, Record $record) : void {
		$nullable = !$property->required;
		$columnName = $property->columns[0];

		switch ($property->type) {
			case 'Text':
				$table->addColumn(new Column($columnName, ColumnType::Text, $nullable, $property->default));
				break;
			case 'Int':
				$table->addColumn(new Column($columnName, $property->signed ? ColumnType::Int : ColumnType::UnsignedInt, $nullable, $property->default));
				break;
			case 'Float':
				$table->addColumn(new Column($columnName, ColumnType::Float, $nullable, $property->default));
				break;
			case 'Decimal':
				$table->addColumn(new Column($columnName, ColumnType::Decimal, $nullable, $property->default,
					precision: $property->precision, scale: $property->scale));
				break;
			case 'Bool':
				$table->addColumn(new Column($columnName, ColumnType::Bool, $nullable, $property->default));
				break;
			case 'Date':
				$table->addColumn(new Column($columnName, ColumnType::Date, $nullable));
				break;
			case 'DateTime':
				$table->addColumn(new Column($columnName, ColumnType::DateTime, $nullable));
				break;
			case 'TimestampText':
				$table->addColumn(new Column($columnName, $property->storesText() ? ColumnType::Text : ColumnType::DateTime, $nullable));
				break;
			case 'Class':
				$table->addColumn(new Column($columnName, ColumnType::Reference, $nullable));
				self::addReferenceIndex($table, $property, $record);
				break;
			case 'DynamicClass':
				$table->addColumn(new Column($property->columns[0], ColumnType::ClassName, $nullable));
				$table->addColumn(new Column($property->columns[1], ColumnType::Reference, $nullable));
				self::addReferenceIndex($table, $property, $record);
				break;
			default:
				// A type class without a column definition here
				throw new \Exception("Property '{$property->name}' of class {$record->name} has type '{$property->type}', which has no column type");
		}
	}

	/**
	 * Returns the name of the automatic index on the columns of a Class or
	 * DynamicClass property.
	 *
	 * @param Project $project the project the property is in
	 * @param Property $property the property
	 */
	public static function referenceIndexName(Project $project, Property $property) : string {
		return 'idx_'.$project->translate('fieldname', $property->name);
	}

	/**
	 * Adds the automatic index on the columns of a Class or DynamicClass
	 * property, to look up and clean up the references to an object. It's left
	 * out when an <index> of the class starts with those columns, because
	 * the database can use that one for the same lookups.
	 */
	private static function addReferenceIndex(Table $table, Property $property, Record $record) : void {
		foreach ($record->indexes as $index)
			if ($index->table == $table->name && array_slice($index->columns, 0, count($property->columns)) === $property->columns)
				return;
		$table->addIndex(new Index(self::referenceIndexName($record->project, $property), $property->columns));
	}

	/**
	 * Adds the join table of a relation, if the relation owns it. A relation
	 * that doesn't own its table reads one that another relation or a class
	 * writes, and XMLLoader makes sure an owned table never holds a class.
	 */
	private static function addRelation(Schema $schema, Relation $relation) : void {
		if (!$relation->tableOwner)
			return;

		$table = $schema->table($relation->table);
		$table->addColumn(new Column($relation->localId, ColumnType::Reference));
		// The class name of the local object, for a table that holds the
		// relations of several classes; without it, the local id is all we need
		$localColumns = [ $relation->localId ];
		if ($relation->localType !== null) {
			$table->addColumn(new Column($relation->localType, ColumnType::ClassName));
			$localColumns[] = $relation->localType;
		}
		$table->addColumn(new Column($relation->remoteId, ColumnType::Reference));
		if ($relation->orderField !== null)
			$table->addColumn(new Column($relation->orderField, ColumnType::UnsignedInt));

		$table->addIndex(new Index('idx_'.$relation->localId, $localColumns));
		$table->addIndex(new Index('idx_'.$relation->remoteId, [ $relation->remoteId ]));
	}

	/**
	 * Adds the table of a map. XMLLoader only lets maps of different classes
	 * share a table, with the same columns.
	 */
	private static function addMap(Schema $schema, Map $map) : void {
		$table = $schema->table($map->table);

		$ownerColumns = [];
		if ($map->typeColumn !== null) {
			$table->addColumn(new Column($map->typeColumn, ColumnType::ClassName));
			$ownerColumns[] = $map->typeColumn;
		}
		$table->addColumn(new Column($map->idColumn, ColumnType::Reference));
		$ownerColumns[] = $map->idColumn;
		foreach ($map->keys as $key)
			$table->addColumn(new Column($key, ColumnType::MapKey));
		$table->addColumn(new Column($map->value, ColumnType::MapValue));

		// A map is always loaded and deleted by its owner, so that's the index
		// PHersist needs. The owner and the keys together identify a value, so
		// they form a unique index too, if the database can create it; it also
		// serves to look up the owner's rows.
		$table->addIndex(new Index('idx_'.$map->idColumn, $ownerColumns));
		$table->addIndex(new Index('uniq_'.$map->idColumn, array_merge($ownerColumns, $map->keys), unique: true, optional: true));
	}
}
