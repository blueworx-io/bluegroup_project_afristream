<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
// auto-assign.php calls afristream_affiliate_prop(), defined here — see the
// deviation note in the Task 10 report.
require_once __DIR__ . '/../../includes/affiliates.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

/**
 * What auto-assign.php wired up at require time. Captured here because
 * af_run_tests() calls af_reset_store() before every test and would otherwise
 * wipe it before any test body could look — the same reason and the same
 * pattern as the snapshot in test-license-admin.php.
 */
$GLOBALS['af_autoassign_wiring_snapshot'] = array(
	'delete_user'                   => af_registered_actions( 'delete_user' ),
	'wpmu_delete_user'              => af_registered_actions( 'wpmu_delete_user' ),
	'surecart/purchase_created'     => af_registered_actions( 'surecart/purchase_created' ),
	'surecart/subscription_created' => af_registered_actions( 'surecart/subscription_created' ),
);

/**
 * Put the SureCart hooks back after af_reset_store() has wiped them.
 *
 * afristream_autoassign_status() reads whether anything is listening at all, so
 * a test about any other part of the status has to restore the wiring the
 * plugin does for itself at load.
 */
function af_register_autoassign_hooks() {
	add_action( 'surecart/purchase_created', 'afristream_autoassign_from_surecart' );
	add_action( 'surecart/subscription_created', 'afristream_autoassign_from_surecart' );
}

af_test( 'a shortfall is queued with who and how many', function () {
	af_seed_user( 7 );
	afristream_pending_set( 7, 2 );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'one entry' );
	af_assert_same( 7, $pending[0]['user'], 'the user' );
	af_assert_same( 2, $pending[0]['short'], 'and the shortfall' );
	af_assert_same( 1785024000, $pending[0]['since'], 'stamped when it happened' );
} );

af_test( 'queueing the same user again updates rather than duplicates', function () {
	afristream_pending_set( 7, 2 );
	af_set_now( 1785024000 + 600 );
	afristream_pending_set( 7, 1 );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'still one entry' );
	af_assert_same( 1, $pending[0]['short'], 'shortfall updated' );
	af_assert_same( 1785024000, $pending[0]['since'], 'but the original wait is preserved' );
} );

af_test( 'a shortfall of zero clears the entry', function () {
	afristream_pending_set( 7, 2 );
	afristream_pending_set( 7, 0 );
	af_assert_same( array(), afristream_pending_all(), 'cleared' );
} );

af_test( 'the queue comes out oldest first', function () {
	afristream_pending_set( 7, 1 );
	af_set_now( 1785024000 + 600 );
	afristream_pending_set( 8, 1 );

	$pending = afristream_pending_all();
	af_assert_same( 7, $pending[0]['user'], 'the one who has waited longest' );
	af_assert_same( 8, $pending[1]['user'], 'then the newer one' );
} );

af_test( 'draining serves the longest wait first when stock is short', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_set_entitlement( array( 7 => 1, 8 => 1 ) );

	afristream_pending_set( 7, 1 );
	af_set_now( 1785024000 + 600 );
	afristream_pending_set( 8, 1 );

	af_seed_post( 10, 'the only licence' );

	af_assert_same( 1, afristream_pending_drain(), 'one licence handed out' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'to whoever waited longest' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'the newer wait keeps waiting' );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'and stays queued' );
	af_assert_same( 8, $pending[0]['user'], 'as the only one left' );
} );

af_test( 'draining with no stock leaves the queue exactly as it was', function () {
	af_seed_user( 7 );
	af_set_entitlement( array( 7 => 1 ) );
	afristream_pending_set( 7, 1 );

	af_assert_same( 0, afristream_pending_drain(), 'nothing handed out' );
	af_assert_same( 1, count( afristream_pending_all() ), 'still queued' );
} );

af_test( 'a queued user whose subscription lapsed is dropped, not given a licence', function () {
	af_seed_user( 7 );
	af_set_entitlement( array( 7 => 0 ) );
	afristream_pending_set( 7, 1 );
	af_seed_post( 10, 'alpha' );

	af_assert_same( 0, afristream_pending_drain(), 'nothing handed out' );
	af_assert_same( array(), afristream_pending_all(), 'and they leave the queue' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'stock untouched' );
} );

af_test( 'holding more than the entitlement is reported and never revoked', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_set_entitlement( array( 7 => 1 ) );

	$over = afristream_over_allocated();
	af_assert_same( 1, count( $over ), 'one user flagged' );
	af_assert_same( 7, $over[0]['user'], 'named' );
	af_assert_same( 1, $over[0]['entitled'], 'entitlement shown' );
	af_assert_same( 2, $over[0]['held'], 'against what they hold' );
	af_assert_same( array( 11 ), $over[0]['surplus'], 'and the surplus is the most recently acquired' );

	af_assert_same( 7, afristream_license_owner( 11 ), 'and nothing was taken away' );
} );

af_test( 'a mirror that disagrees with the licences is reported', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10', '99' ) );

	af_assert_same( array( 7 ), afristream_mirror_mismatches(), 'the disagreement surfaces' );

	afristream_rebuild_user_mirror( 7 );
	af_assert_same( array(), afristream_mirror_mismatches(), 'and clears once rebuilt' );
} );

af_test( 'a SureCart object yields a user id however it names it', function () {
	af_assert_same( 7, afristream_user_id_from_surecart( (object) array( 'user_id' => 7 ) ), 'direct user_id' );
	af_assert_same( 7, afristream_user_id_from_surecart( array( 'user_id' => '7' ) ), 'array form, string value' );
	af_assert_same( 7, afristream_user_id_from_surecart( (object) array( 'customer' => (object) array( 'user_id' => 7 ) ) ), 'nested on the customer' );
	af_assert_same( 0, afristream_user_id_from_surecart( (object) array( 'nothing' => 1 ) ), 'nothing usable gives 0' );
	af_assert_same( 0, afristream_user_id_from_surecart( null ), 'null is safe' );
} );

af_test( 'the queue is not loaded on every front-end request', function () {
	afristream_pending_set( 7, 1 );
	af_assert_same( 'no', af_option_autoload( AFRISTREAM_PENDING_OPTION ), 'the queue option is written with autoload off' );
} );

af_test( 'the drain runs on a network user deletion as well as a single-site one', function () {
	$wiring = $GLOBALS['af_autoassign_wiring_snapshot'];
	af_assert( in_array( 'afristream_pending_drain', $wiring['delete_user'], true ), 'wired to delete_user' );
	af_assert( in_array( 'afristream_pending_drain', $wiring['wpmu_delete_user'], true ), 'and to wpmu_delete_user, as includes/licenses.php is' );
} );

af_test( 'a drain with no stock still drops whoever is no longer owed anything', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_set_entitlement( array( 7 => 0, 8 => 1 ) );
	afristream_pending_set( 7, 1 );
	afristream_pending_set( 8, 1 );

	// No stock is a queue's ordinary state — it is why anyone is in one — so
	// housekeeping cannot be something that only happens on the lucky runs.
	af_assert_same( 0, afristream_pending_drain(), 'there was nothing to hand out' );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'the lapsed subscriber leaves the queue anyway' );
	af_assert_same( 8, $pending[0]['user'], 'and whoever is still owed a licence keeps their place' );
} );

af_test( 'a drain drops a user who no longer exists', function () {
	afristream_pending_set( 99, 1 );

	af_assert_same( 0, afristream_pending_drain(), 'nothing handed out' );
	af_assert_same( array(), afristream_pending_all(), 'a deleted account cannot be owed a licence' );
} );

af_test( 'a drain never evicts a customer whose subscriptions could not be read', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_1' );
	af_seed_post( 10, 'alpha' );
	afristream_pending_set( 7, 1 );
	af_surecart_fail( 'subscriptions' );

	af_assert_same( 0, afristream_pending_drain(), 'nothing handed out on an answer we do not have' );
	af_assert_same( 1, count( afristream_pending_all() ), 'and they keep their place in the queue' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'stock untouched' );
} );

af_test( 'a failed lookup never takes a customer off the queue', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_pending_set( 7, 2 );
	af_surecart_fail( 'customers' );

	afristream_autoassign_for_user( 7 );

	$pending = afristream_pending_all();
	af_assert_same( 1, count( $pending ), 'still queued' );
	af_assert_same( 2, $pending[0]['short'], 'still owed exactly what they were owed' );
} );

af_test( 'nobody is reported over-allocated on an entitlement that could not be read', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_surecart_fail( 'customers' );

	// A person is meant to act on this report. Reading an unreadable entitlement
	// as zero would put every licence holder on the site into it, with their
	// whole holding named as surplus.
	af_assert_same( array(), afristream_over_allocated(), 'unknown is not an entitlement of zero' );
} );

af_test( 'the surplus named is the one most recently acquired, not the lowest ID', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	// Licence 11 was theirs first; 10 arrived an hour later. Sorting by post ID
	// would name 11 — the one they have had all along — as the mistake.
	afristream_assign_license( 11, 7, 'auto-assign' );
	af_set_now( 1785024000 + 3600 );
	afristream_assign_license( 10, 7, 'auto-assign' );

	$over = afristream_over_allocated();
	af_assert_same( 1, count( $over ), 'one user flagged' );
	af_assert_same( array( 10 ), $over[0]['surplus'], 'the most recently acquired' );
} );

af_test( 'a SureCart event naming no user leaves a trace', function () {
	afristream_autoassign_from_surecart( (object) array( 'nothing' => 1 ) );

	$events = afristream_unresolved_events();
	af_assert_same( 1, count( $events ), 'recorded rather than dropped' );
	af_assert_same( array( 'nothing' ), $events[0]['keys'], 'with the shape that arrived, so a renamed field is visible' );
	af_assert_same( 1785024000, $events[0]['time'], 'and when' );
} );

af_test( 'the status says so plainly when nothing is listening for SureCart events', function () {
	af_seed_post( 10, 'alpha' );

	// af_reset_store() has wiped what auto-assign.php registered at load, which
	// is exactly the shape of the failure being checked for: the hook names
	// being wrong on the live site.
	$status = afristream_autoassign_status();

	af_assert_same( 'off', $status['state'], 'not healthy' );
	af_assert( false !== strpos( $status['label'], 'Nothing is listening' ), 'and it names the reason' );
} );

af_test( 'free stock on its own is never reported as healthy', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_register_autoassign_hooks();

	$status = afristream_autoassign_status();

	af_assert_same( 'unknown', $status['state'], 'stock existing says nothing about assignment working' );
	af_assert( false !== strpos( $status['label'], 'cannot tell the two apart' ), 'and it admits it cannot tell' );
} );

af_test( 'a recent automatic assignment is what makes the status healthy', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 1 ) );
	af_register_autoassign_hooks();

	afristream_topup_user( 7 );
	$status = afristream_autoassign_status();

	af_assert_same( 'ok', $status['state'], 'something was actually handed out' );
	af_assert( false !== strpos( $status['label'], 'assigned automatically' ), 'and that is what it says' );
} );

af_test( 'events that could not be matched to a customer are surfaced in the status', function () {
	af_seed_post( 10, 'alpha' );
	af_register_autoassign_hooks();

	afristream_autoassign_from_surecart( (object) array( 'nothing' => 1 ) );
	$status = afristream_autoassign_status();

	af_assert_same( 'warn', $status['state'], 'a payload nobody could read is a problem, not stock news' );
} );
