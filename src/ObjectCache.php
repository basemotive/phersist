<?php

namespace PHersist;

// TODO
// Now that PHP8 has the WeakMap class, this ObjectCache should make use of
// that.

/**
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ObjectCache {
	private $data = [];

	public function put(?ActiveRecord $object) : void {
		if ($object == null || $object->id == '') return;
		$this->data[get_class($object).':'.$object->id] = $object;
	}

	public function get(string $objectClass, mixed $objectId) : ?ActiveRecord {
		$cache_id = "$objectClass:$objectId";
		return isset($this->data[$cache_id]) ? $this->data[$cache_id] : null;
	}
}