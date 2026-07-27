<?php
/**
 * A history for each licence: who held it, when, and who made it so.
 *
 * The point is manual override. When a licence needs reassigning by hand, the
 * question is always "who had this last and how did they get it" — and before
 * this there was no way to answer it, because an assignment left no trace
 * beyond its current state.
 *
 * Stored as postmeta rather than a custom table. There are tens of licences,
 * not thousands; the log travels with the post through export and import; and
 * there is no schema to create, version or migrate.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Where a licence's history lives. */
define( 'AFRISTREAM_LICENSE_LOG_META', 'afristream_license_log' );

/**
 * Entries kept per licence.
 *
 * Enough to cover the life of a licence several times over, small enough that
 * the meta row stays a sensible size. The oldest go first — recent history is
 * what an override decision needs.
 */
define( 'AFRISTREAM_LOG_CAP', 100 );

/**
 * Who is making this change.
 *
 * A person's display name when someone is logged in, 'system' when the change
 * came from a webhook or cron, where there is no user to name.
 *
 * @return string
 */
function afristream_current_actor() {
	$user = wp_get_current_user();
	if ( $user && ! empty( $user->ID ) && ! empty( $user->display_name ) ) {
		return (string) $user->display_name;
	}
	return 'system';
}

/**
 * Append an entry to a licence's history.
 *
 * Stored oldest-first so appending is a push and capping is a slice; the
 * reversal for display happens on read, which is the rarer operation.
 *
 * Every string is sanitised on the way in, not only escaped on the way out.
 * Two of them are not this plugin's own words: actor comes from a display name,
 * which a subscriber sets for themselves, and detail carries field values a
 * licence's editor typed. Every reader escapes today, but the log is read in
 * several places and will be read in more, and a store that cannot hold markup
 * is one that cannot be the source of an injection when somebody adds a reader
 * that forgets. sanitize_text_field() is the right strength here: these are
 * single-line labels, and it strips tags and control bytes while leaving
 * ordinary punctuation — including the arrows the field diffs are written with
 * — alone.
 *
 * @param int    $license_id Licence post ID.
 * @param string $event      assigned|unassigned|created|updated|conflict.
 * @param int    $user_id    Licence holder involved, 0 if none.
 * @param string $context    Where the change came from: auto-assign, profile, backfill, admin.
 * @param string $detail     Free text, e.g. a field's before and after.
 * @return void
 */
function afristream_license_log_add( $license_id, $event, $user_id = 0, $context = '', $detail = '' ) {
	$license_id = (int) $license_id;
	if ( ! $license_id ) {
		return;
	}

	$log = get_post_meta( $license_id, AFRISTREAM_LICENSE_LOG_META, true );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$log[] = array(
		'time'    => (int) current_time( 'timestamp' ),
		'event'   => sanitize_text_field( (string) $event ),
		'user'    => (int) $user_id,
		'actor'   => sanitize_text_field( afristream_current_actor() ),
		'context' => sanitize_text_field( (string) $context ),
		'detail'  => sanitize_text_field( (string) $detail ),
	);

	if ( count( $log ) > AFRISTREAM_LOG_CAP ) {
		$log = array_slice( $log, -AFRISTREAM_LOG_CAP );
	}

	update_post_meta( $license_id, AFRISTREAM_LICENSE_LOG_META, $log );
}

/**
 * A licence's history, newest first.
 *
 * @param int $license_id Licence post ID.
 * @return array<int,array>
 */
function afristream_license_log_get( $license_id ) {
	$log = get_post_meta( (int) $license_id, AFRISTREAM_LICENSE_LOG_META, true );
	if ( ! is_array( $log ) || empty( $log ) ) {
		return array();
	}

	return array_reverse( $log );
}

/**
 * The most recent time this licence was given to someone.
 *
 * Survives the licence being freed again, which is the case that matters: the
 * reason to look is usually that it is free now and should not be.
 *
 * @param int $license_id Licence post ID.
 * @return array|null
 */
function afristream_license_last_assignment( $license_id ) {
	foreach ( afristream_license_log_get( $license_id ) as $entry ) {
		if ( 'assigned' === $entry['event'] ) {
			return $entry;
		}
	}
	return null;
}

/**
 * Recent events across every licence, newest first.
 *
 * Each entry carries the licence it belongs to, since the merged view loses
 * that context otherwise.
 *
 * @param int $limit Most entries to return.
 * @return array<int,array>
 */
function afristream_license_log_recent( $limit = 20 ) {
	$all = array();

	foreach ( afristream_all_license_ids() as $license_id ) {
		foreach ( afristream_license_log_get( $license_id ) as $entry ) {
			$entry['license'] = $license_id;
			$all[]            = $entry;
		}
	}

	usort(
		$all,
		function ( $a, $b ) {
			if ( $a['time'] !== $b['time'] ) {
				return $b['time'] <=> $a['time'];
			}
			return $b['license'] <=> $a['license'];
		}
	);

	$limit = (int) $limit;
	return $limit > 0 ? array_slice( $all, 0, $limit ) : $all;
}

/**
 * Declare the licence history on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_log_registry( $items ) {
	$items[] = array(
		'group'  => 'Licences',
		'name'   => 'Licence history',
		'type'   => 'field',
		'handle' => AFRISTREAM_LICENSE_LOG_META,
		'file'   => 'includes/license-log.php',
		'status' => array(
			'state' => 'ok',
			/* translators: %d: entries kept per licence. */
			'label' => sprintf( __( 'Last %d events per licence', 'bluegroup-project-afristream' ), AFRISTREAM_LOG_CAP ),
		),
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_log_registry' );
