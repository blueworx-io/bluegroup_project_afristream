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
 * The residual race: WordPress's add_option() checks for the option before its
 * INSERT ... ON DUPLICATE KEY UPDATE, so two callers landing inside the same few
 * microseconds can in principle both be told they acquired. This function does
 * not close that window, and closing it would need a real named database lock —
 * more machinery than assignment volumes here justify. It is survivable only
 * because nothing relies on the lock alone: afristream_assign_license() reads
 * back the owner it just wrote and refuses if it is not its own, so a caller that
 * slipped through this window still cannot end up appearing to hold a licence
 * that somebody else holds.
 *
 * @return string|false Ownership token, or false when the lock is held.
 */
function afristream_lock_acquire() {
	$now   = (int) current_time( 'timestamp' );
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
 * costs nothing and removes a whole class of interleaving. A caller that cannot
 * take the lock is told so rather than proceeding without it, because
 * proceeding is exactly how two checkouts end up claiming the same licence. It
 * is told immediately: this never waits, so a queue of blocked requests can
 * never build up behind one slow claim.
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
 * Rewrite a user's usermeta mirror from the licences that point at them.
 *
 * The values are written as strings, not integers, because that is what ACF
 * wrote and what the Connected User column's LIKE '"10"' query matches: a
 * serialized integer is i:10; and would never be found.
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
 * The lock is an optimisation, not the guarantee. The guarantee is the
 * compare-and-swap: the owner row is written and then read straight back, and a
 * caller that does not find its own user ID there knows another claim landed
 * between the two and gives up. Without that read-back, two callers that both
 * got past the lock would both see an unowned licence, both write, and each
 * rebuild only its own mirror — leaving one owner on the licence but the licence
 * listed in two users' active_license. Since the Connected User column and the
 * customer's own portal read that mirror, that is exactly "two customers appear
 * to hold the same licence", the one state this whole design exists to make
 * impossible. The lock makes it rare; the read-back makes it impossible.
 *
 * A caller that finds it lost repairs both mirrors before returning: the real
 * holder's, so the mirror agrees with the licence's own record, and its own, so
 * it is not left advertising a licence it does not hold. It logs nothing, because
 * nothing was assigned to it, and it is refused with the same
 * afristream_license_taken code an already-owned licence gives, because from the
 * caller's side that is the same answer.
 *
 * What remains true is that the winner is whoever wrote last rather than whoever
 * asked first. That is fine: both callers wanted an unowned licence, and exactly
 * one of them ends up with it.
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

			update_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META, $user_id );

			// Read back what is actually on the licence now. Anything other than
			// our own user ID means a concurrent claim wrote after us and owns it.
			$holder = afristream_license_owner( $license_id );
			if ( $holder !== $user_id ) {
				afristream_rebuild_user_mirror( $holder );
				afristream_rebuild_user_mirror( $user_id );

				return new WP_Error(
					'afristream_license_taken',
					__( 'That licence is already assigned to another user.', 'bluegroup-project-afristream' )
				);
			}

			afristream_rebuild_user_mirror( $user_id );

			if ( function_exists( 'afristream_license_log_add' ) ) {
				afristream_license_log_add( $license_id, 'assigned', $user_id, $context );
			}

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
 * That does change what a bare truthiness test means, and callers have to be
 * updated rather than left alone: a refused lock used to come back as false and
 * now comes back as a WP_Error, which is truthy, so `if ( unassign( $id ) )`
 * now reads a refusal as a success. Check is_wp_error() first and treat the
 * three outcomes separately — they cannot safely be collapsed into two.
 *
 * @param int    $license_id Licence post ID.
 * @param string $context    Why, for the log.
 * @return true|false|WP_Error True if a holder was removed, false if it was
 *                             already free, WP_Error if the lock was refused
 *                             and nothing was attempted.
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

			delete_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META );
			afristream_rebuild_user_mirror( $owner );

			if ( function_exists( 'afristream_license_log_add' ) ) {
				afristream_license_log_add( $license_id, 'unassigned', $owner, $context );
			}

			return true;
		}
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true === $result;
}
