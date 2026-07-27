<?php
/**
 * Licence admin screens and the credentials the portal shows a customer.
 *
 * Everything here reads through includes/fields.php, which owns the data model.
 * There is no ACF dependency left and therefore no function_exists() guards:
 * the fields are the plugin's own now, so there is nothing optional to degrade
 * against.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A user's streaming credentials, one entry per licence they hold.
 *
 * Split from afristream_portal_user_credentials() so it can be tested without a
 * logged-in session.
 *
 * @param int $user_id User ID.
 * @return array<int,array{label:string,user:string,pass:string}>
 */
function afristream_portal_credentials_for_user( $user_id ) {
	$profiles = array();
	$n        = 1;

	foreach ( afristream_user_license_ids( $user_id ) as $license_id ) {
		$username = (string) get_the_title( $license_id );
		$password = (string) afristream_license_meta( $license_id, 'app_password' );

		// A licence with neither is a half-created record, not a profile. Showing
		// it as an empty card reads as the portal being broken.
		if ( '' === $username && '' === $password ) {
			continue;
		}

		$profiles[] = array(
			'label' => 'Profile ' . $n,
			'user'  => $username,
			'pass'  => $password,
		);
		$n++;
	}

	return $profiles;
}

/**
 * The logged-in user's credentials. Shared by the REST endpoint that feeds the
 * portal's Profile tab and by the [user_acf_fields] shortcode.
 *
 * @return array<int,array{label:string,user:string,pass:string}>
 */
function afristream_portal_user_credentials() {
	if ( ! is_user_logged_in() ) {
		return array();
	}
	return afristream_portal_credentials_for_user( get_current_user_id() );
}

/**
 * Free a user's licences when the user is deleted.
 *
 * Going through afristream_unassign_license() rather than deleting the meta
 * means the licences return to the available pool and each one records who it
 * was taken from — which is the whole point of the log when a deletion turns
 * out to have been a mistake.
 *
 * A refused lock or a hand-off that lands mid-release comes back as a WP_Error
 * rather than a bool, and a licence left un-freed that way is a licence owned by
 * a user ID that is about to stop existing: permanently out of stock, invisible
 * to the drain, and invisible to both afristream_mirror_mismatches() and
 * afristream_over_allocated(), which work outwards from users that exist. So a
 * refusal is retried once — the commonest cause is an overlapping request
 * holding the assignment lock for a moment, which a second attempt clears —
 * before it is given up on.
 *
 * The mirror is cleared only when every licence really was freed. Not because
 * the row outlives the deletion — WordPress removes an account's usermeta as
 * part of deleting it, so it is going either way — but because deleting it here
 * is this plugin recording that the licences were released when one of them was
 * not, and afristream_mirror_mismatches() reads that record. Leaving it alone
 * keeps the failure legible for as long as anything can still see it.
 *
 * What outlasts the deletion is the licence itself, still owned by a user ID
 * that no longer resolves to anybody. afristream_orphaned_licenses() reports
 * that state on the Configurations page with a way to release it, and that —
 * not the mirror — is the recovery path.
 *
 * There is no administrator watching a delete_user hook to show a notice to, so
 * a failure is also written to the licence's own history, where whoever next
 * opens that licence will find it.
 *
 * @param int $user_id User being deleted.
 */
function afristream_portal_unassign_licenses_on_user_delete( $user_id ) {
	$failed = array();

	foreach ( afristream_user_license_ids( $user_id ) as $license_id ) {
		if ( is_wp_error( afristream_unassign_license( $license_id, 'user-deleted' ) ) ) {
			$failed[] = $license_id;
		}
	}

	$still_held = array();

	foreach ( $failed as $license_id ) {
		$result = afristream_unassign_license( $license_id, 'user-deleted' );

		if ( is_wp_error( $result ) ) {
			$still_held[] = $license_id;
			afristream_license_log_add(
				$license_id,
				'conflict',
				$user_id,
				'user-deleted',
				$result->get_error_message()
			);
		}
	}

	if ( empty( $still_held ) ) {
		delete_user_meta( $user_id, AFRISTREAM_USER_LICENSE_META );
	}
}
add_action( 'delete_user', 'afristream_portal_unassign_licenses_on_user_delete' );
add_action( 'wpmu_delete_user', 'afristream_portal_unassign_licenses_on_user_delete' );

/**
 * Add the Active Licenses column to the Users table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function afristream_portal_active_licenses_user_column( $columns ) {
	$columns['active_licenses'] = __( 'Active Licenses', 'bluegroup-project-afristream' );
	return $columns;
}
add_filter( 'manage_users_columns', 'afristream_portal_active_licenses_user_column' );

/**
 * Render the Active Licenses column.
 *
 * @param string $value       Existing value.
 * @param string $column_name Column being rendered.
 * @param int    $user_id     User for this row.
 * @return string
 */
function afristream_portal_render_active_licenses_user_column( $value, $column_name, $user_id ) {
	if ( 'active_licenses' !== $column_name ) {
		return $value;
	}

	$titles = array();
	foreach ( afristream_user_license_ids( $user_id ) as $license_id ) {
		$titles[] = get_the_title( $license_id );
	}

	return ! empty( $titles ) ? esc_html( implode( ', ', $titles ) ) : '—';
}
add_filter( 'manage_users_custom_column', 'afristream_portal_render_active_licenses_user_column', 10, 3 );

/**
 * Add Expiry Date, Mobile Active, Connected User and Last Assigned columns to
 * the Licenses table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function afristream_portal_license_columns( $columns ) {
	$new_columns = array();
	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;
		if ( 'title' === $key ) {
			$new_columns['expiry_date']    = __( 'Expiry Date', 'bluegroup-project-afristream' );
			$new_columns['mobile_active']  = __( 'Mobile Active', 'bluegroup-project-afristream' );
			$new_columns['active_license'] = __( 'Connected User', 'bluegroup-project-afristream' );
			$new_columns['last_assigned']  = __( 'Last Assigned', 'bluegroup-project-afristream' );
		}
	}
	return $new_columns;
}
add_filter( 'manage_license_posts_columns', 'afristream_portal_license_columns' );

/**
 * Convert a licence's displayed expiry date to a timestamp.
 *
 * expiry_date is written only as Ymd, and afristream_license_is_available()
 * treats anything else as unreadable so that stock whose expiry can't be
 * trusted is never handed to a customer. The admin column deliberately agrees
 * with that: afristream_portal_render_license_columns() only reaches this
 * function after afristream_license_expiry_is_readable() has confirmed the
 * stored value parses, and by then afristream_license_meta() has already
 * reformatted it to d/m/Y for display — so d/m/Y is the only shape that ever
 * arrives here. The round-trip check below still guards it directly rather
 * than trusting the caller, so this function stays correct even if a future
 * caller feeds it something unchecked.
 *
 * @param string $expiry_date Date in d/m/Y, as returned by afristream_license_meta().
 * @return int|false
 */
function afristream_portal_license_expiry_timestamp( $expiry_date ) {
	if ( empty( $expiry_date ) ) {
		return false;
	}
	$date = DateTime::createFromFormat( 'd/m/Y', $expiry_date );
	if ( $date && $date->format( 'd/m/Y' ) === $expiry_date ) {
		$date->setTime( 0, 0, 0 );
		return $date->getTimestamp();
	}
	return false;
}

/**
 * Render the licence table's custom columns.
 *
 * @param string $column  Column being rendered.
 * @param int    $post_id Licence for this row.
 */
function afristream_portal_render_license_columns( $column, $post_id ) {
	if ( 'expiry_date' === $column ) {
		$expiry_date = afristream_license_meta( $post_id, 'expiry_date' );
		if ( '' === $expiry_date ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}

		// A stored value that afristream_license_expiry_is_readable() cannot parse
		// is not "no expiry" and not a plain date either — it is a corrupted field
		// that afristream_license_is_available() already treats as unusable. The
		// column has to say so plainly rather than printing it as if it were a
		// normal date, or nobody notices there is anything to fix.
		if ( ! afristream_license_expiry_is_readable( $post_id ) ) {
			echo '<span style="color:#b91c1c; font-weight:600;">' . esc_html__( 'Unreadable date', 'bluegroup-project-afristream' ) . '</span> <span style="color:#6b7280;">(' . esc_html( $expiry_date ) . ')</span>';
			return;
		}

		// afristream_license_expiry_is_readable() has already confirmed the stored
		// value parses, so this always returns a real timestamp, never false.
		$timestamp   = afristream_portal_license_expiry_timestamp( $expiry_date );
		$today_start = strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) );

		$is_expired = $timestamp < $today_start;
		$label      = $is_expired ? __( 'Expired', 'bluegroup-project-afristream' ) : __( 'Active', 'bluegroup-project-afristream' );
		$bg_color   = $is_expired ? '#fee2e2' : '#dcfce7';
		$text_color = $is_expired ? '#b91c1c' : '#166534';

		echo '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
		echo '<span>' . esc_html( $expiry_date ) . '</span>';
		echo '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg_color ) . '; color:' . esc_attr( $text_color ) . ';">' . esc_html( $label ) . '</span>';
		echo '</div>';
		return;
	}

	if ( 'mobile_active' === $column ) {
		$is_active  = filter_var( afristream_license_meta( $post_id, 'mobile_active' ), FILTER_VALIDATE_BOOLEAN );
		$label      = $is_active ? __( 'Yes', 'bluegroup-project-afristream' ) : __( 'No', 'bluegroup-project-afristream' );
		$bg_color   = $is_active ? '#dcfce7' : '#f3f4f6';
		$text_color = $is_active ? '#166534' : '#374151';
		echo '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg_color ) . '; color:' . esc_attr( $text_color ) . ';">' . esc_html( $label ) . '</span>';
		return;
	}

	// Who holds it — one meta read now that the licence carries its own owner,
	// where this used to scan every user for a serialized ID.
	if ( 'active_license' === $column ) {
		$owner = afristream_license_owner( $post_id );
		if ( ! $owner ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}
		$user = get_userdata( $owner );
		if ( ! $user ) {
			echo '<span style="color:#b91c1c;">' . esc_html__( 'deleted user', 'bluegroup-project-afristream' ) . '</span>';
			return;
		}
		echo '<a href="' . esc_url( get_edit_user_link( $owner ) ) . '">' . esc_html( $user->display_name ) . '</a>';
		return;
	}

	// The last person it went to, even if it is free now — which is exactly the
	// case where the question gets asked.
	if ( 'last_assigned' === $column ) {
		$last = afristream_license_last_assignment( $post_id );
		if ( ! $last ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}
		$user = $last['user'] ? get_userdata( $last['user'] ) : false;
		$name = $user ? $user->display_name : __( 'deleted user', 'bluegroup-project-afristream' );
		echo '<span>' . esc_html( $name ) . '</span><br>';
		echo '<span style="color:#6b7280; font-size:12px;">' . esc_html( wp_date( 'j M Y', $last['time'] ) );
		if ( ! empty( $last['context'] ) ) {
			echo ' · ' . esc_html( $last['context'] );
		}
		echo '</span>';
	}
}
add_action( 'manage_license_posts_custom_column', 'afristream_portal_render_license_columns', 10, 2 );

/**
 * Make Expiry Date and Mobile Active sortable.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function afristream_portal_license_sortable_columns( $columns ) {
	$columns['expiry_date']   = 'expiry_date';
	$columns['mobile_active'] = 'mobile_active';
	return $columns;
}
add_filter( 'manage_edit-license_sortable_columns', 'afristream_portal_license_sortable_columns' );

/**
 * Sort licences by expiry date or mobile flag.
 *
 * Sorting on a meta key by setting meta_key and ordering on meta_value forces an
 * INNER JOIN, so any licence without that row simply drops out of the list — a
 * licence with no expiry date disappeared from the screen the moment somebody
 * clicked Expiry Date, which reads as licences having been deleted. A meta_query
 * of "has it OR does not have it" is joined the other way, as a LEFT JOIN, so
 * every licence stays in the list and the ones with no value sort together at
 * one end.
 *
 * The order is taken from the request and applied to the named clause. A
 * per-clause direction overrides the query's own 'order', so leaving it out
 * would pin the column to ascending whichever way the arrow was clicked.
 *
 * @param WP_Query $query The query being run.
 */
function afristream_portal_sort_license_columns( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'license' !== $query->get( 'post_type' ) ) {
		return;
	}

	$orderby = $query->get( 'orderby' );
	if ( ! in_array( $orderby, array( 'expiry_date', 'mobile_active' ), true ) ) {
		return;
	}

	$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

	$query->set(
		'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		array(
			'relation'         => 'OR',
			'afristream_value' => array(
				'key'     => $orderby,
				'compare' => 'EXISTS',
			),
			'afristream_none'  => array(
				'key'     => $orderby,
				'compare' => 'NOT EXISTS',
			),
		)
	);
	$query->set( 'orderby', array( 'afristream_value' => $order ) );
}
add_action( 'pre_get_posts', 'afristream_portal_sort_license_columns' );

/**
 * Declare the licence admin columns on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_columns_registry( $items ) {
	$columns = array(
		'active_licenses' => array( 'Users: Active Licenses column', 'manage_users_columns' ),
		'expiry_date'     => array( 'Licences: Expiry Date column', 'manage_license_posts_columns' ),
		'mobile_active'   => array( 'Licences: Mobile Active column', 'manage_license_posts_columns' ),
		'active_license'  => array( 'Licences: Connected User column', 'manage_license_posts_columns' ),
		'last_assigned'   => array( 'Licences: Last Assigned column', 'manage_license_posts_columns' ),
	);

	foreach ( $columns as $handle => $column ) {
		$items[] = array(
			'group'  => 'Admin UI',
			'name'   => $column[0],
			'type'   => 'column',
			'handle' => $handle,
			'file'   => 'includes/licenses.php',
		);
	}

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_columns_registry' );
