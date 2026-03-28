<?php

namespace PHersist;

use WeakReference;

/**
 * Holds weak references to objects retrieved from the database.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ObjectCache {
	/** @var array<string, WeakReference> */
	private static array $cache = [];

	private static bool $enabled = false;

	/**
	 * Enable or disable the ObjectCache.
	 *
	 * This is best enabled before starting the PHP session if you want to store
	 * PHersist objects in your session.
	 *
	 * @param bool $enabled if you want to use the ObjectCache
	 */
	public static function setEnabled(bool $enabled) : void {
		$this->$enabled = $enabled;
	}

	/**
	 * Puts an ActiveRecord object in the cache.
	 *
	 * @param ?ActiveRecord $object the object to put in the cache
	 */
	public static function put(?ActiveRecord $object) : void {
		if (!$this->enabled) return;
		if ($object == null || $object->id == null) return;
		self::$cache[get_class($object).':'.$object->id] = WeakReference::create($object);
	}

	/**
	 * Gets an ActiveRecord object from the cache.
	 *
	 * @param string $objectClass the class of the object
	 * @param int $objectId the id of the object
	 * @return ?ActiveRecord the stored object, or null in case of a cache miss
	 */
	public static function get(string $objectClass, int $objectId) : ?ActiveRecord {
		if (!$this->enabled) return null;
		return (self::$cache["{$objectClass}:{$objectId}"] ?? null)?->get();
	}

	/**
	 * Evicts an ActiveRecord object from the cache.
	 *
	 * @param ?ActiveRecord $object the object to evict from the cache
	 */
	public static function evict(?ActiveRecord $object) : void {
		if (!$this->enabled) return;
		if ($object == null || $object->id == null) return;
        unset(self::$cache[get_class($object).':'.$object->id]);
    }
}