<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';

af_test( 'the backfill moves usermeta assignments onto the licences', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10', '11' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 2, $report['claimed'], 'both claimed' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'first licence points at the user' );
	af_assert_same( 7, afristream_license_owner( 11 ), 'so does the second' );
	af_assert_same( array(), $report['conflicts'], 'no conflicts' );
} );

af_test( 'a licence held by two users goes to the earlier registration and is reported', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_user( 8, 'bob', '2026-03-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 7, afristream_license_owner( 10 ), 'the earlier-registered user keeps it' );
	af_assert_same( 1, count( $report['conflicts'] ), 'the clash is reported' );
	af_assert_same( 10, $report['conflicts'][0]['license'], 'naming the licence' );
	af_assert_same( 7, $report['conflicts'][0]['kept'], 'and who kept it' );
	af_assert_same( array( 8 ), $report['conflicts'][0]['rejected'], 'and who lost it' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'conflict', $log[0]['event'], 'and it is on the licence history, not just a report' );
} );

af_test( 'the loser of a conflict does not silently keep it in their mirror', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_user( 8, 'bob', '2026-03-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_backfill_ownership();

	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'winner keeps it' );
	af_assert_same( array(), get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'loser mirror is corrected' );
} );

af_test( 'a mirror pointing at a licence that no longer exists is dropped', function () {
	af_seed_user( 7, 'alice' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '999' ) );

	$report = afristream_backfill_ownership();
	af_assert_same( 0, $report['claimed'], 'nothing claimed' );
	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'the dangling reference is cleared' );
} );

af_test( 'running the backfill twice changes nothing the second time', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_backfill_ownership();
	$second = afristream_backfill_ownership();

	af_assert_same( 0, $second['claimed'], 'nothing left to claim' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'ownership intact' );
	af_assert_same( 1, count( afristream_license_log_get( 10 ) ), 'and no duplicate log entry' );
} );

af_test( 'the upgrade runs once and then stands down', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_maybe_upgrade();
	af_assert_same( AFRISTREAM_SCHEMA_VERSION, (int) get_option( AFRISTREAM_SCHEMA_OPTION ), 'version recorded' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'backfilled' );

	// A second call must not re-run: unassign and confirm nothing puts it back.
	afristream_unassign_license( 10, 'test' );
	afristream_maybe_upgrade();
	af_assert_same( 0, afristream_license_owner( 10 ), 'the upgrade did not run again' );
} );

af_test( 'a licence nobody ever held leaves the backfill with an explicit owner row, not no row at all', function () {
	af_seed_post( 10, 'alpha' );

	afristream_backfill_ownership();

	af_assert_same( '0', get_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, true ), 'the row exists and reads free, ready for afristream_claim_license_row() to match against' );
} );
