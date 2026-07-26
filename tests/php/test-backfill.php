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

af_test( 'a licence somebody else already holds keeps its owner, and the dropped claim is reported', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_user( 9, 'carol', '2026-05-01 00:00:00' );
	af_seed_post( 10, 'alpha' );

	// An auto-assign or a webhook got here before the first admin_init did.
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 9 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 0, $report['claimed'], 'nothing is claimed over the top of a real owner' );
	af_assert_same( 9, afristream_license_owner( 10 ), 'the real owner keeps it' );
	af_assert_same( 1, count( $report['conflicts'] ), 'and the dropped claim is not silent' );
	af_assert_same( 10, $report['conflicts'][0]['license'], 'naming the licence' );
	af_assert_same( 9, $report['conflicts'][0]['kept'], 'who actually holds it, not who the old data guessed' );
	af_assert_same( array( 7 ), $report['conflicts'][0]['rejected'], 'and whose claim was dropped' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'conflict', $log[0]['event'], 'the licence history records it too' );
	af_assert_same( 9, $log[0]['user'], 'against the user who kept it' );

	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the dropped claimant mirror is corrected' );
} );

af_test( 'a mirror entry pointing at anything but a published licence is never written to', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 20, 'an order', 'publish', 'shop_order' );
	af_seed_post( 21, 'a licence taken back to draft', 'draft', 'license' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '20', '21' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 0, $report['claimed'], 'nothing claimed' );
	af_assert_same( array(), get_post_meta( 20, AFRISTREAM_LICENSE_OWNER_META ), 'no owner row on a post that is not a licence' );
	af_assert_same( array(), get_post_meta( 21, AFRISTREAM_LICENSE_OWNER_META ), 'and none on a draft' );
	af_assert_same( array(), afristream_license_log_get( 20 ), 'no history invented on the unrelated post' );
	af_assert_same( array(), afristream_license_log_get( 21 ), 'nor on the draft' );
	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'both entries are dropped from the mirror' );
} );

af_test( 'a licence claimed by two users registered at the same moment resolves on user ID', function () {
	af_seed_user( 8, 'bob', '2026-02-01 00:00:00' );
	af_seed_user( 7, 'alice', '2026-02-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 7, afristream_license_owner( 10 ), 'the lower user ID keeps it rather than whichever row came back first' );
	af_assert_same( array( 8 ), $report['conflicts'][0]['rejected'], 'and the other is reported' );
} );

af_test( 'a user whose registration date cannot be read does not beat one that can', function () {
	af_seed_user( 7, 'alice', '' );                     // never written, or wiped.
	af_seed_user( 8, 'bob', '2026-03-01 00:00:00' );
	af_seed_user( 9, 'carol', '0000-00-00 00:00:00' );  // what an older MySQL leaves behind.
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 9, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 8, afristream_license_owner( 10 ), 'the only readable registration wins, despite the higher ID' );
	af_assert_same( 8, $report['conflicts'][0]['kept'], 'and is named as the keeper' );
	af_assert_same( array( 7, 9 ), $report['conflicts'][0]['rejected'], 'the unreadable rows sort last, among themselves by ID' );
} );

af_test( 'a second run does not wipe a conflict report somebody still has to act on', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_user( 8, 'bob', '2026-03-01 00:00:00' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );
	update_user_meta( 8, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	afristream_backfill_ownership();
	$stored = afristream_ownership_conflicts();
	af_assert_same( 1, count( $stored ), 'the first run reports the clash' );

	$second = afristream_backfill_ownership();

	af_assert_same( array(), $second['conflicts'], 'the second run has nothing of its own to say' );
	af_assert_same( $stored, afristream_ownership_conflicts(), 'so the stored report is left exactly as it was' );
} );

af_test( 'the migration stands down while the lock is held, and runs on a later request', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	// Another admin_init — admin-ajax.php, the heartbeat — is already migrating.
	$token = afristream_lock_acquire();
	af_assert( false !== $token, 'the rival request holds the lock' );

	af_assert( is_wp_error( afristream_backfill_ownership() ), 'the backfill refuses to run beside it' );

	afristream_maybe_upgrade();

	af_assert_same( 0, afristream_license_owner( 10 ), 'nothing was written' );
	af_assert_same( array(), afristream_license_log_get( 10 ), 'and no duplicate history' );
	af_assert_same( 1, (int) get_option( AFRISTREAM_SCHEMA_OPTION, 1 ), 'the schema version is left alone so this runs again later' );

	afristream_lock_release( $token );
	afristream_maybe_upgrade();

	af_assert_same( 7, afristream_license_owner( 10 ), 'and the later request does the migration' );
	af_assert_same( 1, count( afristream_license_log_get( 10 ) ), 'exactly once' );
	af_assert_same( AFRISTREAM_SCHEMA_VERSION, (int) get_option( AFRISTREAM_SCHEMA_OPTION, 1 ), 'recording the version only once it has' );
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
