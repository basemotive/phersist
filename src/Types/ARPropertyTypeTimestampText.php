<?php

namespace PHersist\Types;

/**
 * Sets an automatic timestamp (for text fields).
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
class ARPropertyTypeTimestampText extends ARPropertyType {
	public function __construct($activeRecord=null) {
		parent::__construct($activeRecord);
	}

	public function fromDB(array $prop, array $values) : mixed {
		return $values[$prop['fieldnames'][0]];
	}

	public function toDB(array $prop, mixed $value) : array {
		// We may need to update the date field because of creation or modification of the object.
		if ($this->requiresAutoUpdate($prop)) {
			// The date format may be specified in the metadata, otherwise use default
			$dateFormat = isset($prop['date_format']) ? $prop['date_format'] : 'YmdHi';
			$value = date($dateFormat);
		}
		return [ $prop['fieldnames'][0] => $value ];
	}

	public function requiresAutoUpdate(array $prop) : bool {
		if ($this->activeRecord == null) return false;
		return ($prop['update_on']=='create' && $this->activeRecord->id==null) || $prop['update_on']=='modify';
	}

}