<?php
/**
 * Turning a SureCart event into the WordPress user who paid.
 *
 * This is the hop that has never worked on the live site. Four events were
 * recorded there, all identical and all reading:
 *
 *   SureCart\Models\Purchase
 *   user_id: missing, customer: present, customer_id: present, id: present
 *
 * So the embedded customer is there and is reached, and it simply does not
 * carry user_id — SureCart puts a partial customer in the purchase payload,
 * enough to name the customer but not to name the WordPress account. Every
 * purchase since the feature shipped resolved to nobody and no licence has
 * ever been assigned automatically.
 *
 * The fix is to fetch the whole customer by ID, which is the same join
 * afristream_subscription_count() already trusts in the other direction.
 *
 * @package bluegroup-project-afristream
 */

af_test( 'the live payload shape now resolves to the customer who paid', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_live' );

	// Exactly what the site recorded: a partial customer with no user_id on it,
	// alongside the customer_id that can be used to fetch the full record.
	$purchase = new AF_Fake_SureCart_Model(
		array(
			'customer'    => new AF_Fake_SureCart_Model( array( 'id' => 'cus_live', 'email' => 'paid@example.com' ) ),
			'customer_id' => 'cus_live',
			'id'          => 'pur_1',
		)
	);

	af_assert_same( 7, afristream_user_id_from_surecart( $purchase ), 'resolved through the full customer record' );
} );

af_test( 'a payload that already names the user does not go to the API for it', function () {
	af_seed_user( 7 );
	// No customer seeded at all: if this reached the lookup it would come back
	// a WP_Error and resolve to nobody, so passing proves it never went.
	$purchase = new AF_Fake_SureCart_Model( array( 'user_id' => 7, 'customer_id' => 'cus_absent' ) );

	af_assert_same( 7, afristream_user_id_from_surecart( $purchase ), 'the direct attribute still wins' );
} );

af_test( 'an embedded customer that does carry user_id is still used as is', function () {
	af_seed_user( 9 );
	$purchase = new AF_Fake_SureCart_Model(
		array( 'customer' => new AF_Fake_SureCart_Model( array( 'user_id' => 9, 'id' => 'cus_absent' ) ) )
	);

	af_assert_same( 9, afristream_user_id_from_surecart( $purchase ), 'no fetch needed when it is already there' );
} );

af_test( 'a customer SureCart has never heard of resolves to nobody, not to a fatal', function () {
	$purchase = new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_nope' ) );

	af_assert_same( 0, afristream_user_id_from_surecart( $purchase ), 'a WP_Error is not mistaken for a record' );
} );

af_test( 'a customer with no WordPress account behind it resolves to nobody', function () {
	// Seeded against user 0 — a SureCart customer who checked out as a guest.
	af_seed_surecart_customer( 0, 'cus_guest' );
	$purchase = new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_guest' ) );

	af_assert_same( 0, afristream_user_id_from_surecart( $purchase ), 'no account to assign to' );
} );

af_test( 'SureCart being unreachable resolves to nobody rather than guessing', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_live' );
	af_surecart_fail( 'customers' );

	$purchase = new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_live' ) );

	af_assert_same( 0, afristream_user_id_from_surecart( $purchase ), 'an unreadable answer is not an answer' );
} );

af_test( 'an unresolvable event is still recorded, with the fetch having been tried', function () {
	afristream_autoassign_from_surecart( new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_nope' ) ) );

	$events = afristream_unresolved_events();
	af_assert_same( 1, count( $events ), 'the diagnostic still fires when the fetch fails too' );
} );

af_test( 'an event from before the fix stops raising the alarm once one works', function () {
	// The state the live site will be in on deploying this: four events recorded
	// while the lookup was broken, sitting in the option forever. Without this,
	// the Configurations page goes on reporting "4 SureCart events arrived
	// carrying no customer" for good — the stale warning that teaches people to
	// stop reading the page, which is the failure the ACF panel already died of.
	af_set_now( 1785024000 );
	afristream_autoassign_from_surecart( new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_nope' ) ) );
	af_assert_same( 1, count( afristream_unresolved_events_live() ), 'live while nothing has worked since' );

	// A licence goes out automatically. The old event is now history.
	af_seed_post( 10, 'alpha' );
	af_seed_user( 7 );
	af_set_now( 1785024000 + 60 );
	afristream_assign_license( 10, 7, 'auto-assign' );

	af_assert_same( 0, count( afristream_unresolved_events_live() ), 'settled by a later success' );
	af_assert_same( 1, count( afristream_unresolved_events() ), 'but not erased from the record' );
} );

af_test( 'an event after the last success is still live', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_user( 7 );
	af_set_now( 1785024000 );
	afristream_assign_license( 10, 7, 'auto-assign' );

	af_set_now( 1785024000 + 60 );
	afristream_autoassign_from_surecart( new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_nope' ) ) );

	af_assert_same( 1, count( afristream_unresolved_events_live() ), 'a new failure is not covered by an old success' );
} );
