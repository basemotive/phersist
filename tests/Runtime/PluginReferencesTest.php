<?php

/**
 * References between model XMLs: a plugin (plugin.xml) refers to the classes
 * of the core (core.xml). Deleting a core object cleans up the plugin's
 * references only when the plugin's namespace is registered. See
 * docs/advanced-features.md, "Projects with several model XMLs (plugins)".
 */

use PHersist\ReferenceRegistry;
use Tests\Models\Core\{Member, Team};
use Tests\Models\Plugin\Entry;

useModel('core', 'plugin');

beforeEach(fn () => given([
	'members' => [ [ 'id' => 1, 'name' => 'Ann' ], [ 'id' => 2, 'name' => 'Bob' ] ],
	'teams' => [ [ 'id' => 1, 'name' => 'Core team' ] ],
	'member_teams' => [ [ 'member_id' => 1, 'team_id' => 1 ] ],
	'entries' => [
		[ 'id' => 1, 'title' => 'By Ann', 'author_id' => 1, 'editor_id' => 2 ],
		[ 'id' => 2, 'title' => 'Edited by Ann', 'author_id' => 2, 'editor_id' => 1 ],
	],
	'entry_likes' => [ [ 'entry_id' => 1, 'member_id' => 1 ], [ 'entry_id' => 1, 'member_id' => 2 ] ],
	'notes' => [
		[ 'id' => 1, 'text' => 'About Ann', 'subject_type' => 'Tests\Models\Core\Member', 'subject_id' => 1 ],
		[ 'id' => 2, 'text' => 'About entry 1', 'subject_type' => 'Entry', 'subject_id' => 1 ],
	],
]));

it('cleans up the plugin\'s references once its namespace is registered', function () {
	ReferenceRegistry::register('Tests\Models\Plugin');

	Member::fetch(1)->delete();

	expectState([
		'members' => [ [ 'id' => 2 ] ],
		'member_teams' => [],
		'entries' => [ [ 'id' => 1, 'author_id' => null ] ], // entry 2 is cascaded by its editor
		'entry_likes' => [ [ 'entry_id' => 1, 'member_id' => 2 ] ],
		'notes' => [ [ 'id' => 2 ] ],
	]);
});

it('accepts the namespace with a leading backslash, and more than once', function () {
	ReferenceRegistry::register('\Tests\Models\Plugin');
	ReferenceRegistry::register('Tests\Models\Plugin\\');

	Member::fetch(2)->delete();

	expectState([
		'members' => [ [ 'id' => 1 ] ],
		'entries' => [ [ 'id' => 2, 'author_id' => null ] ], // entry 1 is cascaded by its editor
		'entry_likes' => [],
		'notes' => [ [ 'id' => 1 ] ], // entry 1's note is cascaded along
	]);
});

it('leaves the plugin\'s references alone without the registration', function () {
	// Documented: only the deleted object's own model XML is known by default
	Member::fetch(1)->delete();

	expectState([ 'members' => [ [ 'id' => 2 ] ], 'member_teams' => [] ]);
});

it('cleans up references within the plugin itself without registration', function () {
	Entry::fetch(1)->delete();

	expectState([
		'entries' => [ [ 'id' => 2 ] ],
		'entry_likes' => [],
		'notes' => [ [ 'id' => 1 ] ],
	]);
});

it('fails, changing nothing, for a registered namespace without references', function () {
	ReferenceRegistry::register('Tests\Models\Nothing');

	expect(fn () => Team::fetch(1)->delete())->toThrow(Exception::class, 'PHersistReferences');
	expectUnchanged();
});
