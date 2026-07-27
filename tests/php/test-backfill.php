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

af_test( 'a mirror entry pointing at a post that was never a licence is silently dropped', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 20, 'an order', 'publish', 'shop_order' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '20' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 0, $report['claimed'], 'nothing claimed' );
	af_assert_same( array(), $report['conflicts'], 'nothing to report either — it was never a licence' );
	af_assert_same( array(), get_post_meta( 20, AFRISTREAM_LICENSE_OWNER_META ), 'no owner row on a post that is not a licence' );
	af_assert_same( array(), afristream_license_log_get( 20 ), 'no history invented on the unrelated post' );
	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'the entry is dropped from the mirror' );
} );

af_test( 'a licence taken back to draft keeps its owner meta instead of losing the assignment', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_post( 21, 'a licence taken back to draft', 'draft', 'license' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '21' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 7, afristream_license_owner( 21 ), 'the owner meta is written even though the post is a draft' );
	af_assert_same( 1, count( $report['conflicts'] ), 'a human is told about it' );
	af_assert_same( 21, $report['conflicts'][0]['license'], 'naming the licence' );
	af_assert_same( 7, $report['conflicts'][0]['kept'], 'and who it belongs to' );
	af_assert_same( array(), $report['conflicts'][0]['rejected'], 'nobody else claimed it — this is not a two-user clash' );
	af_assert_same( 'draft', $report['conflicts'][0]['status'], 'and why it was flagged' );

	$log = afristream_license_log_get( 21 );
	af_assert_same( 2, count( $log ), 'the draft licence gets an assigned entry and a flag explaining why' );
	af_assert_same( 'conflict', $log[0]['event'], 'flagged the same way a genuine clash would be, newest first' );
	af_assert_same( 7, $log[0]['user'], 'against the user whose assignment was preserved' );
	af_assert_same( 'assigned', $log[1]['event'], 'under the assignment it explains' );

	af_assert_same( array( '21' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the rebuilt mirror keeps it — a draft licence is unusable, not unowned' );
} );

af_test( 'a trashed licence keeps its owner meta instead of losing the assignment', function () {
	af_seed_user( 7, 'alice', '2026-01-01 00:00:00' );
	af_seed_post( 22, 'a licence sent to trash', 'trash', 'license' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '22' ) );

	$report = afristream_backfill_ownership();

	af_assert_same( 7, afristream_license_owner( 22 ), 'the owner meta is written even though the post is trashed' );
	af_assert_same( 1, count( $report['conflicts'] ), 'a human is told about it' );
	af_assert_same( 22, $report['conflicts'][0]['license'], 'naming the licence' );
	af_assert_same( 7, $report['conflicts'][0]['kept'], 'and who it belongs to' );
	af_assert_same( array(), $report['conflicts'][0]['rejected'], 'nobody else claimed it — this is not a two-user clash' );
	af_assert_same( 'trash', $report['conflicts'][0]['status'], 'and why it was flagged' );

	$log = afristream_license_log_get( 22 );
	af_assert_same( 2, count( $log ), 'the trashed licence gets an assigned entry and a flag explaining why' );
	af_assert_same( 'conflict', $log[0]['event'], 'flagged the same way a genuine clash would be, newest first' );
	af_assert_same( 7, $log[0]['user'], 'against the user whose assignment was preserved' );
	af_assert_same( 'assigned', $log[1]['event'], 'under the assignment it explains' );

	af_assert_same( array( '22' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the rebuilt mirror keeps it — a trashed licence is unusable, not unowned' );
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

/**
 * admin_init, which the migration hangs off, is not the administrator-only
 * moment its name suggests: it fires on admin-ajax.php, and that endpoint
 * serves logged-out requests too. The lock inside the backfill means an
 * anonymous trigger was never a correctness problem — but who may start a
 * one-way migration is a separate question from whether it is safe once
 * started, and the answer should not be "anybody at all".
 */

af_test( 'an anonymous request cannot set the migration running', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	// admin-ajax.php with nobody logged in: no manage_options, and an AJAX
	// request besides.
	af_set_capabilities( array() );
	af_set_doing_ajax( true );

	afristream_maybe_upgrade();

	af_assert_same( 0, afristream_license_owner( 10 ), 'nothing was migrated' );
	af_assert_same( 1, (int) get_option( AFRISTREAM_SCHEMA_OPTION, 1 ), 'and the schema version is untouched, so a real admin page load still will' );
} );

af_test( 'a logged-in visitor without manage_options cannot either', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	af_set_capabilities( array( 'read' => true ) );

	afristream_maybe_upgrade();

	af_assert_same( 0, afristream_license_owner( 10 ), 'a subscriber reaching admin-ajax migrates nothing' );
} );

af_test( 'the heartbeat does not run the migration, an admin page load does', function () {
	af_seed_user( 7, 'alice' );
	af_seed_post( 10, 'alpha' );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	af_set_capabilities( array( 'manage_options' => true ) );

	// The same administrator, but on admin-ajax.php — the heartbeat firing in a
	// background tab. It waits for a real page load rather than starting a
	// migration nobody is watching.
	af_set_doing_ajax( true );
	afristream_maybe_upgrade();
	af_assert_same( 0, afristream_license_owner( 10 ), 'not on the heartbeat' );

	af_set_doing_ajax( false );
	afristream_maybe_upgrade();
	af_assert_same( 7, afristream_license_owner( 10 ), 'and then on the page load' );
} );

af_test( 'a licence nobody ever held leaves the backfill with an explicit owner row, not no row at all', function () {
	af_seed_post( 10, 'alpha' );

	afristream_backfill_ownership();

	af_assert_same( '0', get_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, true ), 'the row exists and reads free, ready for afristream_claim_license_row() to match against' );
} );
