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
 * Whether a licence can be handed to someone: published, unowned, not past its
 * expiry date, and its expiry date is actually readable. A corrupted expiry_date
 * fails closed rather than being treated as "no expiry" — we cannot promise a
 * customer an unexpired licence when we can't tell whether it has expired. A
 * licence expiring today is still usable today.
 *
 * @param int $license_id Licence post ID.
 * @return bool
 */
function afristream_license_is_available( $license_id ) {
	$license_id = (int) $license_id;

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

/** Transient key guarding a licence claim. */
define( 'AFRISTREAM_LOCK_KEY', 'afristream_assign_lock' );

/** How long a claim may hold the lock before it is assumed abandoned. */
define( 'AFRISTREAM_LOCK_TTL', 10 );

/**
 * Run a callback with the assignment lock held.
 *
 * Claims are short — read an owner, write an owner — so a single global lock
 * costs nothing and removes a whole class of interleaving. A caller that cannot
 * take the lock is told so rather than proceeding without it, because
 * proceeding is exactly how two checkouts end up claiming the same licence.
 *
 * The TTL means a request that dies mid-claim releases the lock on its own
 * within ten seconds instead of wedging assignment until someone notices.
 *
 * @param callable $fn Body to run while holding the lock.
 * @return mixed The callback's return value, or WP_Error when the lock is held.
 */
function afristream_with_lock( $fn ) {
	if ( get_transient( AFRISTREAM_LOCK_KEY ) ) {
		return new WP_Error(
			'afristream_locked',
			__( 'Another licence assignment is in progress. Please try again.', 'bluegroup-project-afristream' )
		);
	}

	set_transient( AFRISTREAM_LOCK_KEY, 1, AFRISTREAM_LOCK_TTL );

	try {
		return call_user_func( $fn );
	} finally {
		delete_transient( AFRISTREAM_LOCK_KEY );
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
					__( 'That licence is expired or not published.', 'bluegroup-project-afristream' )
				);
			}

			update_post_meta( $license_id, AFRISTREAM_LICENSE_OWNER_META, $user_id );
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
 * @param int    $license_id Licence post ID.
 * @param string $context    Why, for the log.
 * @return bool True if a holder was removed, false if it was already free.
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

	return true === $result;
}
