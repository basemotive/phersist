<?php

namespace Babble\Model;

trait ForumMessageTrait {
	function createdAtRelative(): string {
	    $now  = new \DateTime();
	    $past = new \DateTime($this->createdAt);
	    $diff = $now->diff($past);

	    $intervals = [
	        ['label' => 'year',   'value' => $diff->y],
	        ['label' => 'month',  'value' => $diff->m],
	        ['label' => 'week',   'value' => (int)($diff->days / 7)],
	        ['label' => 'day',    'value' => $diff->d],
	        ['label' => 'hour',   'value' => $diff->h],
	        ['label' => 'minute', 'value' => $diff->i],
	        ['label' => 'second', 'value' => $diff->s],
	    ];

	    // Return "just now" if under 5 seconds
	    if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0 && $diff->s < 5) {
	        return 'just now';
	    }

	    foreach ($intervals as $interval) {
	        if ($interval['value'] > 0) {
	            $label = $interval['label'];
	            $value = $interval['value'];
	            // Pluralise if needed
	            return $value . ' ' . $label . ($value > 1 ? 's' : '') . ' ago';
	        }
	    }

	    return 'just now';
	}
}

?>