<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';

/** Pin entitlement without SureCart, which the harness cannot instantiate. */
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
