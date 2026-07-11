<?php
/**
 * License management + user credentials.
 *
 * Ported from the standalone "afristream code snippets" so the snippets plugin
 * can be retired. Everything ACF-dependent is guarded with function_exists()
 * so the plugin degrades gracefully (rather than fatally) if ACF is inactive.
 *
 * Data model (defined in ACF / the `license` CPT, not here):
 *   - User field  `active_license`  — relationship to one or more license posts
 *   - License field `app_password`  — the streaming password for that license
 *   - License field `expiry_date`, `mobile_active` — admin metadata
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The current logged-in user's streaming credentials, one entry per assigned
 * license. Returns an empty array when the user is logged out, ACF is
 * unavailable, or no license is assigned. Shared by the REST endpoint (which
 * feeds the portal's Profile tab) and the [user_acf_fields] shortcode.
 *
 * @return array<int,array{label:string,user:string,pass:string}>
 */
function afristream_portal_user_credentials() {
	if ( ! is_user_logged_in() || ! function_exists( 'get_field' ) ) {
		return array();
	}

	$acf_user_id    = 'user_' . get_current_user_id();
	$active_license = get_field( 'active_license', $acf_user_id );
	if ( empty( $active_license ) ) {
		return array();
	}
	if ( ! is_array( $active_license ) ) {
		$active_license = array( $active_license );
	}

	$profiles = array();
	$n        = 1;
	foreach ( $active_license as $license ) {
		$license_id = is_object( $license ) ? $license->ID : $license;
		if ( ! $license_id ) {
			continue;
		}
		$username = (string) get_the_title( $license_id );
		$password = (string) get_field( 'app_password', $license_id );
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
 * Get the user ID currently being edited in ACF user fields.
 */
function afristream_portal_current_acf_user_id() {
	// Reading admin request context to scope a de-dupe query; no state change.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_GET['user_id'] ) ) {
		return (int) $_GET['user_id'];
	}
	if ( ! empty( $_POST['user_id'] ) ) {
		return (int) $_POST['user_id'];
	}
	if ( ! empty( $_GET['user'] ) ) {
		return (int) $_GET['user'];
	}
	if ( ! empty( $_POST['user'] ) ) {
		return (int) $_POST['user'];
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( is_admin() && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && 'profile' === $screen->base ) {
			return get_current_user_id();
		}
	}

	return 0;
}

/**
 * Get all license IDs assigned to users, excluding one user if needed.
 */
function afristream_portal_assigned_license_ids( $exclude_user_id = 0 ) {
	if ( ! function_exists( 'get_field' ) ) {
		return array();
	}

	$assigned_license_ids = array();

	$users = get_users(
		array(
			'fields'   => array( 'ID' ),
			'meta_key' => 'active_license', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'number'   => -1,
		)
	);

	foreach ( $users as $user ) {
		if ( (int) $user->ID === (int) $exclude_user_id ) {
			continue;
		}

		$licenses = get_field( 'active_license', 'user_' . $user->ID );
		if ( empty( $licenses ) ) {
			continue;
		}
		if ( ! is_array( $licenses ) ) {
			$licenses = array( $licenses );
		}

		foreach ( $licenses as $license ) {
			$license_id = is_object( $license ) ? (int) $license->ID : (int) $license;
			if ( $license_id ) {
				$assigned_license_ids[] = $license_id;
			}
		}
	}

	return array_values( array_unique( $assigned_license_ids ) );
}

/**
 * Hide already-assigned licenses from the ACF relationship field.
 */
add_filter(
	'acf/fields/relationship/query/name=active_license',
	function ( $args, $field, $post_id ) {
		$current_user_id      = afristream_portal_current_acf_user_id();
		$excluded_license_ids = afristream_portal_assigned_license_ids( $current_user_id );

		if ( ! empty( $excluded_license_ids ) ) {
			$args['post__not_in'] = $excluded_license_ids;
		}

		return $args;
	},
	10,
	3
);

/**
 * Safety check: prevent saving a license already assigned to another user.
 */
add_filter(
	'acf/validate_value/name=active_license',
	function ( $valid, $value, $field, $input ) {
		if ( true !== $valid ) {
			return $valid;
		}
		if ( empty( $value ) ) {
			return $valid;
		}

		$current_user_id  = afristream_portal_current_acf_user_id();
		$already_assigned = afristream_portal_assigned_license_ids( $current_user_id );

		$submitted_ids = is_array( $value ) ? array_map( 'intval', $value ) : array( (int) $value );

		foreach ( $submitted_ids as $license_id ) {
			if ( in_array( $license_id, $already_assigned, true ) ) {
				return 'One or more selected licenses are already assigned to another user.';
			}
		}

		return $valid;
	},
	10,
	4
);

/**
 * Auto-unassign licenses when a user is deleted.
 */
function afristream_portal_unassign_licenses_on_user_delete( $user_id ) {
	if ( function_exists( 'delete_field' ) ) {
		delete_field( 'active_license', 'user_' . $user_id );
	}
}
add_action( 'delete_user', 'afristream_portal_unassign_licenses_on_user_delete' );
add_action( 'wpmu_delete_user', 'afristream_portal_unassign_licenses_on_user_delete' );

/**
 * Add Active Licenses column to Users table.
 */
function afristream_portal_active_licenses_user_column( $columns ) {
	$columns['active_licenses'] = 'Active Licenses';
	return $columns;
}
add_filter( 'manage_users_columns', 'afristream_portal_active_licenses_user_column' );

/**
 * Render Active Licenses column content.
 */
function afristream_portal_render_active_licenses_user_column( $value, $column_name, $user_id ) {
	if ( 'active_licenses' !== $column_name ) {
		return $value;
	}
	if ( ! function_exists( 'get_field' ) ) {
		return '—';
	}

	$licenses = get_field( 'active_license', 'user_' . $user_id );
	if ( empty( $licenses ) ) {
		return '—';
	}
	if ( ! is_array( $licenses ) ) {
		$licenses = array( $licenses );
	}

	$output = array();
	foreach ( $licenses as $license ) {
		$license_id = is_object( $license ) ? $license->ID : $license;
		if ( ! $license_id ) {
			continue;
		}
		$output[] = get_the_title( $license_id );
	}

	return ! empty( $output ) ? esc_html( implode( ', ', $output ) ) : '—';
}
add_filter( 'manage_users_custom_column', 'afristream_portal_render_active_licenses_user_column', 10, 3 );

/**
 * Add Expiry Date, Mobile Active, and Connected User columns to the License
 * post type table.
 */
function afristream_portal_license_columns( $columns ) {
	$new_columns = array();
	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;
		if ( 'title' === $key ) {
			$new_columns['expiry_date']    = 'Expiry Date';
			$new_columns['mobile_active']  = 'Mobile Active';
			$new_columns['active_license'] = 'Connected User';
		}
	}
	return $new_columns;
}
add_filter( 'manage_license_posts_columns', 'afristream_portal_license_columns' );

/**
 * Convert an expiry date string to a timestamp, tolerant of several formats.
 */
function afristream_portal_license_expiry_timestamp( $expiry_date ) {
	if ( empty( $expiry_date ) ) {
		return false;
	}
	$formats = array( 'd/m/Y', 'Y-m-d', 'Ymd', 'm/d/Y' );
	foreach ( $formats as $format ) {
		$date = DateTime::createFromFormat( $format, $expiry_date );
		if ( $date && $date->format( $format ) === $expiry_date ) {
			$date->setTime( 0, 0, 0 );
			return $date->getTimestamp();
		}
	}
	$fallback = strtotime( $expiry_date );
	return $fallback ? $fallback : false;
}

/**
 * Render Expiry Date, Mobile Active, and Connected User column content.
 */
function afristream_portal_render_license_columns( $column, $post_id ) {
	if ( ! in_array( $column, array( 'expiry_date', 'mobile_active', 'active_license' ), true ) ) {
		return;
	}
	if ( ! function_exists( 'get_field' ) ) {
		echo '<span style="color:#6b7280;">—</span>';
		return;
	}

	// -- Expiry Date --
	if ( 'expiry_date' === $column ) {
		$expiry_date = get_field( 'expiry_date', $post_id );
		if ( empty( $expiry_date ) ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}
		$timestamp   = afristream_portal_license_expiry_timestamp( $expiry_date );
		$today        = current_time( 'timestamp' );
		$today_start  = strtotime( gmdate( 'Y-m-d', $today ) );
		if ( ! $timestamp ) {
			echo '<span>' . esc_html( $expiry_date ) . '</span>';
			return;
		}
		$is_expired = $timestamp < $today_start;
		$label      = $is_expired ? 'Expired' : 'Active';
		$bg_color   = $is_expired ? '#fee2e2' : '#dcfce7';
		$text_color = $is_expired ? '#b91c1c' : '#166534';
		echo '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
		echo '<span>' . esc_html( $expiry_date ) . '</span>';
		echo '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg_color ) . '; color:' . esc_attr( $text_color ) . ';">' . esc_html( $label ) . '</span>';
		echo '</div>';
	}

	// -- Mobile Active --
	if ( 'mobile_active' === $column ) {
		$mobile_active = get_field( 'mobile_active', $post_id );
		$is_active     = filter_var( $mobile_active, FILTER_VALIDATE_BOOLEAN );
		$label         = $is_active ? 'Yes' : 'No';
		$bg_color      = $is_active ? '#dcfce7' : '#f3f4f6';
		$text_color    = $is_active ? '#166534' : '#374151';
		echo '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg_color ) . '; color:' . esc_attr( $text_color ) . ';">' . esc_html( $label ) . '</span>';
	}

	// -- Connected User (reverse lookup via active_license on the User) --
	if ( 'active_license' === $column ) {
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'active_license',
						'value'   => '"' . $post_id . '"', // ACF stores a relationship as a serialized array.
						'compare' => 'LIKE',
					),
				),
				'number'     => 10,
			)
		);

		if ( empty( $users ) ) {
			echo '<span style="color:#6b7280;">—</span>';
			return;
		}

		$links = array();
		foreach ( $users as $user ) {
			$edit_url = get_edit_user_link( $user->ID );
			$links[]  = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $user->display_name ) . '</a>';
		}

		echo wp_kses_post( implode( '<br>', $links ) );
	}
}
add_action( 'manage_license_posts_custom_column', 'afristream_portal_render_license_columns', 10, 2 );

/**
 * Make Expiry Date and Mobile Active columns sortable.
 */
function afristream_portal_license_sortable_columns( $columns ) {
	$columns['expiry_date']   = 'expiry_date';
	$columns['mobile_active'] = 'mobile_active';
	return $columns;
}
add_filter( 'manage_edit-license_sortable_columns', 'afristream_portal_license_sortable_columns' );

/**
 * Sort License posts by the expiry_date or mobile_active ACF field.
 */
function afristream_portal_sort_license_columns( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'license' !== $query->get( 'post_type' ) ) {
		return;
	}
	$orderby = $query->get( 'orderby' );
	if ( 'expiry_date' === $orderby ) {
		$query->set( 'meta_key', 'expiry_date' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$query->set( 'orderby', 'meta_value' );
	}
	if ( 'mobile_active' === $orderby ) {
		$query->set( 'meta_key', 'mobile_active' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$query->set( 'orderby', 'meta_value' );
	}
}
add_action( 'pre_get_posts', 'afristream_portal_sort_license_columns' );
