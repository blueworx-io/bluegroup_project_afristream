<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
// afristream_subscription_count() and afristream_user_id_from_surecart() both
// call afristream_affiliate_prop(), which lives here.
require_once __DIR__ . '/../../includes/affiliates.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

/**
 * Pin an entitlement per user, leaving anyone not named to whatever the
 * SureCart lookup answers.
 *
 * The harness does have SureCart stand-ins now, so entitlement can be driven
 * through them instead; this stays because most of these tests are about the
 * allocation arithmetic, and seeding a customer and subscriptions to say
 * "this user is owed two" would bury that in setup.
 */
function af_set_entitlement( $map ) {
	add_filter(
		'afristream_entitlement',
		function ( $value, $user_id ) use ( $map ) {
			return isset( $map[ $user_id ] ) ? $map[ $user_id ] : $value;
		},
		10,
		2
	);
}

af_test( 'a user with one subscription gets one licence', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	$result = afristream_topup_user( 7 );

	af_assert_same( 1, count( $result['assigned'] ), 'one licence handed out' );
	af_assert_same( 0, $result['short'], 'nothing outstanding' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'the first available one' );
} );

af_test( 'two subscriptions get two licences', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_seed_post( 12, 'gamma' );
	af_set_entitlement( array( 7 => 2 ) );

	afristream_topup_user( 7 );
	af_assert_same( array( 10, 11 ), afristream_user_license_ids( 7 ), 'exactly two' );
} );

af_test( 'running the top-up twice assigns nothing the second time', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	afristream_topup_user( 7 );
	$second = afristream_topup_user( 7 );

	af_assert_same( array(), $second['assigned'], 'a replayed webhook hands out nothing' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still exactly one' );
} );

af_test( 'a user already holding their entitlement is left alone', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'nothing assigned' );
	af_assert_same( 0, afristream_license_owner( 11 ), 'the spare stays free' );
} );

af_test( 'a partial top-up assigns what it can and reports the shortfall', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 3 ) );

	$result = afristream_topup_user( 7 );

	af_assert_same( 1, count( $result['assigned'] ), 'the one available licence is used' );
	af_assert_same( 2, $result['short'], 'and the gap is reported, not swallowed' );
	af_assert_same( 3, $result['entitled'], 'entitlement reported' );
	af_assert_same( 1, $result['held'], 'and what they ended up with' );
} );

af_test( 'no stock at all assigns nothing and reports the whole entitlement short', function () {
	af_seed_user( 7 );
	af_set_entitlement( array( 7 => 2 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'nothing to give' );
	af_assert_same( 2, $result['short'], 'both outstanding' );
} );

af_test( 'expired stock is not counted as available', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'expired' );
	update_post_meta( 10, 'expiry_date', '20250101' );
	af_set_entitlement( array( 7 => 1 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'an expired licence is not stock' );
	af_assert_same( 1, $result['short'], 'reported short instead' );
} );

af_test( 'someone who has bought nothing is entitled to nothing', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 0 ) );

	$result = afristream_topup_user( 7 );
	af_assert_same( array(), $result['assigned'], 'no subscription, no licence' );
	af_assert_same( 0, $result['short'], 'and not recorded as short' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'stock untouched' );
} );

af_test( 'the top-up logs how the licence was given out', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_set_entitlement( array( 7 => 1 ) );

	afristream_topup_user( 7 );
	$log = afristream_license_log_get( 10 );
	af_assert_same( 'assigned', $log[0]['event'], 'logged' );
	af_assert_same( 'auto-assign', $log[0]['context'], 'and attributed to the automation' );
} );

af_test( 'a refused lock abandons the claim rather than taking a second licence', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_set_entitlement( array( 7 => 1 ) );

	// A second webhook for this same customer holds the global lock and is
	// part-way through claiming licence 10: it has not written the owner row
	// yet, so this request still reads the customer as holding nothing, and
	// re-counting alone cannot see it coming. The filter stands that rival down
	// after it has refused this request once, which is the only reason the next
	// candidate is reachable at all — without it a permanent lock would refuse
	// every candidate and prove nothing.
	add_option( AFRISTREAM_LOCK_KEY, array( 'token' => 'rival', 'expires' => time() + 60 ) );
	$released = false;
	add_filter(
		'option_' . AFRISTREAM_LOCK_KEY,
		function ( $value ) use ( &$released ) {
			if ( ! $released && ! empty( $value ) ) {
				$released = true;
				delete_option( AFRISTREAM_LOCK_KEY );
			}
			return $value;
		}
	);

	$result = afristream_topup_user( 7 );

	af_assert_same( array(), $result['assigned'], 'the refusal costs the slot, it does not move to another licence' );
	af_assert_same( 1, $result['short'], 'and is reported short so the queue picks it up' );

	// The rival now lands the claim it was always going to land.
	afristream_assign_license( 10, 7, 'auto-assign' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'one subscription, one licence' );
} );

af_test( 'the top-up re-counts what the customer holds before every claim', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	af_seed_post( 12, 'gamma' );
	af_set_entitlement( array( 7 => 2 ) );

	// A second request for the same customer lands its own claim on licence 12
	// the instant this one finishes with licence 10. A loop counting only what
	// it has handed out itself would never see it and would give the customer a
	// third licence for two subscriptions.
	add_action(
		'af_wpdb_after_query',
		function ( $post_id, $meta_key, $from, $to, $changed ) {
			if ( 10 === (int) $post_id && $changed ) {
				update_post_meta( 12, AFRISTREAM_LICENSE_OWNER_META, 7 );
			}
		},
		10,
		5
	);

	$result = afristream_topup_user( 7 );

	af_assert_same( array( 10 ), $result['assigned'], 'only the one this request claimed' );
	af_assert_same( array( 10, 12 ), afristream_user_license_ids( 7 ), 'two subscriptions, two licences' );
	af_assert_same( 0, afristream_license_owner( 11 ), 'and the spare stays in stock' );
} );

af_test( 'an entitlement that cannot be read assigns nothing and reports nothing', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_surecart_fail( 'customers' );

	$result = afristream_topup_user( 7 );

	af_assert_same( true, $result['unknown'], 'the caller is told the answer is unknown' );
	af_assert_same( null, $result['entitled'], 'rather than being handed a zero it would act on' );
	af_assert_same( array(), $result['assigned'], 'nothing is given out on a guess' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'stock untouched' );
} );

af_test( 'a nonsense entitlement filter never produces a free licence', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	// Each filter below is appended and returns a constant, so the most recently
	// added is the answer — that is what lets one test walk three bad values.
	add_filter( 'afristream_entitlement', function () { return -5; }, 10, 2 );
	af_assert_same( 0, afristream_entitlement( 7 ), 'a negative entitlement is floored, not subtracted from' );
	af_assert_same( array(), afristream_topup_user( 7 )['assigned'], 'and hands out nothing' );

	add_filter( 'afristream_entitlement', function () { return 'plenty'; }, 10, 2 );
	af_assert_same( null, afristream_entitlement( 7 ), 'a string is unknown, not (int) 0 and not (int) 1' );
	af_assert_same( array(), afristream_topup_user( 7 )['assigned'], 'and hands out nothing' );

	add_filter( 'afristream_entitlement', function () { return array( 1, 2 ); }, 10, 2 );
	af_assert_same( null, afristream_entitlement( 7 ), 'an array is unknown too' );
	af_assert_same( array(), afristream_topup_user( 7 )['assigned'], 'and hands out nothing' );

	af_assert_same( 0, afristream_license_owner( 10 ), 'the licence was never touched' );
} );

af_test( 'two active subscriptions count as two', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_1' );
	af_seed_surecart_subscription( 'cus_1', 'active' );
	af_seed_surecart_subscription( 'cus_1', 'active' );

	af_assert_same( 2, afristream_subscription_count( 7 ), 'one licence owed per active subscription' );
	af_assert_same( 2, afristream_entitlement( 7 ), 'and that is the entitlement' );
} );

af_test( 'only active and trialing subscriptions are counted', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_1' );
	af_seed_surecart_subscription( 'cus_1', 'active' );
	af_seed_surecart_subscription( 'cus_1', 'trialing' );
	af_seed_surecart_subscription( 'cus_1', 'canceled' );
	af_seed_surecart_subscription( 'cus_1', 'past_due' );

	// The stand-in deliberately ignores the status filter passed to the query,
	// so this only passes if the re-check in PHP is doing the work.
	af_assert_same( 2, afristream_subscription_count( 7 ), 'a cancelled subscription is not a licence' );
} );

af_test( 'another customer\'s subscriptions are not counted as this one\'s', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_surecart_customer( 7, 'cus_1' );
	af_seed_surecart_customer( 8, 'cus_2' );
	af_seed_surecart_subscription( 'cus_2', 'active' );

	af_assert_same( 0, afristream_subscription_count( 7 ), 'nothing of their own' );
	af_assert_same( 1, afristream_subscription_count( 8 ), 'and the subscription belongs to whoever bought it' );
} );

af_test( 'a customer SureCart has never heard of is owed nothing, not unknown', function () {
	af_seed_user( 7 );
	af_assert_same( 0, afristream_subscription_count( 7 ), 'an empty answer that is not an error is a real answer' );
} );

af_test( 'a customer with no subscriptions is owed nothing, not unknown', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_1' );
	af_assert_same( 0, afristream_subscription_count( 7 ), 'they exist in SureCart and have bought nothing' );
} );

af_test( 'an error from either SureCart query is unknown, never zero', function () {
	af_seed_user( 7 );
	af_seed_surecart_customer( 7, 'cus_1' );
	af_seed_surecart_subscription( 'cus_1', 'active' );

	af_surecart_fail( 'customers' );
	af_assert_same( null, afristream_subscription_count( 7 ), 'the customer query failing is not an absence of customers' );

	af_surecart_fail( 'customers', false );
	af_surecart_fail( 'subscriptions' );
	af_assert_same( null, afristream_subscription_count( 7 ), 'nor is the subscription query failing an absence of subscriptions' );

	af_surecart_fail( 'subscriptions', false );
	af_assert_same( 1, afristream_subscription_count( 7 ), 'and the real answer comes back once it can be read' );
} );

af_test( 'a user who no longer exists cannot be asked about', function () {
	af_assert_same( null, afristream_subscription_count( 99 ), 'no account, nothing established' );
	af_assert_same( null, afristream_subscription_count( 0 ), 'and nor for no user at all' );
} );
