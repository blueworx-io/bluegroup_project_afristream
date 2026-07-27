<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/licenses.php';

/**
 * Deleting a customer frees their licences. When one of those releases is
 * refused — an overlapping request holding the assignment lock, a licence that
 * changed hands mid-release — the licence is left owned by a user ID that is
 * about to stop existing. Nothing reclaims it: it is not available, it is in
 * nobody's holdings, and neither the mirror check nor the over-allocation
 * report can see it, because both start from users who exist.
 *
 * The lock is held for the whole of the delete in the tests below, which is the
 * one way to make every release refuse deterministically — the same technique
 * tests/php/test-profile-diff.php uses for a refused profile save.
 */

af_test( 'deleting a user frees their licences', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	afristream_portal_unassign_licenses_on_user_delete( 7 );

	af_assert_same( 0, afristream_license_owner( 10 ), 'back in the pool' );
	af_assert_same( 0, afristream_license_owner( 11 ), 'both of them' );
	af_assert_same( '', get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the mirror is cleared, because there is nothing left to record' );
} );

af_test( 'a licence that could not be freed keeps its record of who held it', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	afristream_with_lock(
		function () {
			afristream_portal_unassign_licenses_on_user_delete( 7 );
		}
	);

	af_assert_same( 7, afristream_license_owner( 10 ), 'the release really was refused' );
	af_assert_same(
		array( '10' ),
		get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ),
		'so the mirror is left in place — it is the last thing naming what this account held'
	);

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'conflict', $log[0]['event'], 'and the failure is written where whoever opens the licence will find it' );
	af_assert_same( 'user-deleted', $log[0]['context'], 'saying what was happening at the time' );
} );

af_test( 'a release that was merely unlucky the first time is retried', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	// A rival holds the lock when the first release looks, and lets go a moment
	// afterwards — hooked to the read of the lock option itself, which is the
	// only instant in a single-threaded test where "just too late" can be
	// staged. The first attempt is still refused, because it read a live lock;
	// the retry is what turns that from a licence lost out of circulation into
	// a licence back in stock.
	$token    = afristream_lock_acquire();
	$released = false;
	add_filter(
		'option_' . AFRISTREAM_LOCK_KEY,
		function ( $value ) use ( &$token, &$released ) {
			if ( ! $released && false !== $token ) {
				// Set first: releasing reads the option again, and this filter
				// runs on that read too.
				$released = true;
				afristream_lock_release( $token );
				$token = false;
			}
			return $value;
		}
	);

	afristream_portal_unassign_licenses_on_user_delete( 7 );

	af_assert_same( 0, afristream_license_owner( 10 ), 'the second attempt got it' );
	af_assert_same( '', get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and with nothing left held, the mirror goes' );
} );

af_test( 'a licence owned by an account that no longer exists is reported', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 10, 7, 'test' );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 999 ); // an account that was deleted.

	$orphans = afristream_orphaned_licenses();

	af_assert_same( 1, count( $orphans ), 'only the one nobody can look up' );
	af_assert_same( 11, $orphans[0]['license'], 'naming the licence' );
	af_assert_same( 999, $orphans[0]['owner'], 'and the account it is stuck on' );
} );

af_test( 'an orphan is found whatever state its post is in', function () {
	af_seed_post( 10, 'drafted-and-orphaned', 'draft' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 999 );

	af_assert_same( 1, count( afristream_orphaned_licenses() ), 'unpublishing a licence does not hide it from the report' );
} );

af_test( 'releasing an orphan puts it back in the pool and says so in its history', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 999 );

	af_assert_same( true, afristream_release_orphaned_license( 10 ), 'released' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'the licence is free' );
	af_assert( afristream_license_is_available( 10 ), 'and available again' );
	af_assert_same( array(), afristream_orphaned_licenses(), 'so it is no longer reported' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'unassigned', $log[0]['event'], 'the release is recorded' );
	af_assert_same( 'orphan-release', $log[0]['context'], 'and named for what it was' );
} );

af_test( 'the release cannot be turned on a licence somebody actually holds', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	$result = afristream_release_orphaned_license( 10 );

	af_assert( is_wp_error( $result ), 'refused' );
	af_assert_same( 'afristream_owner_exists', $result->get_error_code(), 'because the owner is a real account' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'and the customer still holds it' );
} );

af_test( 'releasing needs the capability, not just the link', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 999 );

	af_set_capabilities( array( 'manage_options' => false ) );

	$result = afristream_release_orphaned_license( 10 );

	af_assert( is_wp_error( $result ), 'refused' );
	af_assert_same( 'afristream_forbidden', $result->get_error_code(), 'on the capability, before anything is written' );
	af_assert_same( 999, afristream_license_owner( 10 ), 'nothing changed' );
} );

af_test( 'the release refuses anything that is not an owned licence', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_post( 20, 'a page', 'publish', 'page' );

	af_assert_same( 'afristream_license_free', afristream_release_orphaned_license( 10 )->get_error_code(), 'a free licence has nothing to release' );
	af_assert_same( 'afristream_not_a_license', afristream_release_orphaned_license( 20 )->get_error_code(), 'and a page is not a licence' );
	af_assert_same( 'afristream_not_a_license', afristream_release_orphaned_license( 0 )->get_error_code(), 'nor is nothing at all' );
} );

af_test( 'the Configurations page flags an orphan on the assignment row', function () {
	require_once __DIR__ . '/../../includes/configurations.php';

	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 999 );

	add_filter( 'afristream_registry', 'afristream_register_field_registry' );

	$status = null;
	foreach ( afristream_registry() as $item ) {
		if ( AFRISTREAM_LICENSE_OWNER_META === $item['handle'] ) {
			$status = $item['status'];
		}
	}

	af_assert( is_array( $status ), 'the assignment row is declared' );
	af_assert_same( 'warn', $status['state'], 'and it stops reporting itself as fine while a licence is stranded' );
} );
