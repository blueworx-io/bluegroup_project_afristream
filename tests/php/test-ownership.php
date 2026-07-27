<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

/**
 * Ownership and availability are two questions, and a licence's post status
 * answers only one of them. A drafted licence must never be handed to anybody,
 * and must never stop counting as held by whoever it already belongs to —
 * because the count of what a customer holds is what the top-up compares
 * against their entitlement, and a licence that drops out of that count is one
 * the next SureCart event replaces.
 */

af_test( 'a licence taken to draft still belongs to the customer holding it', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	af_seed_post( 10, 'alpha', 'draft' ); // an administrator unpublishes it.

	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still theirs' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'the licence still names them' );
	af_assert( ! afristream_license_is_available( 10 ), 'but it is not stock anybody can be given' );
	af_assert_same( array(), afristream_available_licenses(), 'and it is nowhere in the available list' );
} );

af_test( 'a licence in the trash still belongs to the customer holding it', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	af_seed_post( 10, 'alpha', 'trash' );

	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'trashing the post does not un-sell the licence' );
	af_assert( ! afristream_license_is_available( 10 ), 'and it certainly is not available' );
} );

af_test( 'drafting an assigned licence does not earn the customer a second one', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	afristream_autoassign_for_user( 7 );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'one subscription, one licence' );

	// The licence they hold is unpublished. Nothing about their entitlement
	// changed, so a second SureCart event must still find them fully served.
	af_seed_post( 10, 'alpha', 'draft' );

	afristream_autoassign_for_user( 7 );

	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still exactly one — the drafted licence was not treated as gone' );
	af_assert_same( 0, afristream_license_owner( 11 ), 'so the spare stock was left alone' );
	af_assert_same( array(), afristream_pending_all(), 'and they are not queued as owed anything' );
} );

af_test( 'a drafted licence still counts against an over-allocated customer', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta', 'draft' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_set_entitlement( array( 7 => 1 ) );

	$over = afristream_over_allocated();

	af_assert_same( 1, count( $over ), 'the customer is reported' );
	af_assert_same( 2, $over[0]['held'], 'holding both, the unpublished one included' );
} );

af_test( 'a drafted licence is counted on the page rather than vanishing from it', function () {
	require_once __DIR__ . '/../../includes/configurations.php';

	af_seed_user( 7 );
	af_seed_post( 10, 'free' );
	af_seed_post( 11, 'held-but-drafted', 'draft' );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_seed_post( 12, 'drafted-and-free', 'draft' );

	$stock = afristream_license_stock();

	af_assert_same( 3, $stock['total'], 'every licence, whatever state its post is in' );
	af_assert_same( 1, $stock['available'], 'only the published free one can be handed out' );
	af_assert_same( 1, $stock['assigned'], 'the drafted one someone holds still counts as assigned' );
	af_assert_same( 1, $stock['unpublished'], 'and the drafted free one is named as what it is' );
	af_assert_same( 0, $stock['expired'], 'not miscounted as expired, which it is not' );
} );

af_test( 'the reverse lookup is one indexed query, not a walk over every licence', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_seed_post( 12, 'gamma' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 8 );

	$before = count( af_get_posts_queries() );
	$held   = afristream_user_license_ids( 7 );
	$issued = array_slice( af_get_posts_queries(), $before );

	af_assert_same( array( 10 ), $held, 'their licence, and nobody else\'s' );
	af_assert_same( 1, count( $issued ), 'asked for once' );

	// The shape is the point: a meta_query on the owner key is a query the
	// database can answer from an index. The version this replaced listed every
	// licence and then read each one's owner meta separately, which returns the
	// same answer and does it on every Users-list row, every portal load and
	// once per candidate during a top-up.
	$query = $issued[0];
	af_assert( isset( $query['meta_query'][0]['key'] ), 'it carries a meta_query' );
	af_assert_same( AFRISTREAM_LICENSE_OWNER_META, $query['meta_query'][0]['key'], 'on the owner key' );
	af_assert_same( '7', $query['meta_query'][0]['value'], 'naming this user' );
	af_assert_same( 'ids', $query['fields'], 'and asks only for IDs' );
} );

af_test( 'the reverse lookup finds an owner the backfill wrote as an integer', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	// afristream_claim_license_row() writes the owner as a string of digits; the
	// migration writes it with update_post_meta() and an int. Both land in the
	// same meta_value column as '7', so both have to be found.
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );

	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'an integer owner row matches too' );
} );

af_test( 'a released licence is not read as belonging to nobody in particular', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_unassign_license( 10, 'test' );

	// Releasing writes '0' rather than deleting the row, so the query must not
	// hand a licence back to user 0 — or to anyone else.
	af_assert_same( array(), afristream_user_license_ids( 7 ), 'no longer theirs' );
	af_assert_same( array(), afristream_user_license_ids( 0 ), 'and a zero user holds nothing' );
	af_assert( afristream_license_is_available( 10 ), 'it is simply back in stock' );
} );
