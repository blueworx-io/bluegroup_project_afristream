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
	// Scoped to this licence's ID: an unscoped action string mints a token that
	// verifies against every licence, so a nonce grabbed from one edit screen
	// would stay usable against any other for its whole lifetime.
	wp_nonce_field( 'afristream_save_license_' . $post->ID, 'afristream_license_nonce' );

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

			// afristream_license_expiry_ymd() collapses "unset" and "unreadable"
			// to the same blank input, so a corrupted value would otherwise sit
			// invisible with no way to correct it. Surfacing the raw stored
			// string is what makes it fixable.
			if ( ! afristream_license_expiry_is_readable( $post->ID ) ) {
				printf(
					'<p class="description">' . esc_html__( 'Stored value is "%s", which could not be read as a date.', 'bluegroup-project-afristream' ) . '</p>',
					esc_html( afristream_license_meta( $post->ID, $key ) )
				);
			}
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
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['afristream_license_nonce'] ) ), 'afristream_save_license_' . $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$changes = array();

	foreach ( array_keys( afristream_license_fields() ) as $key ) {
		$before = (string) get_post_meta( $post_id, $key, true );
		$after  = isset( $_POST[ $key ] ) ? afristream_license_sanitize_field( $key, $_POST[ $key ] ) : '';

		// A corrupted expiry_date renders as a blank <input type="date">, because
		// there is no Y-m-d to put in it — see afristream_license_expiry_ymd().
		// Submitting that blank back would otherwise read as "clear the field"
		// and delete the one copy of the bad value left to fix. Only this exact
		// combination — a non-empty stored value that fails to parse, met by an
		// empty submission — is left alone; a readable date is still cleared
		// normally, and a corrected date still saves normally.
		if ( 'expiry_date' === $key && '' !== $before && '' === $after && ! afristream_license_expiry_is_readable( $post_id ) ) {
			continue;
		}

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
 * Hooked to transition_post_status rather than publish_license: WordPress fires
 * `{$new_status}_{$post_type}` on every save that resolves to publish, not only
 * on the first draft→publish transition, so publish_license alone would log a
 * spurious "created" on every re-save of an already-published licence.
 * transition_post_status carries the old status too, which is what lets a real
 * transition be told apart from a re-save.
 *
 * The old-status check on its own is not quite enough: a licence taken
 * publish → draft → publish again is a genuine transition each time, and would
 * log "created" twice. An empty history is not a reliable sign of a first
 * publish, though: afristream_save_license_fields() can start a licence's
 * history before this function ever runs, by logging an "updated" entry while
 * the licence is still saved as a draft with a field filled in. So the guard
 * checks specifically for an existing "created" entry, not for any history at
 * all, and is checked in addition to the status transition rather than
 * instead of it.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Post whose status changed.
 */
function afristream_log_license_created( $new_status, $old_status, $post ) {
	if ( 'license' !== $post->post_type ) {
		return;
	}
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	foreach ( afristream_license_log_get( $post->ID ) as $entry ) {
		if ( 'created' === $entry['event'] ) {
			return;
		}
	}

	afristream_license_log_add( $post->ID, 'created', 0, 'admin' );
}
add_action( 'transition_post_status', 'afristream_log_license_created', 10, 3 );

/**
 * What changed between the licences a user holds and the ones just submitted.
 *
 * Separated out and returned rather than acted on so it can be tested, and so
 * the save path touches only what actually changed. Re-saving a profile without
 * altering the selection must write nothing — otherwise every profile save
 * would churn the licence log with events that did not happen.
 *
 * @param int[] $current   Licences the user holds now.
 * @param int[] $submitted Licences chosen in the form.
 * @return array{add:int[],remove:int[]}
 */
function afristream_license_selection_diff( $current, $submitted ) {
	$current = array_values( array_unique( array_map( 'intval', (array) $current ) ) );

	$clean = array();
	foreach ( (array) $submitted as $id ) {
		$id = (int) $id;
		if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
			$clean[] = $id;
		}
	}

	$add    = array_values( array_diff( $clean, $current ) );
	$remove = array_values( array_diff( $current, $clean ) );

	sort( $add );
	sort( $remove );

	return array(
		'add'    => $add,
		'remove' => $remove,
	);
}

/**
 * The Active License field on a user's profile.
 *
 * A multi-select of the licences this user holds plus every free one. A licence
 * held by somebody else is simply not in the list — which is what ACF's
 * relationship query filter did, except that now it is a property of how the
 * options are built rather than a filter that has to be remembered.
 *
 * Gated on edit_users — plural — with no exception for a user's own profile.
 * WordPress's map_meta_cap() reduces the singular edit_user capability to read
 * whenever the target is the current user, so a Subscriber editing themselves
 * would pass an edit_user check, or an "or it's their own profile" escape
 * hatch, and be able to render this field against their own account. edit_users
 * is the capability that does not collapse that way; only an administrator
 * holds it. Hiding the field this way is not itself the security boundary —
 * afristream_save_user_license_field() enforces the same capability
 * independently — but a field that never renders also never mints the nonce a
 * forged request would need.
 *
 * @param WP_User $user User being edited.
 */
function afristream_render_user_license_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}

	$held      = afristream_user_license_ids( $user->ID );
	$available = afristream_available_licenses();
	$options   = array_values( array_unique( array_merge( $held, $available ) ) );
	sort( $options );

	wp_nonce_field( 'afristream_save_user_licenses', 'afristream_user_license_nonce' );
	?>
	<h2><?php esc_html_e( 'AfriStream Licence', 'bluegroup-project-afristream' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="afristream-active-license"><?php esc_html_e( 'Active License', 'bluegroup-project-afristream' ); ?></label></th>
			<td>
				<?php if ( empty( $options ) ) : ?>
					<p><?php esc_html_e( 'No licences are available and this user holds none.', 'bluegroup-project-afristream' ); ?></p>
				<?php else : ?>
					<select id="afristream-active-license" name="afristream_active_license[]" multiple size="<?php echo esc_attr( min( 10, max( 3, count( $options ) ) ) ); ?>" style="min-width:340px;">
						<?php foreach ( $options as $license_id ) : ?>
							<?php
							$expiry = afristream_license_meta( $license_id, 'expiry_date' );
							$label  = get_the_title( $license_id );
							if ( '' !== $expiry ) {
								$label .= ' — expires ' . $expiry;
							}
							?>
							<option value="<?php echo esc_attr( $license_id ); ?>" <?php selected( in_array( $license_id, $held, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Hold Ctrl (Cmd on a Mac) to select more than one. Licences held by another user are not listed. Each one the user holds becomes a profile in the portal.', 'bluegroup-project-afristream' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'afristream_render_user_license_field' );
add_action( 'edit_user_profile', 'afristream_render_user_license_field' );

/**
 * Save the licence selection.
 *
 * Every claim goes through afristream_assign_license() and every release goes
 * through afristream_unassign_license(); both take the same assignment lock, so
 * a licence that was free (or held) when the form rendered but has changed
 * hands by the time it is submitted is refused rather than mishandled.
 *
 * Both functions can refuse to act rather than returning a plain bool — the
 * lock can be held by an overlapping request, an addition can lose a race for
 * the licence, or a release can find the licence already moved to somebody
 * else — and each refusal comes back as a WP_Error. Ignoring that and moving
 * on would leave the administrator believing the save fully succeeded while
 * the customer's actual licences quietly disagree with what the profile screen
 * now shows, so every refusal — on either side of the diff — is collected and
 * handed to afristream_license_refused_notice() instead of being dropped.
 *
 * This is the check that actually authorizes the save — hiding the field in
 * afristream_render_user_license_field() only stops the nonce from being
 * minted, it is not itself authorization, so this must independently refuse a
 * request that reaches it by any other means. It is checked against
 * edit_users, not the singular edit_user, for the same reason the render
 * guard is: WordPress's map_meta_cap() reduces edit_user to read when the
 * target is the current user, so current_user_can( 'edit_user', $user_id )
 * would pass for any logged-in user editing themselves and let a customer
 * assign themselves a licence — the thing they are supposed to buy — even
 * though the licence itself happened to be free. edit_users does not collapse
 * that way; only an administrator holds it.
 *
 * @param int $user_id User being saved.
 */
function afristream_save_user_license_field( $user_id ) {
	if ( ! isset( $_POST['afristream_user_license_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['afristream_user_license_nonce'] ) ), 'afristream_save_user_licenses' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}

	$submitted = isset( $_POST['afristream_active_license'] )
		? array_map( 'intval', (array) wp_unslash( $_POST['afristream_active_license'] ) )
		: array();

	$diff = afristream_license_selection_diff( afristream_user_license_ids( $user_id ), $submitted );

	// A refused removal leaves the licence still assigned to this user, which
	// disagrees with what the just-saved form shows — a different problem from
	// a refused addition, and reported separately rather than lumped in with it.
	$not_removed = array();
	foreach ( $diff['remove'] as $license_id ) {
		$result = afristream_unassign_license( $license_id, 'profile' );
		if ( is_wp_error( $result ) ) {
			$not_removed[] = sprintf(
				/* translators: 1: licence title, 2: reason the removal was refused. */
				__( '%1$s — %2$s', 'bluegroup-project-afristream' ),
				get_the_title( $license_id ),
				$result->get_error_message()
			);
		}
	}

	// afristream_assign_license() distinguishes several ways a claim can fail —
	// taken, unavailable, locked, bad arguments — and its own message already
	// says which one this is, so that message is used rather than guessing at a
	// single reason that will be wrong for the other codes.
	$not_added = array();
	foreach ( $diff['add'] as $license_id ) {
		$result = afristream_assign_license( $license_id, $user_id, 'profile' );
		if ( is_wp_error( $result ) ) {
			$not_added[] = sprintf(
				/* translators: 1: licence title, 2: reason the assignment was refused. */
				__( '%1$s — %2$s', 'bluegroup-project-afristream' ),
				get_the_title( $license_id ),
				$result->get_error_message()
			);
		}
	}

	if ( ! empty( $not_added ) || ! empty( $not_removed ) ) {
		set_transient(
			'afristream_license_refused_' . get_current_user_id(),
			array(
				'not_added'   => $not_added,
				'not_removed' => $not_removed,
			),
			60
		);
	}
}
add_action( 'personal_options_update', 'afristream_save_user_license_field' );
add_action( 'edit_user_profile_update', 'afristream_save_user_license_field' );

/**
 * Tell the admin when a save did not do everything the form showed.
 *
 * A refused addition and a refused removal are told apart rather than folded
 * into one generic warning: a licence that stayed with its previous holder is a
 * different, less alarming problem than one that stayed assigned to somebody
 * else, and each licence's own line carries the specific reason
 * afristream_assign_license() or afristream_unassign_license() gave for it.
 * Silently dropping either would look like the save fully worked, which is
 * worse than the refusal itself.
 */
function afristream_license_refused_notice() {
	$key     = 'afristream_license_refused_' . get_current_user_id();
	$refused = get_transient( $key );
	if ( empty( $refused ) ) {
		return;
	}
	delete_transient( $key );

	$not_added   = isset( $refused['not_added'] ) ? (array) $refused['not_added'] : array();
	$not_removed = isset( $refused['not_removed'] ) ? (array) $refused['not_removed'] : array();

	if ( empty( $not_added ) && empty( $not_removed ) ) {
		return;
	}

	echo '<div class="notice notice-error is-dismissible">';

	if ( ! empty( $not_added ) ) {
		echo '<p>';
		printf(
			/* translators: %s: licence names, each followed by the reason it could not be assigned. */
			esc_html__( 'These licences could not be assigned: %s', 'bluegroup-project-afristream' ),
			esc_html( implode( '; ', $not_added ) )
		);
		echo '</p>';
	}

	if ( ! empty( $not_removed ) ) {
		echo '<p>';
		printf(
			/* translators: %s: licence names, each followed by the reason it could not be removed. */
			esc_html__( 'These licences could not be removed: %s', 'bluegroup-project-afristream' ),
			esc_html( implode( '; ', $not_removed ) )
		);
		echo '</p>';
	}

	echo '</div>';
}
add_action( 'admin_notices', 'afristream_license_refused_notice' );
