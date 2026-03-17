<?php

namespace PHersist\Types;
use PHersist\ActiveRecord;

/**
 * Defines the behaviour for a relation type for the ActiveRecord.
 * TODO: Maybe these classes could be static, which could save a bit on memory consumption!
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
abstract class ARRelationType {
	/**
	 * When an ARRelationType instance is created from the ActiveRecord, the object itself
	 * is provided to the constructor. This way methods could access the object to do
	 * some extra checks or processing.
	 * However, when searching for objects, the object will not be available.
	 */
	public function __construct(\PDO $PDO, ?ActiveRecord $activeRecord = null) {
		$this->PDO = $PDO;
		$this->activeRecord = $activeRecord;
	}

	/**
	 * Restores this relation
	 * @param array $rel the relation definition from the metadata
	 * @return array the objects
	 */
	public abstract function restore(array $rel) : array;

	/**
	 * Stores this relation
	 *
	 * @param array $rel the relation definition from the metadata
	 * @param array $objects the objects to store
	 */
	public abstract function store($rel, array $objects) : void;

	/**
	 * Deletes this relation
	 *
	 * @param array $rel the relation definition from the metadata
	 */
	public abstract function delete(array $rel) : void;

	protected ?ActiveRecord $activeRecord = null;
	protected $PDO = null;
}