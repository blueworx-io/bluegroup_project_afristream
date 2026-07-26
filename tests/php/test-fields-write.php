<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';

/**
 * A competing request that got past the lock and is claiming the same licence.
 *
 * It does exactly what afristream_assign_license() does once it holds the lock —
 * the conditional claim, and then, only if that claim actually changed the row, a
 * rebuild of its own mirror — and nothing else. Written out rather than calling
 * the real function because the real one would take the lock, which is the very
 * thing these tests need bypassed: the question is what happens to two claims
 * that reach the owner row together.
 *
 * @param int $license_id Licence being fought over.
 * @param int $user_id    The rival's user.
 * @return bool Whether the rival got it.
 */
function af_rival_claim( $license_id, $user_id ) {
	$won = afristream_claim_license_row( $license_id, '0', (string) $user_id );
	if ( $won ) {
		afristream_rebuild_user_mirror( $user_id );
	}
	return $won;
}

/**
 * The same, for a competing release.
 *
 * @param int $license_id Licence being released.
 * @param int $owner      The holder the rival believes is in place.
 * @return bool Whether the rival freed it.
 */
function af_rival_release( $license_id, $owner ) {
	$freed = afristream_claim_license_row( $license_id, (string) $owner, '0' );
	if ( $freed ) {
		afristream_rebuild_user_mirror( $owner );
	}
	return $freed;
}

/**
 * Run $fn once, immediately before the next claim reaches the database — a rival
 * that gets to the owner row first.
 *
 * @param callable $fn Rival's body.
 * @return void
 */
function af_before_claim( $fn ) {
	$fired = false;
	add_action(
		'af_wpdb_before_query',
		function ( $post_id, $meta_key, $from, $to ) use ( $fn, &$fired ) {
			if ( $fired ) {
				return;
			}
			$fired = true;
			call_user_func( $fn, $post_id, $from, $to );
		},
		10,
		4
	);
}

/**
 * Run $fn once, immediately after a claim has been decided — a rival that
 * arrives when the first caller already believes it has won and has passed the
 * point where any read-back of its own could see anything.
 *
 * @param callable $fn Rival's body.
 * @return void
 */
function af_after_claim( $fn ) {
	$fired = false;
	add_action(
		'af_wpdb_after_query',
		function ( $post_id, $meta_key, $from, $to, $changed ) use ( $fn, &$fired ) {
			if ( $fired ) {
				return;
			}
			$fired = true;
			call_user_func( $fn, $post_id, $from, $to, $changed );
		},
		10,
		5
	);
}

/**
 * Run $fn once, immediately before a user's mirror is written — a rival
 * landing in the exact gap afristream_rebuild_user_mirror() cannot close on
 * its own: after it has derived what to write, before that write lands.
 *
 * Hooks the real 'update_user_meta' action WordPress fires before saving,
 * the same seam af_before_claim()/af_after_claim() use on the claim side of
 * a race, extended here to the mirror-write side rather than building a
 * second mechanism for it.
 *
 * @param int      $user_id Mirror this rival is scoped to.
 * @param callable $fn      Rival's body.
 * @return void
 */
function af_before_mirror_write( $user_id, $fn ) {
	$fired = false;
	add_action(
		'update_user_meta',
		function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( $user_id, $fn, &$fired ) {
			if ( $fired || (int) $object_id !== (int) $user_id || AFRISTREAM_USER_LICENSE_META !== $meta_key ) {
				return;
			}
			$fired = true;
			call_user_func( $fn );
		},
		10,
		4
	);
}

/**
 * Age the lock in place until it is stale. The TTL is measured in real seconds
 * now, which no test can fast-forward, so the lock's own expiry is pushed into
 * the past instead — the same state a request that died holding it leaves behind.
 *
 * @return void
 */
function af_expire_lock() {
	$held = get_option( AFRISTREAM_LOCK_KEY );
	if ( is_array( $held ) ) {
		$held['expires'] = time() - 1;
		update_option( AFRISTREAM_LOCK_KEY, $held );
	}
}

af_test( 'assigning writes the licence and rebuilds the mirror as strings', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'assign succeeds' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'owner is on the licence' );

	// ACF stored the relationship as an array of *strings*. The Connected User
	// column matches on LIKE '"10"', which only hits a serialized string.
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror holds string ids' );
} );

af_test( 'a second licence for the same user does not disturb the first', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( array( 10, 11 ), afristream_user_license_ids( 7 ), 'both held' );
	af_assert_same( array( '10', '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror has both' );
} );

af_test( 'a licence already held by someone else is refused', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'test' );
	$result = afristream_assign_license( 10, 8, 'test' );

	af_assert( is_wp_error( $result ), 'second assignment is a WP_Error' );
	af_assert_same( 'afristream_license_taken', $result->get_error_code(), 'with a specific code' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'the first holder keeps it' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'the second user gets nothing' );
} );

af_test( 'reassigning to the same user is a no-op, not an error', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'test' );
	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'idempotent' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 7 ), 'still exactly one' );
} );

af_test( 'an expired or draft licence cannot be assigned', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'expired' );
	update_post_meta( 10, 'expiry_date', '20250101' );
	af_seed_post( 11, 'draft', 'draft' );

	// The code matters, not just the failure: an implementation that rejected
	// everything as bad arguments would pass a bare is_wp_error() check.
	$expired = afristream_assign_license( 10, 7, 'test' );
	$draft   = afristream_assign_license( 11, 7, 'test' );

	af_assert_same( 'afristream_license_unavailable', $expired->get_error_code(), 'expired refused as unavailable' );
	af_assert_same( 'afristream_license_unavailable', $draft->get_error_code(), 'draft refused as unavailable' );
} );

af_test( 'a post that is not a licence cannot be assigned', function () {
	af_seed_user( 7 );
	af_seed_post( 500, 'About us', 'publish', 'page' );

	$result = afristream_assign_license( 500, 7, 'test' );

	af_assert( is_wp_error( $result ), 'a page is refused' );
	af_assert_same( 'afristream_license_unavailable', $result->get_error_code(), 'as unavailable' );
	af_assert_same( 0, afristream_license_owner( 500 ), 'no ownership was written onto the page' );
	// The damaging half: a "successful" assignment here would rebuild the user's
	// mirror from licence posts only, emptying it while reporting success.
	af_assert_same( '', get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'the mirror was never rebuilt' );
} );

af_test( 'unassigning frees the licence and updates the mirror', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( true, afristream_unassign_license( 10, 'test' ), 'unassign succeeds' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'licence is free' );
	af_assert( afristream_license_is_available( 10 ), 'and available again' );
	af_assert_same( array( 11 ), afristream_user_license_ids( 7 ), 'the other licence is untouched' );
	af_assert_same( array( '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror follows' );
} );

af_test( 'unassigning a free licence is harmless', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 11, 7, 'test' );

	af_assert_same( false, afristream_unassign_license( 10, 'test' ), 'reports nothing to do' );
	af_assert_same( 7, afristream_license_owner( 11 ), 'the other licence still has its holder' );
	af_assert_same( array( '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the mirror is untouched' );

	// false has to mean "already free" and nothing else — an implementation that
	// always returned false would look identical above.
	af_assert_same( true, afristream_unassign_license( 11, 'test' ), 'a held licence reports it was freed' );
} );

af_test( 'a refused lock is an error, never "it was already free"', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );
	afristream_assign_license( 10, 7, 'test' );

	// Revoking while another claim holds the lock. Reporting this as false would
	// tell whoever is cutting off access that there was nothing to cut off.
	$refused = afristream_with_lock( function () {
		return afristream_unassign_license( 10, 'test' );
	} );

	af_assert( is_wp_error( $refused ), 'refusal comes back as an error' );
	af_assert_same( 'afristream_locked', $refused->get_error_code(), 'with the lock code' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'and the customer still holds it' );

	// The outcome it must stay distinct from.
	af_assert_same( false, afristream_unassign_license( 11, 'test' ), 'a genuinely free licence is still false' );
} );

af_test( 'a user with no licences has an empty mirror, not a stale one', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_unassign_license( 10, 'test' );

	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'mirror emptied' );
} );

af_test( 'the lock serialises and reports refusal rather than proceeding', function () {
	$ran = 0;
	$out = afristream_with_lock( function () use ( &$ran ) {
		$ran++;
		// Re-entering while held must be refused, not deadlock or double-run.
		$inner = afristream_with_lock( function () use ( &$ran ) {
			$ran++;
			return 'inner';
		} );
		af_assert( is_wp_error( $inner ), 'a nested claim is refused' );
		return 'outer';
	} );

	af_assert_same( 'outer', $out, 'the outer body ran and returned' );
	af_assert_same( 1, $ran, 'the inner body never ran' );

	// The lock is released afterwards, so the next claim succeeds.
	af_assert_same( 'after', afristream_with_lock( function () { return 'after'; } ), 'lock released' );
} );

af_test( 'the lock is released even when the body throws', function () {
	$threw = false;
	try {
		afristream_with_lock( function () {
			throw new RuntimeException( 'the claim blew up' );
		} );
	} catch ( RuntimeException $e ) {
		$threw = true;
	}

	af_assert( $threw, 'the exception reaches the caller rather than being swallowed' );
	af_assert_same( false, get_option( AFRISTREAM_LOCK_KEY ), 'the lock did not leak' );
	af_assert_same( 'after', afristream_with_lock( function () { return 'after'; } ), 'so assignment still works' );
} );

af_test( 'a lock is released only by the caller that took it', function () {
	$mine = afristream_lock_acquire();
	af_assert( false !== $mine, 'the lock was taken' );

	// Stand in for this claim overrunning the TTL and the lock being re-taken by
	// somebody else while it was still running.
	update_option(
		AFRISTREAM_LOCK_KEY,
		array(
			'token'   => 'someone-else',
			'expires' => time() + AFRISTREAM_LOCK_TTL,
		)
	);

	afristream_lock_release( $mine );

	$held = get_option( AFRISTREAM_LOCK_KEY );
	af_assert( is_array( $held ), 'the successor still has a lock' );
	af_assert_same( 'someone-else', $held['token'], 'and it is theirs, not ours' );
	af_assert(
		is_wp_error( afristream_with_lock( function () { return 'ran'; } ) ),
		'so it still keeps a third claim out'
	);
} );

af_test( 'a stale lock is broken by the next caller', function () {
	// A request that died mid-claim: the lock was written and never released.
	af_assert( false !== afristream_lock_acquire(), 'the dead request took the lock' );
	af_assert(
		is_wp_error( afristream_with_lock( function () { return 'ran'; } ) ),
		'inside the TTL it is honoured'
	);

	af_expire_lock();

	af_assert_same(
		'ran',
		afristream_with_lock( function () { return 'ran'; } ),
		'past the TTL it is broken rather than wedging assignment forever'
	);
} );

af_test( 'losing the race leaves neither user appearing to hold the licence', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	// User 7 already holds something, so an emptied mirror below would be as
	// visible a failure as a mirror still listing licence 10.
	afristream_assign_license( 11, 7, 'test' );

	// User 8's claim gets past the lock at the same moment and reaches the owner
	// row first.
	af_before_claim( function () {
		af_rival_claim( 10, 8 );
	} );

	$result = afristream_assign_license( 10, 7, 'test' );

	// Guarded, because a regression here returns true rather than an error, and a
	// fatal on ->get_error_code() would take the rest of the suite down with it.
	af_assert( is_wp_error( $result ), 'the caller that lost is told so' );
	af_assert_same(
		'afristream_license_taken',
		is_wp_error( $result ) ? $result->get_error_code() : 'assignment reported success',
		'with the code an owned licence already gives'
	);
	af_assert_same( 8, afristream_license_owner( 10 ), 'the licence has exactly one owner, the winner' );

	// The whole point: the mirror the Connected User column and the portal read
	// has to agree with the licence's own record on both sides of the race. The
	// winner wrote its own mirror as part of its own claim — the loser never
	// touches it, because a set computed here could be stale by the time it
	// landed and would drop whatever the winner was assigned in between.
	af_assert_same( array( '10' ), get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'the winner\'s mirror lists it' );
	af_assert_same( array( '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the loser\'s lists only what it still holds' );
	af_assert_same( array( 11 ), afristream_user_license_ids( 7 ), 'which is what the loser truly holds' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 8 ), 'and the winner truly holds the contested one' );
} );

af_test( 'the loser of a race logs nothing', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_assign_license( 11, 7, 'test' );
	af_assert_same( 1, count( afristream_license_log_get( 11 ) ), 'a claim that wins is logged, so an unchanged count means something' );

	af_before_claim( function () {
		af_rival_claim( 10, 8 );
	} );
	afristream_assign_license( 10, 7, 'test' );

	// A logged 'assigned' here would be a permanent record of user 7 being given a
	// licence user 8 holds — the audit trail contradicting the licence itself.
	af_assert_same( 0, count( afristream_license_log_get( 10 ) ), 'the loser adds no entry' );
} );

af_test( 'breaking a stale lock cannot break the live lock that replaced it', function () {
	// A request that died holding the lock, now past its TTL.
	af_assert( false !== afristream_lock_acquire(), 'the dead request took the lock' );
	af_expire_lock();

	// Caller A lands between B reading the dead lock and B acting on what it read:
	// A breaks the dead lock and takes a live one. B carries on holding the value
	// it already read, so it is about to break a lock that is no longer there.
	$a     = false;
	$fired = false;
	add_filter(
		'option_' . AFRISTREAM_LOCK_KEY,
		function ( $value ) use ( &$a, &$fired ) {
			if ( ! $fired ) {
				$fired = true;
				$a     = afristream_lock_acquire();
			}
			return $value;
		}
	);

	$b = afristream_lock_acquire();

	af_assert( false !== $a, 'A broke the stale lock and acquired' );
	af_assert_same( false, $b, 'B is refused rather than acquiring alongside A' );
	af_assert_same( $a, afristream_lock_token( get_option( AFRISTREAM_LOCK_KEY ) ), 'and A\'s live lock survived B\'s break' );
} );

af_test( 'an unreadable lock value is cleared rather than wedging assignment', function () {
	// Nothing writes this shape, but the option is a row anybody could touch, and
	// a value with no readable expiry must not lock assignment out forever.
	update_option( AFRISTREAM_LOCK_KEY, array( 'token' => array( 'not', 'a', 'string' ) ) );

	af_assert_same(
		'ran',
		afristream_with_lock( function () { return 'ran'; } ),
		'the junk lock is broken and the claim runs'
	);
	af_assert_same( false, get_option( AFRISTREAM_LOCK_KEY ), 'and the lock is released cleanly afterwards' );
} );

af_test( 'a rival claiming after the winner already believes it has won is refused', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );

	// The interleaving no read-back can ever see: the rival arrives after this
	// caller's own write has been decided. An implementation that read its write
	// back and trusted the answer would report success to both callers here.
	$rival = null;
	af_after_claim( function () use ( &$rival ) {
		$rival = af_rival_claim( 10, 8 );
	} );

	$result = afristream_assign_license( 10, 7, 'test' );

	af_assert_same( true, $result, 'the first caller succeeded' );
	af_assert_same( false, $rival, 'and the late rival is refused by the row it tried to move' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'the licence has one owner' );

	// Exactly one mirror may list a licence — this is the state the customer
	// asked never to see.
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'the winner lists it' );
	af_assert_same( '', get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'and nobody else does' );
} );

af_test( 'a licence is never freed out from under the owner who just took it', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	// A revoke reads user 7 as the holder. Before its release reaches the row,
	// another request releases the licence and a new customer claims it. Acting
	// on the stale read would strip user 8 of a licence their mirror still lists.
	af_before_claim( function () {
		af_rival_release( 10, 7 );
		af_rival_claim( 10, 8 );
	} );

	$result = afristream_unassign_license( 10, 'test' );

	af_assert( is_wp_error( $result ), 'the revoke is told its read went stale' );
	af_assert_same(
		'afristream_license_changed',
		is_wp_error( $result ) ? $result->get_error_code() : 'the release reported success',
		'with a code that is not "already free"'
	);
	af_assert_same( 8, afristream_license_owner( 10 ), 'the new owner keeps it' );
	af_assert_same( array( '10' ), get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'and their mirror still agrees with the licence' );
	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'the previous holder lists nothing' );
} );

af_test( 'a claim conditioned on the wrong current owner changes nothing', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, '7' );

	af_assert_same( false, afristream_claim_license_row( 10, '0', '8' ), 'a held licence cannot be claimed as free' );
	af_assert_same( false, afristream_claim_license_row( 10, '9', '8' ), 'nor taken from a holder it does not have' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'the real holder is untouched by either' );

	af_assert_same( true, afristream_claim_license_row( 10, '7', '8' ), 'naming the owner actually in place succeeds' );
	af_assert_same( 8, afristream_license_owner( 10 ), 'and moves it' );
	af_assert_same( false, afristream_claim_license_row( 10, '7', '9' ), 'the same claim cannot be replayed' );
} );

af_test( 'a licence that has never been assigned is claimed exactly once', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( array(), get_post_meta( 10 ), 'there is no owner row to update yet' );

	af_assert_same( true, afristream_claim_license_row( 10, '0', '7' ), 'the row is created and claimed' );
	af_assert_same( false, afristream_claim_license_row( 10, '0', '8' ), 'and the next claimant finds it no longer free' );
	af_assert_same( 7, afristream_license_owner( 10 ), 'one owner, the first' );
} );

af_test( 'released licences keep an owner row, holding nobody', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );
	afristream_unassign_license( 10, 'test' );

	// Deleting the row instead would leave the next conditional claim with
	// nothing to match, and the licence unassignable.
	af_assert_same( '0', get_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, true ), 'the row survives, reading nobody' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'which reads the same as never having had one' );
	af_assert_same( true, afristream_assign_license( 10, 7, 'test' ), 'and it can be handed out again' );
} );

af_test( 'the fake database refuses statements it does not model', function () {
	// The race tests are only worth anything if the stub is at least as strict as
	// MySQL. A silent success for unrecognised SQL would make them decorative.
	global $wpdb;

	$threw = false;
	try {
		$wpdb->query( 'DELETE FROM wp_postmeta WHERE post_id = 10' );
	} catch ( RuntimeException $e ) {
		$threw = true;
	}

	af_assert( $threw, 'unmodelled SQL is an error, never a pretend row count' );
} );

af_test( 'the mirror can be rebuilt from the licences alone', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '99' ) ); // stale nonsense

	afristream_rebuild_user_mirror( 7 );
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'rebuilt from truth' );
} );

af_test( 'a claim that succeeds at the row level but is not the resulting owner is refused, not credited', function () {
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );

	// Stand in for the duplicate-row window described on
	// afristream_claim_license_row(): this caller's own UPDATE genuinely
	// changes a row and truthfully reports success, but the row
	// get_post_meta() surfaces afterwards names somebody else, because it was
	// a different row — inserted by another caller — that won the race to be
	// the one anyone reads back. The fake store holds one row per meta_key,
	// so it cannot model two owner rows directly; this reproduces the
	// observable result instead, by making the authoritative read disagree
	// with the row this caller just changed.
	af_after_claim( function ( $post_id, $from, $to, $changed ) {
		if ( $changed ) {
			update_post_meta( $post_id, AFRISTREAM_LICENSE_OWNER_META, '8' );
		}
	} );

	$result = afristream_assign_license( 10, 7, 'test' );

	af_assert( is_wp_error( $result ), 'the caller is told it did not get the licence' );
	af_assert_same(
		'afristream_license_taken',
		is_wp_error( $result ) ? $result->get_error_code() : 'assignment reported success',
		'the same code an already-owned licence gives'
	);
	af_assert_same( 0, count( afristream_license_log_get( 10 ) ), 'no assigned entry is written for a caller that did not really win' );
	af_assert_same( array(), afristream_user_license_ids( 7 ), 'the caller does not truly hold it' );
	af_assert_same( array(), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'nor does its own mirror list it' );
	af_assert_same( array( '10' ), get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'the real holder\'s mirror agrees with the licence' );
} );

af_test( 'a mirror rebuild that is overtaken mid-derivation converges to the complete list rather than the stale one', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	// Licence 10 is already truly held by user 7; the mirror has not been
	// rebuilt to say so yet.
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, '7' );

	// The instant this rebuild is about to write what it derived — [10]
	// only, since licence 11 is not yet assigned as far as it read — a rival
	// finishes assigning licence 11 to the same user and writes the complete
	// mirror first. The delayed, stale write this call already had in hand
	// then lands on top of it, exactly the clobber the fix has to catch.
	af_before_mirror_write( 7, function () {
		update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, '7' );
		update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10', '11' ) );
	} );

	afristream_rebuild_user_mirror( 7 );

	// Without the bounded re-check this would read back ['10'] — the value
	// this call itself wrote over the rival's complete one — even though the
	// licence rows agree the user holds both.
	af_assert_same(
		array( '10', '11' ),
		get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ),
		'the stale write is caught and corrected rather than left standing'
	);
} );
