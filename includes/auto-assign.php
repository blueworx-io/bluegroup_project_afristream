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

/** Users owed a licence that stock could not cover. */
define( 'AFRISTREAM_PENDING_OPTION', 'afristream_pending_licenses' );

/**
 * Find the WordPress user behind a SureCart object.
 *
 * SureCart hands different shapes to different events — sometimes the purchase
 * carries user_id directly, sometimes only its customer does — so this looks in
 * both places rather than assuming one.
 *
 * @param mixed $object Purchase, subscription, model, array or null.
 * @return int User ID, or 0 when there is nothing usable.
 */
function afristream_user_id_from_surecart( $object ) {
	if ( empty( $object ) ) {
		return 0;
	}

	$direct = (int) afristream_affiliate_prop( $object, 'user_id', 0 );
	if ( $direct ) {
		return $direct;
	}

	$customer = afristream_affiliate_prop( $object, 'customer', null );
	if ( ! empty( $customer ) ) {
		return (int) afristream_affiliate_prop( $customer, 'user_id', 0 );
	}

	return 0;
}

/**
 * Everyone waiting for a licence, longest wait first.
 *
 * @return array<int,array{user:int,short:int,since:int}>
 */
function afristream_pending_all() {
	$stored = get_option( AFRISTREAM_PENDING_OPTION, array() );
	if ( ! is_array( $stored ) || empty( $stored ) ) {
		return array();
	}

	$pending = array();
	foreach ( $stored as $entry ) {
		if ( empty( $entry['user'] ) || empty( $entry['short'] ) ) {
			continue;
		}
		$pending[] = array(
			'user'  => (int) $entry['user'],
			'short' => (int) $entry['short'],
			'since' => (int) ( isset( $entry['since'] ) ? $entry['since'] : 0 ),
		);
	}

	usort(
		$pending,
		function ( $a, $b ) {
			if ( $a['since'] !== $b['since'] ) {
				return $a['since'] <=> $b['since'];
			}
			return $a['user'] <=> $b['user'];
		}
	);

	return $pending;
}

/**
 * Record, update or clear what a user is owed.
 *
 * An existing entry keeps its original timestamp when the shortfall changes, so
 * partially filling someone's order does not send them to the back of the queue.
 *
 * @param int $user_id User ID.
 * @param int $short   Licences still owed; 0 removes them from the queue.
 * @return void
 */
function afristream_pending_set( $user_id, $short ) {
	$user_id = (int) $user_id;
	$short   = max( 0, (int) $short );
	if ( ! $user_id ) {
		return;
	}

	$pending = afristream_pending_all();
	$out     = array();
	$since   = (int) current_time( 'timestamp' );

	foreach ( $pending as $entry ) {
		if ( $entry['user'] === $user_id ) {
			$since = $entry['since'] ? $entry['since'] : $since;
			continue;
		}
		$out[] = $entry;
	}

	if ( $short > 0 ) {
		$out[] = array(
			'user'  => $user_id,
			'short' => $short,
			'since' => $since,
		);
	}

	update_option( AFRISTREAM_PENDING_OPTION, $out );
}

/**
 * Hand out whatever stock exists to whoever has waited longest.
 *
 * Each user's entitlement is re-checked rather than trusting the queued number:
 * a subscription may have lapsed while they waited, and giving a licence to
 * someone who has since cancelled would be worse than the original shortage.
 *
 * @return int Licences assigned.
 */
function afristream_pending_drain() {
	$assigned = 0;

	foreach ( afristream_pending_all() as $entry ) {
		if ( empty( afristream_available_licenses( 1 ) ) ) {
			break;
		}

		$result = afristream_topup_user( $entry['user'], 'auto-assign-queued' );
		$assigned += count( $result['assigned'] );
		afristream_pending_set( $entry['user'], $result['short'] );
	}

	return $assigned;
}

/**
 * Bring a user up to date, queueing whatever stock could not cover.
 *
 * @param int    $user_id User ID.
 * @param string $context For the log.
 * @return void
 */
function afristream_autoassign_for_user( $user_id, $context = 'auto-assign' ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}

	$result = afristream_topup_user( $user_id, $context );
	afristream_pending_set( $user_id, $result['short'] );
}

/**
 * React to a SureCart purchase or subscription.
 *
 * Both events funnel here because either can be the first moment a customer is
 * genuinely paid-up, and the top-up is idempotent so being told twice costs
 * nothing.
 *
 * @param mixed $object The SureCart model the event carried.
 */
function afristream_autoassign_from_surecart( $object ) {
	$user_id = afristream_user_id_from_surecart( $object );
	if ( $user_id ) {
		afristream_autoassign_for_user( $user_id );
	}
}
add_action( 'surecart/purchase_created', 'afristream_autoassign_from_surecart' );
add_action( 'surecart/subscription_created', 'afristream_autoassign_from_surecart' );

/**
 * Serve the queue whenever stock appears.
 *
 * @param int $post_id Licence that was published or updated.
 */
function afristream_drain_on_license_change( $post_id ) {
	if ( 'license' !== get_post_type( $post_id ) ) {
		return;
	}
	afristream_pending_drain();
}
add_action( 'save_post_license', 'afristream_drain_on_license_change', 20 );

// includes/licenses.php frees this user's licences back to the pool on
// delete_user at the default priority (10). That has to land first, or the
// drain triggered here would run against stock that has not been released
// yet — so this is deliberately pinned to a later priority.
add_action( 'delete_user', 'afristream_pending_drain', 20 );

/**
 * Users holding more licences than they are entitled to.
 *
 * Reported, never acted on. A lapsed subscription is often a failed card that
 * recovers within days, and taking a paying-then-briefly-lapsed customer's
 * access away automatically is a decision that belongs to a person.
 *
 * The surplus named is the most recently acquired, since that is the one most
 * likely to be the mistake.
 *
 * @return array<int,array{user:int,entitled:int,held:int,surplus:int[]}>
 */
function afristream_over_allocated() {
	$over = array();

	$holders = array();
	foreach ( afristream_all_license_ids() as $license_id ) {
		$owner = afristream_license_owner( $license_id );
		if ( $owner ) {
			$holders[ $owner ][] = $license_id;
		}
	}

	foreach ( $holders as $user_id => $license_ids ) {
		$entitled = afristream_entitlement( $user_id );
		$held     = count( $license_ids );

		if ( $held <= $entitled ) {
			continue;
		}

		sort( $license_ids );
		$over[] = array(
			'user'     => (int) $user_id,
			'entitled' => $entitled,
			'held'     => $held,
			'surplus'  => array_values( array_slice( $license_ids, $entitled ) ),
		);
	}

	usort(
		$over,
		function ( $a, $b ) {
			return $a['user'] <=> $b['user'];
		}
	);

	return $over;
}

/**
 * Users whose usermeta mirror disagrees with the licences pointing at them.
 *
 * Should always be empty. When it is not, something wrote the mirror directly
 * instead of going through the assignment functions — worth knowing about
 * rather than discovering through a customer seeing the wrong credentials.
 *
 * @return int[] User IDs.
 */
function afristream_mirror_mismatches() {
	$mismatched = array();

	foreach ( get_users( array( 'fields' => array( 'ID' ), 'number' => -1 ) ) as $user ) {
		$user_id = (int) $user->ID;
		$truth   = afristream_user_license_ids( $user_id );
		$mirror  = afristream_user_mirror_ids( $user_id );

		if ( empty( $truth ) && empty( $mirror ) ) {
			continue;
		}
		if ( $truth !== $mirror ) {
			$mismatched[] = $user_id;
		}
	}

	sort( $mismatched );
	return $mismatched;
}

/**
 * A one-line health summary for the Configurations page.
 *
 * @return array{state:string,label:string}
 */
function afristream_autoassign_status() {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) ) {
		return array(
			'state' => 'off',
			'label' => __( 'SureCart inactive — nothing is assigned automatically', 'bluegroup-project-afristream' ),
		);
	}

	$pending   = afristream_pending_all();
	$available = count( afristream_available_licenses() );

	if ( ! empty( $pending ) ) {
		return array(
			'state' => 'warn',
			/* translators: %d: number of users waiting. */
			'label' => sprintf( _n( '%d user awaiting a licence', '%d users awaiting a licence', count( $pending ), 'bluegroup-project-afristream' ), count( $pending ) ),
		);
	}

	return array(
		'state' => 'ok',
		/* translators: %d: number of free licences. */
		'label' => sprintf( _n( 'Active — %d licence available', 'Active — %d licences available', $available, 'bluegroup-project-afristream' ), $available ),
	);
}
