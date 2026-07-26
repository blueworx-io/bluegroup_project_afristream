<?php
require_once __DIR__ . '/../../includes/fields.php';

af_test( 'assigning writes the licence and rebuilds the mirror as strings', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'assign succeeds' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'owner is on the licence' );

	// ACF stored the relationship as an array of *strings*. The Connected User
	// column matches on LIKE '"10"', which only hits a serialized string.
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror holds string ids' );
} );

af_test( 'a second licence for the same user does not disturb the first', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( array( 10, 11 ), afristream_user_license_ids( 7 ), 'both held' );
	af_assert_same( array( '10', '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror has both' );
} );

af_test( 'a licence already held by someone else is refused', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'test' );
	$result = afristream_assign_license( 10, 8, 'test' );

	af_assert( is_wp_error( $result ), 'second assignment is a WP_Error' );
	af_assert_same( 'afristream_license_taken', $result->get_error_code(), 'with a specific code' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'the first holder keeps it' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'the second user gets nothing' );
} );

af_test( 'reassigning to the same user is a no-op, not an error', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'test' );
	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'idempotent' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still exactly one' );
} );

af_test( 'an expired or draft licence cannot be assigned', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'expired' );
	update_post_meta( 10, 'expiry_date', '20250101' );
	af_seed_post( 11, 'draft', 'draft' );

	af_assert( is_wp_error( afristream_assign_license( 10, 7, 'test' ) ), 'expired refused' );
	af_assert( is_wp_error( afristream_assign_license( 11, 7, 'test' ) ), 'draft refused' );
} );

af_test( 'unassigning frees the licence and updates the mirror', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( true, afristream_unassign_license( 10, 'test' ), 'unassign succeeds' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'licence is free' );
	af_assert( afristream_license_is_available( 10 ), 'and available again' );
	af_assert_same( array( 11 ), afristream_user_license_ids( 7 ), 'the other licence is untouched' );
	af_assert_same( array( '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror follows' );
} );

af_test( 'unassigning a free licence is harmless', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( false, afristream_unassign_license( 10, 'test' ), 'reports nothing to do' );
} );

af_test( 'a user with no licences has an empty mirror, not a stale one', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_unassign_license( 10, 'test' );

	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror emptied' );
} );

af_test( 'the lock serialises and reports refusal rather than proceeding', function () {
	$ran = 0;
	$out = afristream_with_lock( function () use ( &$ran ) {
		$ran++;
		// Re-entering while held must be refused, not deadlock or double-run.
		$inner = afristream_with_lock( function () use ( &$ran ) {
			$ran++;
			return 'inner';
		} );
		af_assert( is_wp_error( $inner ), 'a nested claim is refused' );
		return 'outer';
	} );

	af_assert_same( 'outer', $out, 'the outer body ran and returned' );
	af_assert_same( 1, $ran, 'the inner body never ran' );

	// The lock is released afterwards, so the next claim succeeds.
	af_assert_same( 'after', afristream_with_lock( function () { return 'after'; } ), 'lock released' );
} );

af_test( 'the mirror can be rebuilt from the licences alone', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '99' ) ); // stale nonsense

	afristream_rebuild_user_mirror( 7 );
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'rebuilt from truth' );
} );
