<?php
require_once __DIR__ . '/../../includes/fields.php';

/**
 * The licence log module does not exist yet — fields.php calls it behind a
 * function_exists() so it can be added later. Standing it in here is what gives
 * "the loser logs nothing" any force: without it the assertion would pass just
 * as happily against code that never logs anything at all.
 */
if ( ! function_exists( 'afristream_license_log_add' ) ) {
	function afristream_license_log_add( $license_id, $event, $user_id, $context = '' ) {
		$GLOBALS['af_log'][] = array( (int) $license_id, $event, (int) $user_id, $context );
	}
}

/**
 * Stand in for a competing claim that lands after $license_id's owner row is
 * written and before the writer reads it back. Fires once, on that licence only,
 * and writes nothing but the owner row — the winner's mirror is deliberately
 * left alone so that only the losing caller can be the one that repairs it.
 *
 * @param int $license_id Licence whose owner row gets stolen.
 * @param int $winner     User the licence really ends up with.
 * @return void
 */
function af_steal_owner_mid_write( $license_id, $winner ) {
	$fired = false;
	add_action(
		'updated_post_meta',
		function ( $meta_id, $post_id, $key, $value ) use ( $license_id, $winner, &$fired ) {
			if ( $fired || AFRISTREAM_LICENSE_OWNER_META !== $key || (int) $post_id !== (int) $license_id ) {
				return;
			}
			$fired = true;
			$GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] = (int) $winner;
		},
		10,
		4
	);
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
			'expires' => current_time( 'timestamp' ) + AFRISTREAM_LOCK_TTL,
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

	af_set_now( current_time( 'timestamp' ) + AFRISTREAM_LOCK_TTL + 1 );

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

	// User 8's claim gets past the lock at the same moment and writes last.
	af_steal_owner_mid_write( 10, 8 );

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
	// has to agree with the licence's own record on both sides of the race.
	af_assert_same( array( '10' ), get_user_meta( 8, AFRISTREAM_USER_LICENSE_META, true ), 'the winner\'s mirror was repaired' );
	af_assert_same( array( '11' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'and the loser\'s lists only what it still holds' );
	af_assert_same( array( 11 ), afristream_user_license_ids( 7 ), 'which is what the loser truly holds' );
	af_assert_same( array( 10 ), afristream_user_license_ids( 8 ), 'and the winner truly holds the contested one' );
} );

af_test( 'the loser of a race logs nothing', function () {
	$GLOBALS['af_log'] = array();
	af_seed_user( 7 );
	af_seed_user( 8 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_assign_license( 11, 7, 'test' );
	af_assert_same( 1, count( $GLOBALS['af_log'] ), 'a claim that wins is logged, so an unchanged count means something' );

	af_steal_owner_mid_write( 10, 8 );
	afristream_assign_license( 10, 7, 'test' );

	// A logged 'assigned' here would be a permanent record of user 7 being given a
	// licence user 8 holds — the audit trail contradicting the licence itself.
	af_assert_same( 1, count( $GLOBALS['af_log'] ), 'the loser adds no entry' );
} );

af_test( 'breaking a stale lock cannot break the live lock that replaced it', function () {
	// A request that died holding the lock, now past its TTL.
	af_assert( false !== afristream_lock_acquire(), 'the dead request took the lock' );
	af_set_now( current_time( 'timestamp' ) + AFRISTREAM_LOCK_TTL + 1 );

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

af_test( 'the mirror can be rebuilt from the licences alone', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '99' ) ); // stale nonsense

	afristream_rebuild_user_mirror( 7 );
	af_assert_same( array( '10' ), get_user_meta( 7, AFRISTREAM_USER_LICENSE_META, true ), 'rebuilt from truth' );
} );
