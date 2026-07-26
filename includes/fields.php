<?php
/**
 * The licence data model, owned by this plugin rather than by ACF.
 *
 * ACF held three things: the `license` post type, four fields on it, and a
 * relationship field on the user pointing at licences. All three now live here,
 * reading and writing the exact meta keys and value formats ACF used, so no
 * data moves and nothing that already reads this data has to change.
 *
 * One thing does change, deliberately. ACF recorded an assignment only as an
 * entry in a serialized array in usermeta. That is a read-modify-write, so two
 * overlapping assignments silently lose one of them, and nothing structurally
 * stops the same licence appearing in two users' arrays. Now the licence itself
 * carries its owner in a single meta row — one licence, one owner, by
 * construction — and the usermeta array is rebuilt from it as a mirror for the
 * benefit of everything that still reads the old shape.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authoritative owner of a licence: one user ID, stored on the licence post. */
define( 'AFRISTREAM_LICENSE_OWNER_META', 'afristream_assigned_user' );

/** The derived mirror ACF wrote, kept so existing readers keep working. */
define( 'AFRISTREAM_USER_LICENSE_META', 'active_license' );

/**
 * The four licence fields, in the order they appeared in ACF.
 *
 * @return array<string,array{label:string,type:string,choices?:string[]}>
 */
function afristream_license_fields() {
	return array(
		'app_password'     => array(
			'label' => 'App Password',
			'type'  => 'text',
		),
		'expiry_date'      => array(
			'label' => 'Expiry Date',
			'type'  => 'date',
		),
		'license_provider' => array(
			'label'   => 'License Provider',
			'type'    => 'select',
			'choices' => array( 'Shockwave', 'AfriStream' ),
		),
		'mobile_active'    => array(
			'label'   => 'Mobile Active',
			'type'    => 'radio',
			'choices' => array( 'Yes', 'No' ),
		),
	);
}

/**
 * Parse a stored expiry value strictly as Ymd. The round-trip check (format it
 * back and compare) is what rejects both plain garbage and DateTime's habit of
 * rolling an overflowing value into a real date instead of failing — e.g.
 * "20261332" would otherwise silently become some day in 2027.
 *
 * @param string $raw Candidate Ymd string.
 * @return DateTime|false
 */
function afristream_parse_ymd( $raw ) {
	$date = DateTime::createFromFormat( 'Ymd', $raw );
	return ( $date && $date->format( 'Ymd' ) === $raw ) ? $date : false;
}

/**
 * Read a licence field exactly as ACF's get_field() returned it.
 *
 * The only field that is not a straight passthrough is expiry_date: ACF stores
 * a date picker as Ymd but was configured to return d/m/Y, and both the admin
 * column and the expiry parser downstream expect d/m/Y. An unparseable value is
 * handed back untouched rather than coerced — a wrong date shown as itself can
 * be found and fixed, one silently rewritten cannot.
 *
 * @param int    $post_id Licence post ID.
 * @param string $key     Field name.
 * @return string
 */
function afristream_license_meta( $post_id, $key ) {
	$raw = (string) get_post_meta( (int) $post_id, $key, true );

	if ( 'expiry_date' !== $key || '' === $raw ) {
		return $raw;
	}

	$date = afristream_parse_ymd( $raw );
	if ( ! $date ) {
		return $raw;
	}

	return $date->format( 'd/m/Y' );
}

/**
 * The stored, sortable form of the expiry date. Ymd strings compare correctly
 * as strings, which is what availability and ordering rely on.
 *
 * @param int $license_id Licence post ID.
 * @return string Ymd, or '' when unset or unparseable.
 */
function afristream_license_expiry_ymd( $license_id ) {
	$raw = (string) get_post_meta( (int) $license_id, 'expiry_date', true );
	if ( '' === $raw ) {
		return '';
	}
	return afristream_parse_ymd( $raw ) ? $raw : '';
}

/**
 * Whether a licence's expiry_date can be trusted to decide availability. A
 * blank field genuinely means "no expiry" and is trustworthy. A non-blank
 * field that fails to parse is not: afristream_license_expiry_ymd() collapses
 * that case to '' too, same as unset, so callers deciding availability need
 * this to tell the two apart rather than treating a corrupted date as a licence
 * that never expires.
 *
 * @param int $license_id Licence post ID.
 * @return bool
 */
function afristream_license_expiry_is_readable( $license_id ) {
	$raw = (string) get_post_meta( (int) $license_id, 'expiry_date', true );
	return '' === $raw || false !== afristream_parse_ymd( $raw );
}

/**
 * The user holding this licence, or 0 if it is free.
 *
 * "Free" has two storage shapes and both must read the same. A licence that has
 * never been touched has no owner row at all; one that has been released has a
 * row holding '0', because afristream_claim_license_row() needs a row to exist
 * before it can conditionally update it and so releases by writing '0' rather
 * than deleting. Casting to int collapses both — '' and '0' are each 0 — so no
 * caller has to know which shape it is looking at.
 *
 * @param int $license_id Licence post ID.
 * @return int
 */
function afristream_license_owner( $license_id ) {
	return (int) get_post_meta( (int) $license_id, AFRISTREAM_LICENSE_OWNER_META, true );
}

/**
 * Every published licence ID. Small by nature — a licence is a thing someone
 * bought, and there are tens of them, not thousands.
 *
 * @return int[]
 */
function afristream_all_license_ids() {
	$ids = get_posts(
		array(
			'post_type'      => 'license',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Every licence a user holds, read from the authoritative side.
 *
 * Not from the usermeta mirror: the mirror is a convenience for other readers,
 * and if the two ever disagree the licence's own record is the one to trust.
 *
 * @param int $user_id User ID.
 * @return int[] Ascending licence IDs.
 */
function afristream_user_license_ids( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return array();
	}

	$held = array();
	foreach ( afristream_all_license_ids() as $license_id ) {
		if ( afristream_license_owner( $license_id ) === $user_id ) {
			$held[] = $license_id;
		}
	}

	sort( $held );
	return $held;
}

/**
 * Whether a licence can be handed to someone: it is actually a licence,
 * published, unowned, not past its expiry date, and its expiry date is actually
 * readable. A corrupted expiry_date fails closed rather than being treated as
 * "no expiry" — we cannot promise a customer an unexpired licence when we can't
 * tell whether it has expired. A licence expiring today is still usable today.
 *
 * The post type is checked because a post ID is just a number and nothing stops
 * a caller passing the ID of a page or an order. Without this, ownership meta
 * lands on an unrelated post, and the user's mirror — which is rebuilt only from
 * licence posts — comes back empty, so the caller is told the assignment worked
 * while the customer holds nothing.
 *
 * A zero or otherwise falsy ID is refused before the post type is asked for,
 * because get_post_type( 0 ) does not mean "no post" in WordPress — it falls back
 * to the global $post, so on a licence's own admin screen it would happily answer
 * 'license' and let an ID of nothing look available. No caller reaches this with
 * 0 today; the check is here so the function is correct read on its own.
 *
 * @param int $license_id Licence post ID.
 * @return bool
 */
function afristream_license_is_available( $license_id ) {
	$license_id = (int) $license_id;

	if ( ! $license_id ) {
		return false;
	}
	if ( 'license' !== get_post_type( $license_id ) ) {
		return false;
	}
	if ( 'publish' !== get_post_status( $license_id ) ) {
		return false;
	}
	if ( afristream_license_owner( $license_id ) ) {
		return false;
	}
	if ( ! afristream_license_expiry_is_readable( $license_id ) ) {
		return false;
	}

	$expiry = afristream_license_expiry_ymd( $license_id );
	if ( '' === $expiry ) {
		return true;
	}

	return $expiry >= current_time( 'Ymd' );
}

/**
 * Available licences, soonest-expiring first.
 *
 * Dated stock is spent before undated stock: a licence with an expiry date is
 * wasting away whether or not anyone is using it, whereas one without an expiry
 * keeps indefinitely. Undated licences therefore sort last, and ties break on ID
 * so the order is stable between calls.
 *
 * @param int $limit Most to return; 0 for all.
 * @return int[]
 */
function afristream_available_licenses( $limit = 0 ) {
	$available = array();
	foreach ( afristream_all_license_ids() as $license_id ) {
		if ( afristream_license_is_available( $license_id ) ) {
			$available[] = $license_id;
		}
	}

	usort(
		$available,
		function ( $a, $b ) {
			$ea = afristream_license_expiry_ymd( $a );
			$eb = afristream_license_expiry_ymd( $b );

			if ( ( '' === $ea ) !== ( '' === $eb ) ) {
				return '' === $ea ? 1 : -1;
			}
			if ( $ea !== $eb ) {
				return strcmp( $ea, $eb );
			}
			return $a <=> $b;
		}
	);

	$limit = (int) $limit;
	return $limit > 0 ? array_slice( $available, 0, $limit ) : $available;
}

/**
 * Register the licence post type.
 *
 * Arguments copied verbatim from ACF's own export so the admin URLs, menu
 * position, icon and REST visibility are exactly what they were.
 */
function afristream_register_license_post_type() {
	register_post_type(
		'license',
		array(
			'labels'           => array(
				'name'          => __( 'Licenses', 'bluegroup-project-afristream' ),
				'singular_name' => __( 'License', 'bluegroup-project-afristream' ),
				'menu_name'     => __( 'Licenses', 'bluegroup-project-afristream' ),
				'all_items'     => __( 'All Licenses', 'bluegroup-project-afristream' ),
				'edit_item'     => __( 'Edit License', 'bluegroup-project-afristream' ),
				'view_item'     => __( 'View License', 'bluegroup-project-afristream' ),
				'add_new_item'  => __( 'Add New License', 'bluegroup-project-afristream' ),
				'add_new'       => __( 'Add New License', 'bluegroup-project-afristream' ),
				'new_item'      => __( 'New License', 'bluegroup-project-afristream' ),
				'search_items'  => __( 'Search Licenses', 'bluegroup-project-afristream' ),
				'not_found'     => __( 'No licenses found', 'bluegroup-project-afristream' ),
			),
			'public'           => true,
			'show_in_rest'     => true,
			'menu_icon'        => 'dashicons-tickets-alt',
			'supports'         => array( 'title', 'custom-fields' ),
			'delete_with_user' => false,
		)
	);

	foreach ( array_keys( afristream_license_fields() ) as $key ) {
		register_meta(
			'post',
			$key,
			array(
				'object_subtype' => 'license',
				'type'           => 'string',
				'single'         => true,
				'show_in_rest'   => true,
				'auth_callback'  => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}
add_action( 'init', 'afristream_register_license_post_type', 5 );

/** Option name guarding a licence claim. */
define( 'AFRISTREAM_LOCK_KEY', 'afristream_assign_lock' );

/** How long a claim may hold the lock before it is assumed abandoned. */
define( 'AFRISTREAM_LOCK_TTL', 10 );

/**
 * The token inside a stored lock value, or '' when the value is not a shape we
 * recognise as a lock.
 *
 * Two reasons this is a function rather than an inline comparison. First, the
 * option is just a row anybody could have written, so its 'token' may be missing
 * or be an array; comparing that to a string directly raises an "Array to string
 * conversion" warning and then compares nonsense. Second, an unreadable lock
 * value still has to be clearable or assignment wedges permanently, and giving it
 * the token '' lets it be cleared through the same compare-and-delete as every
 * other lock instead of needing a special unconditional path.
 *
 * @param mixed $value Whatever is stored under AFRISTREAM_LOCK_KEY.
 * @return string
 */
function afristream_lock_token( $value ) {
	if ( is_array( $value ) && isset( $value['token'] ) && is_scalar( $value['token'] ) ) {
		return (string) $value['token'];
	}
	return '';
}

/**
 * Try to take the assignment lock, returning the token that proves ownership of
 * it, or false if somebody else holds it.
 *
 * The lock is an option rather than a transient because taking it has to be one
 * indivisible act. Reading a transient and then writing it is two acts with a
 * gap between them, and two requests that both look during that gap both see
 * nothing and both proceed — which is the entire situation the lock exists to
 * prevent. add_option() is an INSERT against a unique key, so the database, not
 * our sequencing, decides who wins. wp_cache_add() would be atomic too, but only
 * where a persistent object cache is installed, and this plugin cannot assume
 * one; an option works on the plainest possible WordPress host.
 *
 * A holder that dies mid-claim never releases anything, so the stored value
 * carries the moment it stops being credible. A later caller that finds an
 * expired lock breaks it and tries once more — once, not in a loop, because
 * anything beyond a single retry is waiting, which this lock does not do.
 *
 * Breaking is itself a claim, so it is done as a compare-and-delete against the
 * exact token that was observed as expired, never as a plain delete. Two callers
 * that both read the same dead lock would otherwise both get in: the first
 * deletes it and takes a fresh lock, and the second's delete would then remove
 * that live lock and leave the second free to acquire alongside the first. With
 * the token compared, the second's delete finds a token it did not observe and
 * does nothing, so its one retry meets a live lock and it is refused — which is
 * the correct answer for a caller that was too late.
 *
 * The residual race, stated plainly: WordPress's add_option() checks for the
 * option before its INSERT ... ON DUPLICATE KEY UPDATE, so two callers landing
 * inside the same few microseconds can in principle both be told they acquired.
 * This function does not close that window. It does not need to, because the
 * lock is not what keeps two customers off one licence — the conditional UPDATE
 * in afristream_claim_license_row() is, and it is decided by InnoDB's row lock
 * rather than by anything sequenced in PHP. What the lock buys is that claims
 * rarely collide in the first place, so the losing path is rarely taken.
 *
 * Expiry is measured with time(), not current_time(), because the TTL is a
 * duration in real seconds. current_time() applies the site's timezone offset,
 * which would make a lock written before a timezone change look hours old or
 * hours in the future.
 *
 * @return string|false Ownership token, or false when the lock is held.
 */
function afristream_lock_acquire() {
	$now   = time();
	$token = uniqid( 'af', true ) . '.' . random_int( 100000, 999999 );
	$value = array(
		'token'   => $token,
		'expires' => $now + AFRISTREAM_LOCK_TTL,
	);

	if ( add_option( AFRISTREAM_LOCK_KEY, $value, '', 'no' ) ) {
		return $token;
	}

	$held = get_option( AFRISTREAM_LOCK_KEY );

	// A lock we cannot read an expiry from is treated as expired: leaving an
	// unrecognisable value in place would wedge assignment permanently.
	$expires = is_array( $held ) && isset( $held['expires'] ) ? (int) $held['expires'] : 0;
	if ( $expires > $now ) {
		return false;
	}

	// Break the dead lock only if it is still the dead lock we looked at.
	afristream_lock_release( afristream_lock_token( $held ) );

	return add_option( AFRISTREAM_LOCK_KEY, $value, '', 'no' ) ? $token : false;
}

/**
 * Give up the lock, but only if the lock in place is still the one identified by
 * this token.
 *
 * A claim can overrun the TTL — afristream_rebuild_user_mirror() reads a meta
 * row per licence, and a slow database makes ten seconds reachable. Once it has
 * overrun, the lock in the option may already belong to somebody else, and
 * deleting it unconditionally would strip a live claim of its protection while
 * that claim is mid-write. Matching the token first means an overrunning holder
 * quietly leaves its successor alone.
 *
 * The same matching is what makes breaking a stale lock safe, which is why
 * afristream_lock_acquire() comes through here rather than deleting the option
 * itself: a caller that observed a dead lock passes that dead lock's token, and
 * if somebody else has already broken it and taken a live one, this finds a
 * different token and leaves it alone.
 *
 * @param string $token The token returned by afristream_lock_acquire(), or the
 *                      token read from a lock observed as expired.
 * @return void
 */
function afristream_lock_release( $token ) {
	$held = get_option( AFRISTREAM_LOCK_KEY );

	if ( false === $held ) {
		return;
	}

	if ( afristream_lock_token( $held ) === (string) $token ) {
		delete_option( AFRISTREAM_LOCK_KEY );
	}
}

/**
 * Run a callback with the assignment lock held.
 *
 * Claims are short — read an owner, write an owner — so a single global lock
 * costs nothing and removes most interleaving before it can happen. A caller
 * that cannot take the lock is refused rather than run without it, so two
 * checkouts almost never reach the owner row at the same moment; the conditional
 * UPDATE in afristream_claim_license_row() is what decides it correctly on the
 * occasions they do. The refusal is immediate: this never waits, so a queue of
 * blocked requests can never build up behind one slow claim.
 *
 * The TTL means a request that dies mid-claim releases the lock on its own
 * within ten seconds instead of wedging assignment until someone notices.
 *
 * @param callable $fn Body to run while holding the lock.
 * @return mixed The callback's return value, or WP_Error when the lock is held.
 */
function afristream_with_lock( $fn ) {
	$token = afristream_lock_acquire();

	if ( false === $token ) {
		return new WP_Error(
			'afristream_locked',
			__( 'Another licence assignment is in progress. Please try again.', 'bluegroup-project-afristream' )
		);
	}

	try {
		return call_user_func( $fn );
	} finally {
		afristream_lock_release( $token );
	}
}

/**
 * Move a licence's owner row from one exact value to another, and report whether
 * this caller is the one that moved it.
 *
 * This is the only place in the plugin that issues SQL, and it exists because
 * every PHP-level way of claiming a licence is check-then-act: read the owner,
 * decide it is free, write. Two requests can both read "free" before either
 * writes, and no amount of reading back afterwards fixes that — a read-back only
 * catches a rival that wrote between our write and our read, never one that
 * writes after it. Both callers would then be told they succeeded, and both
 * users' mirrors would list the licence, which is precisely the state this
 * design exists to make impossible.
 *
 * A single conditional UPDATE removes the gap. InnoDB takes a row lock for the
 * duration of the statement, so of two concurrent callers naming the same
 * expected current value, exactly one changes the row and is told one row
 * changed; the other matches nothing and is told zero. The answer comes from the
 * database's own serialisation, not from how PHP happened to interleave.
 *
 * Two details the statement depends on:
 *
 * The row must exist. A conditional UPDATE cannot match a row that was never
 * inserted, so a licence nobody has ever held gets its owner row created here,
 * holding '0'. add_post_meta() with $unique = true is a no-op when the row is
 * already there, so this is safe to run on every claim. It is itself a
 * check-then-act inside WordPress, so two requests racing the very first claim
 * of a licence can each find no row and each insert their own: not one row
 * moved between two claimants, but two separate rows, one per caller. Each
 * caller's UPDATE then matches only the row it just inserted, so both read a
 * changed count of 1 and both believe they claimed the licence. get_post_meta()
 * always returns the lowest meta_id, so only one of those rows is ever visible
 * to anyone else — the other caller's write is real but orphaned, and that
 * caller has to be told it lost despite its own row count. That confirmation
 * is what afristream_assign_license() does immediately after a successful
 * claim, rather than trusting the row count alone. A licence whose owner row
 * has been hand-edited to an empty string is not repaired here: it simply
 * fails to claim, which refuses an assignment rather than risking one being
 * taken from underneath somebody.
 *
 * Rows changed, not rows matched. MySQL reports rows actually changed, so a swap
 * whose target equals the value it matched would report zero and read as a lost
 * race. No caller does that — a claim always moves between '0' and a real user
 * ID — and the guard below refuses such a call rather than answering it wrongly.
 *
 * @param int        $license_id Licence post ID.
 * @param int|string $from       The owner value this caller believes is in place.
 * @param int|string $to         The owner value to write. '0' releases.
 * @return bool True only if this call changed the row.
 */
function afristream_claim_license_row( $license_id, $from, $to ) {
	global $wpdb;

	$license_id = (int) $license_id;
	$from       = (string) $from;
	$to         = (string) $to;

	if ( ! $license_id || $from === $to ) {
		return false;
	}

	add_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META, '0', true );

	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
			$to,
			$license_id,
			AFRISTREAM_LICENSE_OWNER_META,
			$from
		)
	);

	// Raw SQL goes round the meta cache, so without this every get_post_meta()
	// later in the same request would still be served the pre-claim owner.
	wp_cache_delete( $license_id, 'post_meta' );

	// A query error comes back as false, a row count as an integer. They are not
	// the same answer and must not be flattened into one: "somebody beat me to
	// it" is a normal outcome, a broken query is not. Both mean this caller did
	// not claim the licence, which is why both return false here, but the caller
	// distinguishes them by re-reading the owner — a lost race leaves a real
	// holder behind, a failed query leaves the licence still free — and reports
	// the two differently rather than telling somebody a free licence is taken.
	if ( false === $changed ) {
		return false;
	}

	return (int) $changed > 0;
}

/**
 * Rewrite a user's usermeta mirror from the licences that point at them.
 *
 * The values are written as strings, not integers, because that is what ACF
 * wrote and what the Connected User column's LIKE '"10"' query matches: a
 * serialized integer is i:10; and would never be found.
 *
 * The set is derived here, immediately before it is written, and never passed in
 * or cached by a caller. This is a read-then-write and cannot be made atomic
 * without abandoning the array shape ACF left behind, so the window between the
 * derive and the write is kept as small as it can be. It also means no request
 * ever rebuilds another user's mirror: a request that computed somebody else's
 * licences and then wrote them could easily be writing a set that went stale
 * while it was busy — dropping a licence that user was assigned in the meantime.
 * Each claim rebuilds only the mirror of the user it claimed for.
 *
 * @param int $user_id User ID.
 * @return void
 */
function afristream_rebuild_user_mirror( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}

	$ids = array_map( 'strval', afristream_user_license_ids( $user_id ) );
	update_user_meta( $user_id, AFRISTREAM_USER_LICENSE_META, $ids );

	// Two rebuilds for the same user can interleave so the later write carries
	// the older derivation: this call derives [10], a rival derives [10, 11]
	// and writes first, and this call's write then lands on top of it and
	// takes the mirror back down to [10]. The licence rows themselves stay
	// correct throughout — only this derived copy goes stale — so re-deriving
	// right after the write and comparing catches it: if the truth right now
	// differs from what was just written, something changed underneath this
	// call, and the fresher value replaces it.
	//
	// One retry, not a loop. The mirror is derived data whose only job is to
	// agree with the licences it comes from, and it is read only as a
	// convenience mirror, never as an authority — afristream_user_license_ids()
	// is that. Converging a little late, on whichever rebuild happens to run
	// next, is acceptable for something nothing trusts on its own; spinning
	// here would only keep racing the same rival with no better odds, in
	// exchange for turning a bounded write into an unbounded one.
	$fresh = array_map( 'strval', afristream_user_license_ids( $user_id ) );
	if ( $fresh !== $ids ) {
		update_user_meta( $user_id, AFRISTREAM_USER_LICENSE_META, $fresh );
	}
}

/**
 * The licence IDs recorded in a user's mirror.
 *
 * Only for checking the mirror against the truth — never for deciding what
 * someone holds. afristream_user_license_ids() is the answer to that.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function afristream_user_mirror_ids( $user_id ) {
	$raw = get_user_meta( (int) $user_id, AFRISTREAM_USER_LICENSE_META, true );
	if ( empty( $raw ) ) {
		return array();
	}
	if ( ! is_array( $raw ) ) {
		$raw = array( $raw );
	}

	$ids = array();
	foreach ( $raw as $entry ) {
		$id = is_object( $entry ) ? (int) $entry->ID : (int) $entry;
		if ( $id ) {
			$ids[] = $id;
		}
	}

	sort( $ids );
	return $ids;
}

/**
 * Give a licence to a user.
 *
 * The availability check happens inside the lock, immediately before the write,
 * so a licence that was free when the caller looked but taken by the time it
 * acted is refused rather than quietly stolen.
 *
 * The lock is an optimisation, not the guarantee. It keeps claims from colliding
 * often; it cannot keep them from colliding at all, because add_option() decides
 * who holds it with a read followed by a write. The guarantee is the conditional
 * UPDATE in afristream_claim_license_row(): the owner row moves from '0' to this
 * user in one statement that only matches while the licence is still free, and
 * the database says whether this caller was the one that moved it. Of two
 * callers that both got past the lock and both saw an unowned licence, exactly
 * one gets a row changed and assigns; the other gets nothing and is refused.
 *
 * That is what stops two customers appearing to hold one licence. The mirror in
 * active_license — what the Connected User column and the customer's own portal
 * read — is only ever written by a caller whose claim actually changed the row,
 * so a licence can only ever be listed in one user's mirror.
 *
 * A caller that loses has written nothing at all, so it repairs nothing before
 * returning. In particular it does not rebuild the winner's mirror: the winner
 * rebuilds its own as part of its own claim, and a set computed here could
 * already be stale by the time it was written, dropping a licence the winner was
 * assigned in between. The loser logs nothing either, because nothing was
 * assigned to it.
 *
 * A lost claim is reported by what the licence says afterwards, not by the claim
 * failing. Somebody else holding it is afristream_license_taken, the same answer
 * an already-owned licence gives. A licence that reads free after a failed claim
 * is a different thing — an unassign landed in the gap, or the query itself
 * errored — and telling the caller it is "assigned to another user" would be a
 * lie, so it gets its own afristream_license_unclaimed code and an invitation to
 * try again.
 *
 * What remains true is that the winner is whoever the database serialised first
 * rather than whoever asked first. That is fine: both callers wanted an unowned
 * licence, and exactly one of them ends up with it.
 *
 * @param int    $license_id Licence post ID.
 * @param int    $user_id    User to give it to.
 * @param string $context    Why, for the log: 'auto-assign', 'profile', 'backfill'.
 * @return true|WP_Error
 */
function afristream_assign_license( $license_id, $user_id, $context = '' ) {
	$license_id = (int) $license_id;
	$user_id    = (int) $user_id;

	if ( ! $license_id || ! $user_id ) {
		return new WP_Error( 'afristream_bad_args', __( 'A licence and a user are both required.', 'bluegroup-project-afristream' ) );
	}

	return afristream_with_lock(
		function () use ( $license_id, $user_id, $context ) {
			$owner = afristream_license_owner( $license_id );

			// Already theirs. Saying so is more useful than an error, because it
			// makes a repeated webhook harmless.
			if ( $owner === $user_id ) {
				return true;
			}

			if ( $owner ) {
				return new WP_Error(
					'afristream_license_taken',
					__( 'That licence is already assigned to another user.', 'bluegroup-project-afristream' )
				);
			}

			if ( ! afristream_license_is_available( $license_id ) ) {
				return new WP_Error(
					'afristream_license_unavailable',
					__( 'That licence is expired, not published, or not a licence at all.', 'bluegroup-project-afristream' )
				);
			}

			// The claim, and the only thing that decides who gets the licence.
			// It changes the owner row only while it still reads '0'.
			if ( ! afristream_claim_license_row( $license_id, '0', (string) $user_id ) ) {
				$holder = afristream_license_owner( $license_id );

				// Somebody else assigned it to this same user while we were
				// asking. Nothing to complain about: the customer holds it, and
				// their mirror is the winner's to have written, so all this
				// caller does is agree.
				if ( $holder === $user_id ) {
					return true;
				}

				if ( $holder ) {
					return new WP_Error(
						'afristream_license_taken',
						__( 'That licence is already assigned to another user.', 'bluegroup-project-afristream' )
					);
				}

				// Free, yet the claim did not land: it was assigned and released
				// again in the gap, or the query failed. Either way nothing was
				// written and nobody else has it, so say that rather than blaming
				// an owner who does not exist.
				return new WP_Error(
					'afristream_license_unclaimed',
					__( 'That licence could not be claimed just now. Please try again.', 'bluegroup-project-afristream' )
				);
			}

			// The row-level claim just reported success, but that is not
			// quite proof this caller is the licence's one true owner: see
			// the note on afristream_claim_license_row() about two owner
			// rows landing for the same licence when neither existed yet.
			// Each caller's UPDATE then matches only its own row, so a
			// caller can read a changed count of 1 while the row anyone
			// else will ever see names somebody different. Confirming
			// against the authoritative read is what catches that before
			// this caller is told, and logged, as the winner.
			$owner_now = afristream_license_owner( $license_id );

			if ( $owner_now !== $user_id ) {
				// This caller genuinely changed a row — it is not the
				// ordinary lost race above — but it is not the row anyone
				// else will ever read back. Both mirrors have to agree
				// with the truth: the real holder's, in case this caller's
				// stray write is the reason theirs is now stale too, and
				// this caller's own, so it does not go on listing a
				// licence it does not hold. No log entry is written,
				// because nothing was actually assigned to this caller.
				if ( $owner_now ) {
					afristream_rebuild_user_mirror( $owner_now );
				}
				afristream_rebuild_user_mirror( $user_id );

				return new WP_Error(
					'afristream_license_taken',
					__( 'That licence is already assigned to another user.', 'bluegroup-project-afristream' )
				);
			}

			afristream_rebuild_user_mirror( $user_id );

			afristream_license_log_add( $license_id, 'assigned', $user_id, $context );

			return true;
		}
	);
}

/**
 * Take a licence back.
 *
 * Three outcomes, and they have to stay distinguishable. Flattening a refused
 * lock into false would tell somebody revoking a customer's access that the
 * licence was already free when in fact it is still assigned and nothing was
 * done — the one wrong answer that leads to an unrevoked account. So a refusal
 * is returned as the WP_Error it is, exactly as afristream_assign_license()
 * does.
 *
 * The release goes through the same conditional UPDATE as a claim, naming the
 * owner this call read as the value it expects to find. Deleting the row on the
 * strength of that read would be a check-then-act: if an assignment landed in
 * between, the licence would be freed out from under its new owner while that
 * owner's mirror went on listing it — two records disagreeing about who holds
 * what, from a request that meant to release somebody else entirely. Conditioned
 * on the expected owner, that release simply does not happen, and the caller is
 * told so with afristream_license_changed rather than being allowed to carry on
 * from a stale read.
 *
 * Releasing writes '0' rather than deleting the row, because the conditional
 * UPDATE needs a row to match; afristream_license_owner() reads a '0' row and a
 * missing row alike, so nothing downstream can tell the difference.
 *
 * That does change what a bare truthiness test means, and callers have to be
 * updated rather than left alone: a refused lock used to come back as false and
 * now comes back as a WP_Error, which is truthy, so `if ( unassign( $id ) )`
 * now reads a refusal as a success. Check is_wp_error() first and treat the
 * three outcomes separately — they cannot safely be collapsed into two.
 *
 * @param int    $license_id Licence post ID.
 * @param string $context    Why, for the log.
 * @return true|false|WP_Error True if a holder was removed, false if it was
 *                             already free, WP_Error if the lock was refused or
 *                             the licence changed hands mid-release — in both
 *                             error cases nothing was written.
 */
function afristream_unassign_license( $license_id, $context = '' ) {
	$license_id = (int) $license_id;
	if ( ! $license_id ) {
		return false;
	}

	$result = afristream_with_lock(
		function () use ( $license_id, $context ) {
			$owner = afristream_license_owner( $license_id );
			if ( ! $owner ) {
				return false;
			}

			// Release only while the licence still says what we just read. A row
			// count of nothing means somebody changed it underneath us, and the
			// one thing we must not do then is act on the owner we read.
			if ( ! afristream_claim_license_row( $license_id, (string) $owner, '0' ) ) {
				return new WP_Error(
					'afristream_license_changed',
					__( 'That licence changed hands while it was being released. Nothing was changed. Please try again.', 'bluegroup-project-afristream' )
				);
			}

			afristream_rebuild_user_mirror( $owner );

			afristream_license_log_add( $license_id, 'unassigned', $owner, $context );

			return true;
		}
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true === $result;
}
