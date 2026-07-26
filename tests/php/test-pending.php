<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
// auto-assign.php calls afristream_affiliate_prop(), defined here — see the
// deviation note in the Task 10 report.
require_once __DIR__ . '/../../includes/affiliates.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

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
