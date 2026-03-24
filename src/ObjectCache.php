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

	public static function put(?ActiveRecord $object) : void {
		if ($object == null || $object->id == null) return;
		self::$cache[get_class($object).':'.$object->id] = WeakReference::create($object);
	}

	public static function get(string $objectClass, int $objectId) : ?ActiveRecord {
		return (self::$cache["{$objectClass}:{$objectId}"] ?? null)?->get();
	}

	public static function evict(?ActiveRecord $object) : void {
		if ($object == null || $object->id == null) return;
        unset(self::$cache[get_class($object).':'.$object->id]);
    }
}