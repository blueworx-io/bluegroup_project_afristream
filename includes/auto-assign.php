<?php
/**
 * Giving licences to customers who have paid for them.
 *
 * A user is entitled to one licence per active SureCart subscription. Not per
 * purchase and not by amount: prices change, and an entitlement derived from
 * money would quietly change with them.
 *
 * Everything here tops up rather than grants. On each event it asks how many
 * the user should have, counts how many they do have, and closes the gap — so
 * running it twice, or a webhook arriving twice, hands out nothing the second
 * time. That property is what makes it safe to hook to more than one event.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many active subscriptions this user has.
 *
 * SureCart joins to WordPress through the customer record, so this is two
 * lookups: the customer for the user, then their subscriptions. Guarded with
 * class_exists() in the same style as includes/affiliates.php, so the feature
 * degrades to "assigns nothing" rather than fatally when SureCart is absent.
 *
 * @param int $user_id User ID.
 * @return int
 */
function afristream_subscription_count( $user_id ) {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) || ! class_exists( '\SureCart\Models\Customer' ) ) {
		return 0;
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user || empty( $user->user_email ) ) {
		return 0;
	}

	$customers = \SureCart\Models\Customer::where( array( 'user_ids' => array( (int) $user_id ) ) )->get();
	if ( is_wp_error( $customers ) || empty( $customers ) ) {
		return 0;
	}

	$customer_ids = array();
	foreach ( (array) $customers as $customer ) {
		$id = afristream_affiliate_prop( $customer, 'id', '' );
		if ( $id ) {
			$customer_ids[] = $id;
		}
	}

	if ( empty( $customer_ids ) ) {
		return 0;
	}

	$subscriptions = \SureCart\Models\Subscription::where(
		array(
			'customer_ids' => $customer_ids,
			'status'       => array( 'active', 'trialing' ),
		)
	)->get();

	if ( is_wp_error( $subscriptions ) || empty( $subscriptions ) ) {
		return 0;
	}

	// Confirm the status rather than trusting the API's filter — the same
	// caution afristream_affiliate_lookup() applies to its own query.
	$count = 0;
	foreach ( (array) $subscriptions as $subscription ) {
		$status = (string) afristream_affiliate_prop( $subscription, 'status', '' );
		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			$count++;
		}
	}

	return $count;
}

/**
 * How many licences a user should hold.
 *
 * Filterable so the count can be tested without SureCart, and so a future
 * arrangement — a staff account, a bundled plan — can be expressed without
 * changing the allocation logic.
 *
 * @param int $user_id User ID.
 * @return int
 */
function afristream_entitlement( $user_id ) {
	$count = afristream_subscription_count( $user_id );
	return max( 0, (int) apply_filters( 'afristream_entitlement', $count, (int) $user_id ) );
}

/**
 * Bring a user up to their entitlement, as far as stock allows.
 *
 * @param int    $user_id User ID.
 * @param string $context Where the call came from, for the log.
 * @return array{assigned:int[],short:int,entitled:int,held:int}
 */
function afristream_topup_user( $user_id, $context = 'auto-assign' ) {
	$user_id  = (int) $user_id;
	$entitled = afristream_entitlement( $user_id );
	$held     = count( afristream_user_license_ids( $user_id ) );
	$assigned = array();

	$wanted = $entitled - $held;
	if ( $wanted <= 0 ) {
		return array(
			'assigned' => array(),
			'short'    => 0,
			'entitled' => $entitled,
			'held'     => $held,
		);
	}

	// Ask for more candidates than needed: another request may claim one between
	// this list being built and the assignment being attempted, and a refusal
	// should cost a retry rather than the whole top-up.
	foreach ( afristream_available_licenses( $wanted + 3 ) as $license_id ) {
		if ( count( $assigned ) >= $wanted ) {
			break;
		}
		if ( true === afristream_assign_license( $license_id, $user_id, $context ) ) {
			$assigned[] = $license_id;
		}
	}

	$held += count( $assigned );

	return array(
		'assigned' => $assigned,
		'short'    => max( 0, $entitled - $held ),
		'entitled' => $entitled,
		'held'     => $held,
	);
}
