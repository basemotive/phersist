<?php

namespace PHersist\Generator\Schema;

use PHersist\Generator\Model\Map;
use PHersist\Generator\Model\Project;
use PHersist\Generator\Model\Property;
use PHersist\Generator\Model\Record;
use PHersist\Generator\Model\Relation;

/**
 * Works out the tables that a model needs: the columns, indexes and foreign
 * keys, but not their SQL, which is up to the schema generator for each
 * database.
 *
 * The foreign keys are always worked out; a schema generator only writes
 * them if its database has them and the model asks for them. They follow
 * what PHersist does itself when an object is deleted (see ReferenceCleaner
 * and ActiveRecord::delete()), so the database and PHersist agree:
 *
 * - A Class property refers to the base table of its class, with the rule of
 *   its on_remote_delete. A DynamicClass property has no key, because the
 *   table it refers to differs per row.
 * - The columns of a join table refer to the two classes of the relation, and
 *   the rows are deleted along with either of them.
 * - The id of a dataset table other than the base table, and the owner of a
 *   map, refer to the base table; the rows are deleted along with the object.
 *
 * There's no key to a class in another database, nor on a column that holds
 * the ids of several classes (next to a local_type or map type column).
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
		self::makeForeignKeyNamesUnique($schema);
		return $schema;
	}

	/**
	 * Gives the foreign keys that have the same name a name with a hash,
	 * which tells them apart: 'fk_a_b_c' is both the key of table 'a_b',
	 * column 'c' and that of table 'a', column 'b_c'.
	 */
	private static function makeForeignKeyNamesUnique(Schema $schema) : void {
		$count = [];
		foreach ($schema->tables() as $table)
			foreach ($table->foreignKeys as $key)
				$count[strtolower($key->name)] = ($count[strtolower($key->name)] ?? 0) + 1;

		foreach ($schema->tables() as $table)
			foreach ($table->foreignKeys as $index => $key)
				if ($count[strtolower($key->name)] > 1)
					$table->foreignKeys[$index] = new ForeignKey(ForeignKey::name($table->name, $key->column, true),
						$key->column, $key->referencedTable, $key->referencedColumn, $key->onDelete);
	}

	/**
	 * Adds a foreign key on a column to the base table of a class, unless the
	 * class is in another database than the table of the column.
	 *
	 * @param Table $table the table of the column
	 * @param string $column the column that holds the id
	 * @param Record $record the class that has the table
	 * @param ?Record $target the class the column refers to; null if the model doesn't have it
	 * @param ForeignKey::SET_NULL|ForeignKey::RESTRICT|ForeignKey::CASCADE $onDelete
	 *   what happens to the row when the object it refers to is deleted
	 */
	private static function addForeignKey(Table $table, string $column, Record $record, ?Record $target, string $onDelete) : void {
		if ($target === null || $target->database !== $record->database)
			return;
		$table->addForeignKey(new ForeignKey(ForeignKey::name($table->name, $column), $column, $target->table, $target->idColumn, $onDelete));
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
			// ActiveRecord deletes those rows before the one in the base table
			if ($dataset->table != $record->table)
				self::addForeignKey($table, $record->idColumn, $record, $record, ForeignKey::CASCADE);

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
			self::addRelation($schema, $relation, $record);

		foreach ($record->maps as $map)
			self::addMap($schema, $map, $record);
	}

	/**
	 * Adds the columns of a property to its dataset's table.
	 */
	private static function addProperty(Table $table, Property $property, Record $record) : void {
		$nullable = !$property->required;
		$columnName = $property->columns[0];

		switch ($property->type) {
			case 'Text':
				if ($property->length !== null)
					$table->addColumn(new Column($columnName, ColumnType::VarChar, $nullable, $property->default, length: $property->length));
				else
					$table->addColumn(new Column($columnName, match ($property->size) {
						'tiny' => ColumnType::TinyText,
						'medium' => ColumnType::MediumText,
						'long' => ColumnType::LongText,
						default => ColumnType::Text,
					}, $nullable, $property->default));
				break;
			case 'Int':
				$columnType = match ($property->size) {
					'small' => $property->signed ? ColumnType::SmallInt : ColumnType::UnsignedSmallInt,
					'big' => $property->signed ? ColumnType::BigInt : ColumnType::UnsignedBigInt,
					default => $property->signed ? ColumnType::Int : ColumnType::UnsignedInt,
				};
				$table->addColumn(new Column($columnName, $columnType, $nullable, $property->default));
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
				self::addReferenceKey($table, $property, $record);
				break;
			case 'DynamicClass':
				// No foreign key: the table it refers to differs per row
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
	 * Adds the foreign key of a Class property, with the rule of its
	 * on_remote_delete. The automatic index, or the <index> that replaces
	 * it, starts with the column, as the database needs for a foreign key.
	 *
	 * A softdelete class with on_remote_delete="cascade" gets no key. When the
	 * object it refers to is deleted, PHersist softdeletes the referring
	 * objects, which keep their reference so they can be told apart later. A
	 * key would either delete those rows for real (CASCADE) or stop the
	 * deletion (RESTRICT).
	 */
	private static function addReferenceKey(Table $table, Property $property, Record $record) : void {
		if ($property->onRemoteDelete == 'cascade' && $record->softdelete)
			return;
		$onDelete = match ($property->onRemoteDelete) {
			'cascade' => ForeignKey::CASCADE,
			'restrict' => ForeignKey::RESTRICT,
			default => ForeignKey::SET_NULL,
		};
		self::addForeignKey($table, $property->columns[0], $record, $record->project->findRecord((string)$property->class), $onDelete);
	}

	/**
	 * Adds the join table of a relation, if the relation owns it. A relation
	 * that doesn't own its table reads one that another relation or a class
	 * writes, and XMLLoader makes sure an owned table never holds a class.
	 */
	private static function addRelation(Schema $schema, Relation $relation, Record $record) : void {
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

		// PHersist deletes the rows of a join table when the object on either
		// side is deleted. Both indexes start with the id, as a foreign key
		// needs. With a local_type, the local id is that of several classes.
		if ($relation->localType === null)
			self::addForeignKey($table, $relation->localId, $record, $record, ForeignKey::CASCADE);
		self::addForeignKey($table, $relation->remoteId, $record, $record->project->findRecord($relation->class), ForeignKey::CASCADE);
	}

	/**
	 * Adds the table of a map. XMLLoader only lets maps of different classes
	 * share a table, with the same columns.
	 */
	private static function addMap(Schema $schema, Map $map, Record $record) : void {
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

		// The map is deleted along with its owner. Without a type column the
		// owner index starts with the id, as a foreign key needs; with one,
		// the id is that of several classes.
		if ($map->typeColumn === null)
			self::addForeignKey($table, $map->idColumn, $record, $record, ForeignKey::CASCADE);
	}
}
