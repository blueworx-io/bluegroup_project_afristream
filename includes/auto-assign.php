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
 * How many active subscriptions this user has, or null when that cannot be
 * established.
 *
 * The distinction matters more than the number does. "This customer has no
 * subscriptions" and "SureCart could not be asked" are completely different
 * facts, and answering 0 to both makes them indistinguishable to every caller
 * here — all of which read 0 as "owed nothing". One failed API call during a
 * drain would then evict a paying customer from the queue, and nothing would
 * ever put them back, because the only thing that queues anyone is a SureCart
 * event that has already fired. So an unreadable answer is null, and callers
 * make no change at all on it.
 *
 * SureCart joins to WordPress through the customer record, so this is two
 * lookups: the customer for the user, then their subscriptions. Guarded with
 * class_exists() in the same style as includes/affiliates.php, so the feature
 * degrades to "assigns nothing" rather than fatally when SureCart is absent —
 * and absent counts as unknown, not as zero, for the same reason.
 *
 * The customer lookup is by WordPress user ID, which is the join SureCart
 * itself stores, so no email address is involved and a customer whose SureCart
 * address differs from their login is still found.
 *
 * @param int $user_id User ID.
 * @return int|null Subscriptions held, or null when that could not be read.
 */
function afristream_subscription_count( $user_id ) {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) || ! class_exists( '\SureCart\Models\Customer' ) ) {
		return null;
	}

	$user_id = (int) $user_id;
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		// Not zero: there is no account here to ask SureCart about, so nothing
		// has been established either way.
		return null;
	}

	$customers = \SureCart\Models\Customer::where( array( 'user_ids' => array( $user_id ) ) )->get();
	if ( is_wp_error( $customers ) ) {
		return null;
	}

	// An empty result that is not an error is a real answer: this user has never
	// been a SureCart customer, so they hold no subscriptions.
	if ( empty( $customers ) ) {
		return 0;
	}

	$customer_ids = array();
	foreach ( (array) $customers as $customer ) {
		$id = afristream_affiliate_prop( $customer, 'id', '' );
		if ( $id ) {
			$customer_ids[] = $id;
		}
	}

	// Customer records came back, but not one of them carried an ID. That is a
	// response shape we do not understand rather than an absence of
	// subscriptions, and reading it as zero is precisely the guess that costs
	// somebody the licence they paid for.
	if ( empty( $customer_ids ) ) {
		return null;
	}

	$subscriptions = \SureCart\Models\Subscription::where(
		array(
			'customer_ids' => $customer_ids,
			'status'       => array( 'active', 'trialing' ),
		)
	)->get();

	if ( is_wp_error( $subscriptions ) ) {
		return null;
	}
	if ( empty( $subscriptions ) ) {
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
 * How many licences a user should hold, or null when that cannot be established.
 *
 * Filterable so the count can be tested without SureCart, and so a future
 * arrangement — a staff account, a bundled plan — can be expressed without
 * changing the allocation logic. The filter is handed null when the
 * subscription count is unknown, so an override can still answer for a user
 * SureCart could not be asked about, and a filter that simply passes its value
 * through carries the unknown along rather than flattening it to zero.
 *
 * The filter's answer is accepted only when it is a number. A string, an array,
 * a boolean or null all cast to 0 or 1 under (int), and 0 here means "owed
 * nothing" — which would have this plugin report a paying customer as
 * over-allocated, and drop them from the queue, on the strength of a filter
 * that merely misbehaved. Anything non-numeric is therefore treated as unknown.
 * A negative number is a number, so it is honoured and floored at zero: nobody
 * is entitled to less than nothing, and the floor is what stops a negative
 * turning into an assignment through the arithmetic downstream.
 *
 * @param int $user_id User ID.
 * @return int|null Licences owed, or null when that could not be established.
 */
function afristream_entitlement( $user_id ) {
	$count    = afristream_subscription_count( $user_id );
	$filtered = apply_filters( 'afristream_entitlement', $count, (int) $user_id );

	if ( ! is_numeric( $filtered ) ) {
		return null;
	}

	return max( 0, (int) $filtered );
}

/**
 * Force the next read of these licences' owner to hit the store rather than
 * whatever this request already has cached.
 *
 * WordPress caches post meta per request — no persistent object cache plugin
 * needed for that. afristream_user_license_ids() and afristream_available_licenses()
 * both read every candidate's owner earlier in the same call that eventually
 * recounts, so without this the recount goes on answering with what it read
 * the first time even after a rival really has claimed one of these in
 * between. afristream_claim_license_row() only busts the cache for the licence
 * it itself writes, which does nothing for a candidate a rival claimed instead.
 *
 * Scoped to the candidates a caller is actually working through rather than
 * every licence on the site, so this stays a cache invalidation and not a
 * query of its own — the query only happens if and when the recount that
 * follows actually reads one of these.
 *
 * Note for whoever runs the tests: the in-memory store in tests/php/bootstrap.php
 * has no meta cache in front of it — wp_cache_delete() is a no-op stub there —
 * so nothing in the suite exercises this function or can catch its absence.
 * This is written from reading WordPress's caching behaviour, not from a
 * failing test.
 *
 * @param int[] $license_ids Candidate licence IDs.
 * @return void
 */
function afristream_bust_license_owner_cache( $license_ids ) {
	foreach ( $license_ids as $license_id ) {
		wp_cache_delete( (int) $license_id, 'post_meta' );
	}
}

/**
 * Bring a user up to their entitlement, as far as stock allows.
 *
 * Two things about the loop are load-bearing, and both exist because two
 * webhooks for the same customer really do arrive together.
 *
 * What the customer holds is re-counted immediately before every claim, rather
 * than tracked from what this call has handed out. A rival request working on
 * the same customer can land a claim in between, and a loop counting only its
 * own successes cannot see that — it would hand out a licence the customer has
 * already been given by somebody else. That is only true once the recount is
 * actually fresh, though: a rival's claim is a real database write, but this
 * process may already have this candidate's owner cached from reading it
 * earlier in this same call, and would otherwise go on reporting the value it
 * read then. afristream_bust_license_owner_cache() is what keeps the recount
 * honest, and is called on the same candidate list this loop draws from —
 * see that function for why a persistent object cache is not what makes this
 * necessary.
 *
 * A refused lock ends the loop instead of moving to the next candidate. The
 * lock is refused before afristream_assign_license() reaches its "already
 * theirs, return true" guard, so a refusal cannot be read as "not this one, try
 * another": it may well be this customer's own other request holding the lock
 * mid-claim, in which case moving on hands the customer a second licence for
 * one subscription. Standing down costs nothing — the shortfall is returned,
 * the caller queues it, and the drain picks it up the moment stock is free.
 * The over-ask below is kept for what it was for: a candidate genuinely taken
 * by somebody else, or one that has stopped being available.
 *
 * The loop deliberately does not run inside afristream_with_lock(): that is the
 * same global lock afristream_assign_license() takes, so every claim inside it
 * would be refused.
 *
 * @param int    $user_id User ID.
 * @param string $context Where the call came from, for the log.
 * @return array{assigned:int[],short:int,entitled:int|null,held:int,unknown:bool}
 *         unknown is true when the entitlement could not be read, in which case
 *         nothing was assigned and short is 0 only because nothing is known —
 *         callers must make no change on it.
 */
function afristream_topup_user( $user_id, $context = 'auto-assign' ) {
	$user_id  = (int) $user_id;
	$entitled = afristream_entitlement( $user_id );
	$held     = count( afristream_user_license_ids( $user_id ) );

	if ( null === $entitled ) {
		return array(
			'assigned' => array(),
			'short'    => 0,
			'entitled' => null,
			'held'     => $held,
			'unknown'  => true,
		);
	}

	$assigned = array();
	$wanted   = $entitled - $held;

	if ( $wanted <= 0 ) {
		return array(
			'assigned' => array(),
			'short'    => 0,
			'entitled' => $entitled,
			'held'     => $held,
			'unknown'  => false,
		);
	}

	// Ask for more candidates than needed: another request may claim one between
	// this list being built and the assignment being attempted, and a licence
	// lost that way should cost a retry rather than the whole top-up.
	$candidates = afristream_available_licenses( $wanted + 3 );

	foreach ( $candidates as $license_id ) {
		// See afristream_bust_license_owner_cache(): without this, a rival's
		// claim on one of these candidates since this call started would not
		// be visible to the recount just below.
		afristream_bust_license_owner_cache( $candidates );

		if ( count( afristream_user_license_ids( $user_id ) ) >= $entitled ) {
			break;
		}

		$result = afristream_assign_license( $license_id, $user_id, $context );

		if ( true === $result ) {
			$assigned[] = $license_id;
			continue;
		}

		if ( is_wp_error( $result ) && 'afristream_locked' === $result->get_error_code() ) {
			break;
		}
	}

	afristream_bust_license_owner_cache( $candidates );
	$held = count( afristream_user_license_ids( $user_id ) );

	return array(
		'assigned' => $assigned,
		'short'    => max( 0, $entitled - $held ),
		'entitled' => $entitled,
		'held'     => $held,
		'unknown'  => false,
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

	// Autoload off. This is read by a drain and by the Configurations page, never
	// by anything a customer loads, so there is no reason for it to sit in the
	// alloptions blob on every front-end request — and it grows with the queue.
	update_option( AFRISTREAM_PENDING_OPTION, $out, 'no' );
}

/**
 * Re-check everyone in the queue, and hand out whatever stock there is.
 *
 * Two separate jobs, deliberately not gated on each other. Keeping someone
 * owed nothing out of the queue has to happen whether or not there is a licence
 * free, because no stock is a queue's ordinary state — that is why anyone is in
 * it. Breaking out of the loop the moment stock ran out meant a lapsed
 * subscriber or a deleted user was only ever dropped on the lucky runs, so the
 * option grew without bound and the status line reported people waiting forever.
 *
 * Entitlement is re-read rather than trusted from the queued number: a
 * subscription may have lapsed while somebody waited, and giving a licence to a
 * customer who has since cancelled would be worse than the original shortage.
 * An entitlement that cannot be read leaves the entry exactly as it is — see
 * afristream_subscription_count() for why that is not the same as zero.
 *
 * @return int Licences assigned.
 */
function afristream_pending_drain() {
	$assigned = 0;

	foreach ( afristream_pending_all() as $entry ) {
		$user_id = $entry['user'];

		// A user who no longer exists cannot be owed anything, and nothing else
		// will ever clear their entry.
		if ( ! get_userdata( $user_id ) ) {
			afristream_pending_set( $user_id, 0 );
			continue;
		}

		if ( empty( afristream_available_licenses( 1 ) ) ) {
			// Nothing to hand out, but the entry still has to be corrected or
			// dropped. afristream_topup_user() would do the same arithmetic; it is
			// repeated here so a drain over an empty pool costs one entitlement
			// lookup per queued user rather than two.
			$entitled = afristream_entitlement( $user_id );
			if ( null === $entitled ) {
				continue;
			}

			afristream_pending_set( $user_id, max( 0, $entitled - count( afristream_user_license_ids( $user_id ) ) ) );
			continue;
		}

		$result    = afristream_topup_user( $user_id, 'auto-assign-queued' );
		$assigned += count( $result['assigned'] );

		if ( empty( $result['unknown'] ) ) {
			afristream_pending_set( $user_id, $result['short'] );
		}
	}

	return $assigned;
}

/**
 * Bring a user up to date, queueing whatever stock could not cover.
 *
 * An entitlement that could not be read changes nothing — not the licences and
 * not the queue. Clearing a queue entry on the strength of a failed API call is
 * how a paying customer disappears out of the only list that would have got
 * them a licence.
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
	if ( ! empty( $result['unknown'] ) ) {
		return;
	}

	afristream_pending_set( $user_id, $result['short'] );
}

/** SureCart events that arrived but could not be matched to a WordPress user. */
define( 'AFRISTREAM_UNRESOLVED_OPTION', 'afristream_unresolved_events' );

/** How many unresolved events to keep. Enough to see a pattern, small enough
 * to be a row. */
define( 'AFRISTREAM_UNRESOLVED_CAP', 20 );

/**
 * How many attribute names to keep per stored event. Deliberately a separate
 * constant from AFRISTREAM_UNRESOLVED_CAP above, which caps how many events
 * are kept — not what is recorded about each one. Only bites for a plain
 * array payload, which can carry far more fields than are worth storing; a
 * probed object always produces the same short, fixed list.
 */
define( 'AFRISTREAM_UNRESOLVED_KEYS_CAP', 20 );

/**
 * Record a SureCart event this plugin could not turn into a user.
 *
 * Dropping it silently is what makes a wrong assumption invisible: if SureCart
 * ever renames the field the user ID hangs off, every event still fires, every
 * one resolves to nobody, and the only symptom is that customers stop getting
 * licences. What is stored is the shape, not the payload, because the payload
 * is a customer's personal data.
 *
 * "The shape" means different things for the two forms this can arrive in. A
 * plain array exposes its keys directly, so every one of them is recorded — a
 * renamed field is visible whatever it was renamed to. A real SureCart model
 * is not like that: its attributes sit behind __get, private, which is the
 * whole reason afristream_affiliate_prop() exists, and get_object_vars() —
 * what this used to read the keys with — answers empty for a model shaped
 * that way, for every model, always. There is no way to list "every attribute"
 * of something like that from outside it. What is possible instead is to ask,
 * one name at a time through afristream_affiliate_prop(), for the handful of
 * attributes afristream_user_id_from_surecart() actually reads plus their
 * obvious neighbours, and record which of those were there. That cannot show
 * a field renamed to something nobody anticipated, but it does show that none
 * of the expected ones were — which is the failure this exists to catch.
 *
 * @param mixed $object Whatever the hook handed over.
 * @return void
 */
function afristream_record_unresolved_event( $object ) {
	$keys = array();

	if ( is_array( $object ) ) {
		$keys = array_keys( $object );
	} elseif ( is_object( $object ) ) {
		// user_id and customer are what afristream_user_id_from_surecart() itself
		// reads. customer_id and id are their obvious neighbours: a payload that
		// carries a bare customer reference instead of an embedded object, or
		// whose own id would help identify which record this was, in the admin
		// screen that lists these.
		foreach ( array( 'user_id', 'customer', 'customer_id', 'id' ) as $probe ) {
			$present = null !== afristream_affiliate_prop( $object, $probe, null );
			$keys[]  = $probe . ( $present ? ': present' : ': missing' );
		}
	}

	$stored = get_option( AFRISTREAM_UNRESOLVED_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$stored[] = array(
		'time' => (int) current_time( 'timestamp' ),
		'type' => is_object( $object ) ? get_class( $object ) : gettype( $object ),
		'keys' => array_slice( array_map( 'strval', $keys ), 0, AFRISTREAM_UNRESOLVED_KEYS_CAP ),
	);

	if ( count( $stored ) > AFRISTREAM_UNRESOLVED_CAP ) {
		$stored = array_slice( $stored, -AFRISTREAM_UNRESOLVED_CAP );
	}

	update_option( AFRISTREAM_UNRESOLVED_OPTION, $stored, 'no' );
}

/**
 * SureCart events that could not be matched to a user, oldest first.
 *
 * @return array<int,array{time:int,type:string,keys:string[]}>
 */
function afristream_unresolved_events() {
	$stored = get_option( AFRISTREAM_UNRESOLVED_OPTION, array() );
	return is_array( $stored ) ? $stored : array();
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

	if ( ! $user_id ) {
		afristream_record_unresolved_event( $object );
		return;
	}

	afristream_autoassign_for_user( $user_id );
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
//
// wpmu_delete_user alongside it for the same reason includes/licenses.php hooks
// both: on multisite, deleting a user from the network fires that one instead,
// and a drain wired only to delete_user would never see the freed stock.
add_action( 'delete_user', 'afristream_pending_drain', 20 );
add_action( 'wpmu_delete_user', 'afristream_pending_drain', 20 );

/**
 * When a licence was last given to somebody, from its own history.
 *
 * 0 when there is no recorded assignment at all — a licence claimed before the
 * log existed, or written by hand. That sorts it as the oldest holding, which
 * is the right way round: an unrecorded assignment is by definition not one of
 * the recent ones.
 *
 * @param int $license_id Licence post ID.
 * @return int Unix timestamp, or 0 when nothing was ever logged.
 */
function afristream_license_acquired_at( $license_id ) {
	$last = afristream_license_last_assignment( $license_id );
	return $last ? (int) $last['time'] : 0;
}

/**
 * Users holding more licences than they are entitled to.
 *
 * Reported, never acted on. A lapsed subscription is often a failed card that
 * recovers within days, and taking a paying-then-briefly-lapsed customer's
 * access away automatically is a decision that belongs to a person.
 *
 * Which is exactly why it must not report on a guess. With SureCart inactive or
 * erroring, every entitlement reads as unknown, and treating that as zero would
 * list every customer on the site as over-allocated with their entire holding
 * as surplus — handed to a person who is meant to act on it. So SureCart is
 * checked first, and any customer whose entitlement cannot be read is left out
 * of the report rather than accused.
 *
 * The surplus named is the most recently acquired, taken from each licence's
 * own history rather than from its ID, which is creation order and says nothing
 * about when it changed hands. Ties, including licences with no recorded
 * assignment, fall back to licence ID so the report is stable between runs.
 *
 * Counted over every licence whatever its post status, the same as
 * afristream_user_license_ids(), because a customer holding a licence that has
 * since been moved to draft still holds it. A publish-only count here would
 * disagree with the count the top-up works from and quietly under-report.
 *
 * @return array<int,array{user:int,entitled:int,held:int,surplus:int[]}>
 */
function afristream_over_allocated() {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) ) {
		return array();
	}

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

		if ( null === $entitled || $held <= $entitled ) {
			continue;
		}

		usort(
			$license_ids,
			function ( $a, $b ) {
				$ta = afristream_license_acquired_at( $a );
				$tb = afristream_license_acquired_at( $b );
				if ( $ta !== $tb ) {
					return $ta <=> $tb;
				}
				return $a <=> $b;
			}
		);

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

/** How long without an automatic assignment before this stops claiming to know. */
define( 'AFRISTREAM_ASSIGN_SILENCE', 30 * DAY_IN_SECONDS );

/**
 * The last time a licence was handed out by this automation, from the licence
 * log rather than from a counter of its own.
 *
 * The log is already written on every automatic assignment and is the record a
 * person checks by hand, so a second source of the same fact would only be
 * something else to keep in step.
 *
 * @return int Unix timestamp, or 0 when there has never been one.
 */
function afristream_last_autoassignment() {
	foreach ( afristream_license_log_recent( 0 ) as $entry ) {
		if ( 'assigned' !== $entry['event'] ) {
			continue;
		}
		if ( 0 === strpos( (string) $entry['context'], 'auto-assign' ) ) {
			return (int) $entry['time'];
		}
	}

	return 0;
}

/**
 * A one-line health summary for the Configurations page.
 *
 * The thing this must never do is call itself healthy on the strength of stock
 * existing. The two SureCart hook names are the plugin's one unverified
 * assumption about the live site, and if either is wrong then nothing is ever
 * assigned, nobody new ever reaches the queue — a drain only revisits people
 * already in it — and a status keyed on free licences would read
 * "Active, N licences available" at exactly the moment zero were being handed
 * out. Whether the hooks are registered proves nothing, and is not checked
 * here: this file registers them itself a few lines below, so has_action()
 * would answer true even after a SureCart update renamed the event and
 * nothing has fired since. What actually distinguishes a healthy install is
 * that something has been assigned automatically and recently — the unknown
 * state below is what covers a wrong hook name, by saying plainly that it
 * cannot be told apart from silence.
 *
 * Silence is not treated as failure either, because a site nobody has bought
 * from this month is genuinely silent. It is reported as not knowing, in those
 * words, which is the honest answer and the one that prompts somebody to check.
 *
 * @return array{state:string,label:string}
 *         state is one of off, warn, unknown, ok.
 */
function afristream_autoassign_status() {
	if ( ! class_exists( '\SureCart\Models\Subscription' ) ) {
		return array(
			'state' => 'off',
			'label' => __( 'SureCart inactive — nothing is assigned automatically', 'bluegroup-project-afristream' ),
		);
	}

	$unresolved = count( afristream_unresolved_events() );
	if ( $unresolved ) {
		return array(
			'state' => 'warn',
			/* translators: %d: number of SureCart events that named no user. */
			'label' => sprintf( _n( '%d SureCart event arrived carrying no customer this plugin could recognise', '%d SureCart events arrived carrying no customer this plugin could recognise', $unresolved, 'bluegroup-project-afristream' ), $unresolved ),
		);
	}

	$pending = afristream_pending_all();
	if ( ! empty( $pending ) ) {
		return array(
			'state' => 'warn',
			/* translators: %d: number of users waiting. */
			'label' => sprintf( _n( '%d user awaiting a licence', '%d users awaiting a licence', count( $pending ), 'bluegroup-project-afristream' ), count( $pending ) ),
		);
	}

	$last = afristream_last_autoassignment();
	$now  = (int) current_time( 'timestamp' );

	if ( $last && ( $now - $last ) <= AFRISTREAM_ASSIGN_SILENCE ) {
		return array(
			'state' => 'ok',
			/* translators: %s: how long ago, e.g. "2 hours". */
			'label' => sprintf( __( 'Active — a licence was assigned automatically %s ago', 'bluegroup-project-afristream' ), human_time_diff( $last, $now ) ),
		);
	}

	return array(
		'state' => 'unknown',
		/* translators: %d: number of days without an automatic assignment. */
		'label' => sprintf(
			__( 'Nothing has been assigned automatically in %d days. That is normal if nobody has subscribed, but it reads exactly the same as the SureCart hook names being wrong or missing — this cannot tell the two apart. Make a test purchase to confirm.', 'bluegroup-project-afristream' ),
			(int) ( AFRISTREAM_ASSIGN_SILENCE / DAY_IN_SECONDS )
		),
	);
}

/**
 * Declare auto-assignment on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_autoassign_registry( $items ) {
	$status = afristream_autoassign_status();

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Auto-assign on purchase',
		'type'   => 'hook',
		'handle' => 'surecart/purchase_created',
		'file'   => 'includes/auto-assign.php',
		'status' => $status,
	);

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Auto-assign on new subscription',
		'type'   => 'hook',
		'handle' => 'surecart/subscription_created',
		'file'   => 'includes/auto-assign.php',
		'status' => $status,
	);

	$over = afristream_over_allocated();
	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Entitlement check',
		'type'   => 'integration',
		'handle' => 'afristream_entitlement',
		'file'   => 'includes/auto-assign.php',
		'status' => empty( $over )
			? array( 'state' => 'ok', 'label' => __( 'One licence per active subscription', 'bluegroup-project-afristream' ) )
			: array(
				'state' => 'warn',
				/* translators: %d: number of users. */
				'label' => sprintf( _n( '%d user over-allocated', '%d users over-allocated', count( $over ), 'bluegroup-project-afristream' ), count( $over ) ),
			),
	);

	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Waiting list',
		'type'   => 'hook',
		'handle' => 'save_post_license',
		'file'   => 'includes/auto-assign.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Served automatically when a licence frees up', 'bluegroup-project-afristream' ),
		),
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_autoassign_registry' );
