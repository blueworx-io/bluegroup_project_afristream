<?php
/**
 * The licence edit screen and the licence field on a user's profile.
 *
 * Between them these replace both ACF field groups. The markup deliberately
 * uses core's own form-table classes rather than anything bespoke, so the
 * screens look like the rest of wp-admin and not like a plugin's idea of it.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coerce a submitted field value to something safe and expected.
 *
 * A date arrives from <input type="date"> as Y-m-d and is stored as Ymd, which
 * is what ACF stored and what sorting and expiry comparisons rely on. A choice
 * field that arrives as anything outside its own list becomes empty rather than
 * being stored: a value the UI can never produce came from somewhere that
 * should not be trusted.
 *
 * @param string $key Field name.
 * @param mixed  $raw Submitted value.
 * @return string
 */
function afristream_license_sanitize_field( $key, $raw ) {
	$fields = afristream_license_fields();
	if ( ! isset( $fields[ $key ] ) ) {
		return '';
	}

	$value = sanitize_text_field( wp_unslash( (string) $raw ) );
	$type  = $fields[ $key ]['type'];

	if ( 'date' === $type ) {
		if ( '' === $value ) {
			return '';
		}
		$date = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $date->format( 'Ymd' ) : '';
	}

	if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
		return in_array( $value, $fields[ $key ]['choices'], true ) ? $value : '';
	}

	return $value;
}

/**
 * Register both meta boxes on the licence editor.
 */
function afristream_license_meta_boxes() {
	add_meta_box(
		'afristream-license-fields',
		__( 'Licence Details', 'bluegroup-project-afristream' ),
		'afristream_render_license_fields_box',
		'license',
		'normal',
		'high'
	);

	add_meta_box(
		'afristream-license-history',
		__( 'History', 'bluegroup-project-afristream' ),
		'afristream_render_license_history_box',
		'license',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_license', 'afristream_license_meta_boxes' );

/**
 * The four licence fields.
 *
 * @param WP_Post $post Licence being edited.
 */
function afristream_render_license_fields_box( $post ) {
	wp_nonce_field( 'afristream_save_license', 'afristream_license_nonce' );

	$owner = afristream_license_owner( $post->ID );
	echo '<table class="form-table" role="presentation"><tbody>';

	foreach ( afristream_license_fields() as $key => $field ) {
		$id = 'afristream-field-' . $key;
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th>';
		echo '<td>';

		if ( 'date' === $field['type'] ) {
			$ymd   = afristream_license_expiry_ymd( $post->ID );
			$value = '' === $ymd ? '' : substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
			echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
		} elseif ( 'select' === $field['type'] ) {
			$value = afristream_license_meta( $post->ID, $key );
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
			echo '<option value="">' . esc_html__( '— Select —', 'bluegroup-project-afristream' ) . '</option>';
			foreach ( $field['choices'] as $choice ) {
				echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $value, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'radio' === $field['type'] ) {
			$value = afristream_license_meta( $post->ID, $key );
			echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
			foreach ( $field['choices'] as $choice ) {
				echo '<label style="margin-right:16px;"><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $choice ) . '" ' . checked( $value, $choice, false ) . ' /> ' . esc_html( $choice ) . '</label>';
			}
			echo '<label><input type="radio" name="' . esc_attr( $key ) . '" value="" ' . checked( $value, '', false ) . ' /> ' . esc_html__( 'Not set', 'bluegroup-project-afristream' ) . '</label>';
			echo '</fieldset>';
		} else {
			$value = afristream_license_meta( $post->ID, $key );
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
		}

		echo '</td></tr>';
	}

	echo '</tbody></table>';

	echo '<p class="description">';
	if ( $owner ) {
		$user = get_userdata( $owner );
		printf(
			/* translators: %s: linked user name. */
			esc_html__( 'Assigned to %s. Change the assignment from that user\'s profile.', 'bluegroup-project-afristream' ),
			'<a href="' . esc_url( get_edit_user_link( $owner ) ) . '">' . esc_html( $user ? $user->display_name : '#' . $owner ) . '</a>'
		);
	} else {
		esc_html_e( 'Not assigned to anyone — available for auto-assignment.', 'bluegroup-project-afristream' );
	}
	echo '</p>';
}

/**
 * The licence's history, newest first.
 *
 * @param WP_Post $post Licence being edited.
 */
function afristream_render_license_history_box( $post ) {
	$log = afristream_license_log_get( $post->ID );

	if ( empty( $log ) ) {
		echo '<p>' . esc_html__( 'Nothing has happened to this licence yet.', 'bluegroup-project-afristream' ) . '</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'When', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'Event', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'User', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'By', 'bluegroup-project-afristream' ) . '</th>';
	echo '<th>' . esc_html__( 'Detail', 'bluegroup-project-afristream' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $log as $entry ) {
		$user      = $entry['user'] ? get_userdata( $entry['user'] ) : false;
		$user_cell = '—';
		if ( $user ) {
			$user_cell = '<a href="' . esc_url( get_edit_user_link( $entry['user'] ) ) . '">' . esc_html( $user->display_name ) . '</a>';
		} elseif ( $entry['user'] ) {
			$user_cell = esc_html__( 'deleted user', 'bluegroup-project-afristream' ) . ' #' . (int) $entry['user'];
		}

		$by = $entry['actor'];
		if ( ! empty( $entry['context'] ) ) {
			$by .= ' (' . $entry['context'] . ')';
		}

		echo '<tr>';
		echo '<td>' . esc_html( wp_date( 'j M Y, H:i', $entry['time'] ) ) . '</td>';
		echo '<td>' . esc_html( $entry['event'] ) . '</td>';
		echo '<td>' . wp_kses_post( $user_cell ) . '</td>';
		echo '<td>' . esc_html( $by ) . '</td>';
		echo '<td>' . esc_html( $entry['detail'] ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

/**
 * Save the licence fields, recording any change in the licence's history.
 *
 * The before-and-after is written to the log rather than just the new value:
 * "app_password changed" is not much use six weeks later, and the old value is
 * exactly what someone reverting a mistake needs.
 *
 * @param int $post_id Licence post ID.
 */
function afristream_save_license_fields( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['afristream_license_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['afristream_license_nonce'] ) ), 'afristream_save_license' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$changes = array();

	foreach ( array_keys( afristream_license_fields() ) as $key ) {
		$before = (string) get_post_meta( $post_id, $key, true );
		$after  = isset( $_POST[ $key ] ) ? afristream_license_sanitize_field( $key, $_POST[ $key ] ) : '';

		if ( $before === $after ) {
			continue;
		}

		if ( '' === $after ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $after );
		}

		$changes[] = sprintf(
			'%s %s → %s',
			$key,
			'' === $before ? '(empty)' : $before,
			'' === $after ? '(empty)' : $after
		);
	}

	if ( ! empty( $changes ) ) {
		afristream_license_log_add( $post_id, 'updated', afristream_license_owner( $post_id ), 'admin', implode( ', ', $changes ) );
	}
}
add_action( 'save_post_license', 'afristream_save_license_fields' );

/**
 * Record a licence being created, so its history starts at the beginning.
 *
 * @param int $post_id Licence post ID.
 */
function afristream_log_license_created( $post_id ) {
	afristream_license_log_add( $post_id, 'created', 0, 'admin' );
}
add_action( 'publish_license', 'afristream_log_license_created', 10, 1 );
