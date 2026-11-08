<?php

/**
 * Cleaning up references to a deleted object: on_remote_delete null, restrict
 * and cascade, for Class and DynamicClass properties; cascade chains and
 * cycles; restrict checked last; soft-deleted referrers; and that a failed
 * delete changes nothing. See docs/creating-model-from-xml.md, "Deleting
 * objects with relations".
 */

use Tests\Models\References\{BothRef, CascadeRef, CycleA, DynRef, NullRef, Target};

useModel('references');

describe('on_remote_delete="null"', function () {
	it('sets Class references to null', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ], [ 'id' => 2, 'name' => 'T2' ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => 2 ], [ 'id' => 3, 'target_id' => 1 ] ],
		]);

		Target::fetch(1)->delete();

		expectState([
			'targets' => [ [ 'id' => 2 ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => null ], [ 'id' => 2 ], [ 'id' => 3, 'target_id' => null ] ],
		]);
	});

	it('sets both columns of DynamicClass references to null, only for the deleted class', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'dyn_refs' => [
				[ 'id' => 1, 'subject_type' => 'Target', 'subject_id' => 1 ],
				[ 'id' => 2, 'subject_type' => 'NullRef', 'subject_id' => 1 ], // same id, other class
				[ 'id' => 3, 'subject_type' => 'Target', 'subject_id' => 2 ],
			],
			'dyn_ns_refs' => [
				[ 'id' => 1, 'subject_type' => 'Tests\Models\References\Target', 'subject_id' => 1 ],
				[ 'id' => 2, 'subject_type' => 'Target', 'subject_id' => 1 ], // short name: not what use_namespace stores
			],
		]);

		Target::fetch(1)->delete();

		expectState([
			'targets' => [],
			'dyn_refs' => [ [ 'id' => 1, 'subject_type' => null, 'subject_id' => null ], [ 'id' => 2 ], [ 'id' => 3 ] ],
			'dyn_ns_refs' => [ [ 'id' => 1, 'subject_type' => null, 'subject_id' => null ], [ 'id' => 2 ] ],
		]);
	});

	it('also cleans up soft-deleted referrers', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'soft_refs' => [ [ 'id' => 1, 'target_id' => 1, 'deleted' => 1 ], [ 'id' => 2, 'target_id' => 1 ] ],
		]);

		Target::fetch(1)->delete();

		expectState([ 'targets' => [], 'soft_refs' => [ [ 'id' => 1, 'target_id' => null ], [ 'id' => 2, 'target_id' => null ] ] ]);
	});
});

describe('on_remote_delete="restrict"', function () {
	it('stops the delete while an object refers to it, changing nothing', function (string $table) {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			$table => [ [ 'id' => 1, 'target_id' => 1 ] ],
		]);
		$target = Target::fetch(1);

		expect(fn () => $target->delete())->toThrow(Exception::class);
		expectUnchanged();
		expect($target->id)->toBe(1);
		expect($target->isDeleted())->toBeFalse();
	})->with([
		'required property (the default)' => [ 'restrict_refs' ],
		'explicit restrict' => [ 'optional_restrict_refs' ],
	]);

	it('allows the delete when nothing refers to it', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ], [ 'id' => 2, 'name' => 'T2' ] ],
			'restrict_refs' => [ [ 'id' => 1, 'target_id' => 2 ] ],
		]);

		Target::fetch(1)->delete();

		expectState([ 'targets' => [ [ 'id' => 2 ] ] ]);
	});

	it('counts soft-deleted referrers too', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'optional_restrict_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'soft_refs' => [ [ 'id' => 1, 'target_id' => 1, 'deleted' => 1 ] ],
		]);

		expect(fn () => Target::fetch(1)->delete())->toThrow(Exception::class);
		expectUnchanged();
	});

	it('is checked after cascades, so an object deleted along doesn\'t stop it', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'both_refs' => [ [ 'id' => 1, 'via_cascade_id' => 1, 'via_restrict_id' => 1 ] ],
		]);

		Target::fetch(1)->delete();

		expectState([ 'targets' => [], 'both_refs' => [] ]);
	});

	it('still stops the delete for an object that isn\'t deleted along', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ], [ 'id' => 2, 'name' => 'T2' ] ],
			'both_refs' => [ [ 'id' => 1, 'via_cascade_id' => 2, 'via_restrict_id' => 1 ] ],
		]);

		expect(fn () => Target::fetch(1)->delete())->toThrow(Exception::class);
		expectUnchanged();
	});
});

describe('on_remote_delete="cascade"', function () {
	it('deletes the objects that refer to it', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ], [ 'id' => 2, 'name' => 'T2' ] ],
			'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => 2 ], [ 'id' => 3, 'target_id' => null ] ],
		]);

		Target::fetch(1)->delete();

		expectState([ 'targets' => [ [ 'id' => 2 ] ], 'cascade_refs' => [ [ 'id' => 2 ], [ 'id' => 3 ] ] ]);
	});

	it('follows chains', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => null ] ],
			'chain_refs' => [ [ 'id' => 1, 'parent_id' => 1 ], [ 'id' => 2, 'parent_id' => 2 ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
		]);

		Target::fetch(1)->delete();

		expectState([
			'targets' => [],
			'cascade_refs' => [ [ 'id' => 2 ] ],
			'chain_refs' => [ [ 'id' => 2 ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => null ] ],
		]);
	});

	it('deletes DynamicClass referrers of the deleted class only', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'dyn_cascade_refs' => [
				[ 'id' => 1, 'subject_type' => 'Target', 'subject_id' => 1 ],
				[ 'id' => 2, 'subject_type' => 'CascadeRef', 'subject_id' => 1 ],
			],
		]);

		Target::fetch(1)->delete();

		expectState([ 'targets' => [], 'dyn_cascade_refs' => [ [ 'id' => 2 ] ] ]);
	});

	it('handles a cycle by deleting each object once', function () {
		given([
			'cycle_as' => [ [ 'id' => 1, 'b_id' => 1 ], [ 'id' => 2, 'b_id' => null ] ],
			'cycle_bs' => [ [ 'id' => 1, 'a_id' => 1 ] ],
		]);

		CycleA::fetch(1)->delete();

		expectState([ 'cycle_as' => [ [ 'id' => 2 ] ], 'cycle_bs' => [] ]);
	});

	it('soft-deletes soft-delete referrers, which keep their reference', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'soft_cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ], [ 'id' => 2, 'target_id' => 1, 'deleted' => 1 ] ],
		]);

		Target::fetch(1)->delete();

		expectState([ 'targets' => [], 'soft_cascade_refs' => [ [ 'id' => 1, 'deleted' => 1 ], [ 'id' => 2 ] ] ]);
	});

	it('is undone completely when a restrict further down stops the delete', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'cascade_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'dyn_refs' => [ [ 'id' => 1, 'subject_type' => 'Target', 'subject_id' => 1 ] ],
			'restrict_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
		]);
		$target = Target::fetch(1);
		$cascaded = CascadeRef::fetch(1);
		expect($cascaded->target)->not->toBeNull();

		expect(fn () => $target->delete())->toThrow(Exception::class);

		expectUnchanged();
		// ★ BUGS-ALREADY-FIXED2 #8: a rolled-back delete left cascaded objects marked as deleted
		expect($target->isDeleted())->toBeFalse();
		expect($cascaded->isDeleted())->toBeFalse();
	});
});

describe('objects in memory', function () {
	it('still refer to the deleted object until reload()', function () {
		// docs: "Objects that are already in memory are not updated"
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'null_refs' => [ [ 'id' => 1, 'target_id' => 1 ] ],
			'dyn_refs' => [ [ 'id' => 1, 'subject_type' => 'Target', 'subject_id' => 1 ] ],
		]);
		$ref = NullRef::fetch(1);
		$dyn = DynRef::fetch(1);
		expect($ref->target)->not->toBeNull();
		expect($dyn->subject)->not->toBeNull();

		Target::fetch(1)->delete();

		expect($ref->target)->not->toBeNull();
		$ref->reload();
		$dyn->reload();
		expect($ref->target)->toBeNull();
		expect($dyn->subject)->toBeNull();
	});

	it('can\'t commit a reference to a deleted object', function () {
		given([ 'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ] ]);
		$target = Target::fetch(1);
		$target->delete();

		$ref = new NullRef();
		expect(function () use ($ref, $target) {
			$ref->target = $target;
			$ref->commit();
		})->toThrow(Exception::class);
		expectState([ 'targets' => [] ]);
	});

	it('updates a loaded referrer that was cascaded', function () {
		given([
			'targets' => [ [ 'id' => 1, 'name' => 'T1' ] ],
			'both_refs' => [ [ 'id' => 1, 'via_cascade_id' => 1, 'via_restrict_id' => null ] ],
		]);
		onlyWithCache(); // the cascade deletes the cached instance
		$both = BothRef::fetch(1);
		expect($both->viaCascade)->not->toBeNull();

		Target::fetch(1)->delete();

		expect($both->isDeleted())->toBeTrue();
	});
});
