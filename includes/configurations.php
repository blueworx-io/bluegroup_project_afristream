<?php
/**
 * One page that says what this plugin actually does to the site.
 *
 * Read-only on purpose. The value is in being able to trust it, and a page with
 * switches on it is a page that can turn something off by accident.
 *
 * The list is not written here. Each feature file contributes its own entries
 * through the afristream_registry filter, so the page cannot drift from the
 * code the way a hand-maintained list does the first time someone adds a
 * feature and forgets. Status is computed on each load rather than stored, for
 * the same reason.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The groups, in the order they are shown.
 *
 * @return string[]
 */
function afristream_registry_groups() {
	return array( 'Portal', 'Licences', 'Affiliates', 'Content sources', 'Admin UI' );
}

/**
 * Everything the plugin registers, grouped and sorted for display.
 *
 * @return array<int,array>
 */
function afristream_registry() {
	$items  = apply_filters( 'afristream_registry', array() );
	$groups = afristream_registry_groups();
	$clean  = array();

	foreach ( (array) $items as $item ) {
		// An entry without a group, a name and a type cannot be rendered
		// usefully. Dropping it is better than a row of blanks that reads as a
		// broken feature.
		if ( empty( $item['group'] ) || empty( $item['name'] ) || empty( $item['type'] ) ) {
			continue;
		}

		$clean[] = array(
			'group'  => (string) $item['group'],
			'name'   => (string) $item['name'],
			'type'   => (string) $item['type'],
			'handle' => isset( $item['handle'] ) ? (string) $item['handle'] : '',
			'file'   => isset( $item['file'] ) ? (string) $item['file'] : '',
			'status' => isset( $item['status'] ) && is_array( $item['status'] ) ? $item['status'] : null,
		);
	}

	usort(
		$clean,
		function ( $a, $b ) use ( $groups ) {
			$ia = array_search( $a['group'], $groups, true );
			$ib = array_search( $b['group'], $groups, true );

			// A group nobody declared is still shown — just last. Silently
			// dropping it would make the page lie about coverage.
			$ia = ( false === $ia ) ? count( $groups ) : $ia;
			$ib = ( false === $ib ) ? count( $groups ) : $ib;

			if ( $ia !== $ib ) {
				return $ia <=> $ib;
			}
			if ( $a['group'] !== $b['group'] ) {
				return strcmp( $a['group'], $b['group'] );
			}
			return strcmp( $a['name'], $b['name'] );
		}
	);

	return $clean;
}

/**
 * How the licence stock stands right now.
 *
 * Availability is decided by asking afristream_license_is_available() rather
 * than re-deriving it here from the expiry date. That function was made to
 * treat a non-empty expiry it cannot parse as unavailable — deliberately, so a
 * licence whose expiry cannot be trusted is never handed to a customer — and a
 * second, looser check here would quietly disagree with it: a corrupted
 * expiry would count as free stock on this page while the assignment path
 * refuses to hand it out.
 *
 * That still leaves a licence with a corrupted expiry needing somewhere to be
 * counted. It is not available (nobody should be told it is), and it is not
 * quite "expired" either — expired means the date was read and it has passed,
 * and this one's date could not be read at all. It gets a bucket of its own
 * so the page names it as something to go and fix, rather than folding it
 * into a count that hides it.
 *
 * The count runs over every licence, not only the published ones, so that a
 * licence somebody moved to draft cannot vanish from the page while still
 * belonging to a customer. An unowned licence that is not published gets a
 * bucket of its own too: it is not available and it has not expired, and
 * counting it as either would be a plain untruth about stock.
 *
 * @return array{total:int,available:int,assigned:int,expired:int,unreadable:int,unpublished:int}
 */
function afristream_license_stock() {
	$total       = 0;
	$available   = 0;
	$assigned    = 0;
	$expired     = 0;
	$unreadable  = 0;
	$unpublished = 0;

	foreach ( afristream_all_license_ids() as $license_id ) {
		$total++;

		if ( afristream_license_owner( $license_id ) ) {
			$assigned++;
			continue;
		}

		if ( 'publish' !== get_post_status( $license_id ) ) {
			$unpublished++;
			continue;
		}

		if ( ! afristream_license_expiry_is_readable( $license_id ) ) {
			$unreadable++;
			continue;
		}

		if ( afristream_license_is_available( $license_id ) ) {
			$available++;
			continue;
		}

		$expired++;
	}

	return array(
		'total'       => $total,
		'available'   => $available,
		'assigned'    => $assigned,
		'expired'     => $expired,
		'unreadable'  => $unreadable,
		'unpublished' => $unpublished,
	);
}

/**
 * Whether ACF is loaded on this request.
 *
 * This is all that is left of a scanner that used to walk every active plugin,
 * the must-use directory, the theme and its parent, and run three table scans
 * over Elementor's stored JSON and post content — several hundred lines whose
 * entire job was to answer "is it safe to deactivate ACF yet?".
 *
 * That question was answered on 2026-07-27 by deactivating it, and answered
 * again by the review that followed: nothing on the site depends on ACF. This
 * plugin makes no ACF calls at all — includes/fields.php replaced every one.
 * The only two dependants the scanner ever flagged were the headless
 * enhancements plugin, whose calls sit behind function_exists( 'get_fields' )
 * and degrade to an empty array, and SureCart, which has run without a fatal
 * since ACF went. Keeping the scanner to re-answer a settled question meant
 * keeping a panel that told anyone reading it not to deactivate a plugin that
 * was already deactivated, which is how a warning teaches people to ignore it.
 *
 * One hazard genuinely survives, and it is the only one that was ever specific
 * to this plugin rather than to the site: if ACF is reinstalled, its stored
 * copy of the licence post type is still in the database, so it and
 * afristream_register_license_post_type() would both register `license` and
 * the editor would show every field twice. Detecting that needs a function
 * name, not a filesystem walk.
 *
 * get_field is the read helper every ACF install defines;
 * acf_add_local_field_group covers the case of ACF loaded as a library by
 * another plugin rather than activated in its own right, which defines the
 * registration API without necessarily having run its template functions yet.
 *
 * @return bool
 */
function afristream_acf_present() {
	return function_exists( 'get_field' ) || function_exists( 'acf_add_local_field_group' );
}

/**
 * Say where ACF stands, in the tense it actually happened in.
 *
 * Takes the answer rather than looking it up, so both cases can be rendered in
 * a test without a process that has ACF loaded in it.
 *
 * @param bool $present Whether ACF is loaded.
 * @return void
 */
function afristream_render_acf_state( $present ) {
	if ( $present ) {
		?>
		<div class="notice notice-error inline"><p>
			<strong><?php esc_html_e( 'ACF is active again, and it should not be.', 'bluegroup-project-afristream' ); ?></strong><br>
			<?php esc_html_e( 'This plugin replaced ACF and registers the licence post type itself. ACF still holds its own stored copy of that post type and of the licence field groups, so with both running the licence editor shows every field twice and it is not defined which copy a save is written through. Deactivate ACF, or delete its Licenses post type and its two licence field groups if it is needed for something else.', 'bluegroup-project-afristream' ); ?>
		</p></div>
		<?php
		return;
	}
	?>
	<p>
		<?php esc_html_e( 'ACF was retired in 0.20.0 and is not loaded. This plugin reads and writes the licence fields directly, so nothing here depends on it.', 'bluegroup-project-afristream' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Any field groups or post types ACF left behind in the database are inert while it is not running. They are only worth deleting to stop ACF picking them back up if it is ever reinstalled — the licence post type is the one that would clash.', 'bluegroup-project-afristream' ); ?>
	</p>
	<?php
}

/**
 * Add the Configurations menu.
 */
function afristream_add_configurations_page() {
	add_menu_page(
		__( 'Configurations', 'bluegroup-project-afristream' ),
		__( 'Configurations', 'bluegroup-project-afristream' ),
		'manage_options',
		'afristream-configurations',
		'afristream_render_configurations_page',
		'dashicons-list-view',
		58
	);
}
add_action( 'admin_menu', 'afristream_add_configurations_page' );

/**
 * A coloured status pill, or an em dash when a feature reports no status.
 *
 * "unknown" has a colour of its own because it is a real answer, not a missing
 * one. Auto-assignment reports it deliberately, to say it cannot tell a quiet
 * month apart from a broken hook, and painting that in the grey used for "off"
 * told the reader the opposite of what it means — that the feature is switched
 * off. Blue reads as neither working nor disabled, which is the point.
 *
 * An unrecognised state falls through to "unknown" rather than to "off", for
 * the same reason: a state this function has never heard of is something it
 * cannot judge, and saying so is honest where calling it off is a guess.
 *
 * @param array|null $status Status array.
 * @return string Escaped HTML, ready to echo.
 */
function afristream_status_pill( $status ) {
	if ( empty( $status['label'] ) ) {
		return '<span style="color:#6b7280;">—</span>';
	}

	$colours = array(
		'ok'      => array( '#dcfce7', '#166534' ),
		'warn'    => array( '#fef9c3', '#854d0e' ),
		'off'     => array( '#f3f4f6', '#374151' ),
		'bad'     => array( '#fee2e2', '#b91c1c' ),
		'unknown' => array( '#dbeafe', '#1d4ed8' ),
	);
	$state = isset( $status['state'] ) && isset( $colours[ $status['state'] ] ) ? $status['state'] : 'unknown';
	list( $bg, $fg ) = $colours[ $state ];

	return '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . ';">' . esc_html( $status['label'] ) . '</span>';
}

/**
 * Show what a SureCart event that named nobody actually contained.
 *
 * The status line already counts these, and a count on its own is where this
 * plugin's one live failure went to hide: two events arrived, both resolved to
 * no user, no licence was ever assigned automatically, and the page said only
 * that it had happened twice. afristream_record_unresolved_event() had captured
 * the shape of both payloads at the time. Reading it back meant installing a
 * throwaway plugin on a production site, because nothing rendered it.
 *
 * So this prints the recorded shape verbatim. `user_id: missing` next to
 * `customer_id: present` is the whole diagnosis in two words — it says which
 * attribute afristream_user_id_from_surecart() looked for, and which one
 * SureCart sent instead. That is a field name someone can act on without
 * guessing, which is the difference between this panel and the count.
 *
 * Deliberately renders nothing when the list is empty rather than an empty box
 * saying all is well: the status line already carries the healthy case, and a
 * second permanent panel restating it is noise on a page that is mostly read
 * when something is wrong.
 *
 * The keys are a third party's data printed into an admin screen, so they are
 * escaped on the way out like any other untrusted string.
 *
 * @param array<int,array{time:int,type:string,keys:string[]}> $events Recorded events.
 * @return void
 */
function afristream_render_unresolved_events( $events ) {
	if ( empty( $events ) ) {
		return;
	}
	?>
	<div class="notice notice-error inline"><p>
		<strong><?php esc_html_e( 'SureCart events that named nobody:', 'bluegroup-project-afristream' ); ?></strong><br>
		<?php esc_html_e( 'Each of these fired, and no licence was assigned because the payload carried no WordPress user this plugin could recognise. The attributes it looked for are listed against what was actually there — a name marked present where user_id is missing is the field the lookup should be reading.', 'bluegroup-project-afristream' ); ?>
		<br><br>
		<?php foreach ( $events as $event ) : ?>
			<?php
			$when = isset( $event['time'] ) ? (int) $event['time'] : 0;
			$type = isset( $event['type'] ) ? (string) $event['type'] : '';
			$keys = isset( $event['keys'] ) && is_array( $event['keys'] ) ? $event['keys'] : array();
			?>
			<?php if ( $when ) : ?>
				<?php echo esc_html( wp_date( 'j M Y, H:i', $when ) ); ?> —
			<?php endif; ?>
			<code><?php echo esc_html( $type ); ?></code>
			<?php if ( ! empty( $keys ) ) : ?>
				<br><?php echo esc_html( implode( ', ', array_map( 'strval', $keys ) ) ); ?>
			<?php endif; ?>
			<br><br>
		<?php endforeach; ?>
	</p></div>
	<?php
}

/**
 * Render the page.
 */
function afristream_render_configurations_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// The page's one deliberate write: releasing a licence whose owner has been
	// deleted. It is the only way back from that state short of editing the
	// database, so it lives where the state is reported. The nonce is scoped to
	// the licence, so a link for one cannot be replayed against another, and
	// afristream_release_orphaned_license() re-checks both the capability and
	// that the owner really is gone before it frees anything.
	$released = null;
	if ( isset( $_GET['afristream_release_license'] ) ) {
		$release_id = (int) $_GET['afristream_release_license'];
		check_admin_referer( 'afristream_release_license_' . $release_id );
		$released = afristream_release_orphaned_license( $release_id );
	}

	$stock     = afristream_license_stock();
	$pending   = afristream_pending_all();
	$over      = afristream_over_allocated();
	$mismatch  = afristream_mirror_mismatches();
	$orphans   = afristream_orphaned_licenses();
	$conflicts = afristream_ownership_conflicts();
	$registry  = afristream_registry();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Configurations', 'bluegroup-project-afristream' ); ?></h1>
		<p class="description">
			<?php
			printf(
				/* translators: 1: plugin version, 2: settings page link. */
				esc_html__( 'Everything the AfriStream Portal plugin adds to this site. Version %1$s. Settings live on the %2$s page.', 'bluegroup-project-afristream' ),
				esc_html( AFRISTREAM_PORTAL_VERSION ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=bluegroup-project-afristream' ) ) . '">' . esc_html__( 'AfriStream Portal settings', 'bluegroup-project-afristream' ) . '</a>'
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Licences', 'bluegroup-project-afristream' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: total, 2: available, 3: assigned, 4: expired, 5: unreadable expiry, 6: not published. */
				esc_html__( '%1$d licences — %2$d available, %3$d assigned, %4$d expired, %5$d with an expiry date that could not be read (fix these — they are not being handed out), %6$d unassigned and not published (a draft or in the trash, so nobody can be given one).', 'bluegroup-project-afristream' ),
				(int) $stock['total'],
				(int) $stock['available'],
				(int) $stock['assigned'],
				(int) $stock['expired'],
				(int) $stock['unreadable'],
				(int) $stock['unpublished']
			);
			?>
		</p>

		<?php if ( is_wp_error( $released ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $released->get_error_message() ); ?></p></div>
		<?php elseif ( true === $released ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'That licence is free again and back in the pool.', 'bluegroup-project-afristream' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $orphans ) ) : ?>
			<div class="notice notice-error inline"><p>
				<strong><?php esc_html_e( 'Held by an account that no longer exists:', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php esc_html_e( 'These licences cannot be handed to anyone and will not free themselves. Releasing one puts it straight back into the available pool and records it in that licence\'s history.', 'bluegroup-project-afristream' ); ?><br>
				<?php foreach ( $orphans as $orphan ) : ?>
					<?php echo esc_html( get_the_title( $orphan['license'] ) ); ?>
					— <?php echo esc_html( sprintf( __( 'owner was user %d', 'bluegroup-project-afristream' ), $orphan['owner'] ) ); ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=afristream-configurations&afristream_release_license=' . $orphan['license'] ), 'afristream_release_license_' . $orphan['license'] ) ); ?>">
						<?php esc_html_e( 'Release it', 'bluegroup-project-afristream' ); ?>
					</a>
					<br>
				<?php endforeach; ?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $pending ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Waiting for a licence:', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php foreach ( $pending as $entry ) : ?>
					<?php $user = get_userdata( $entry['user'] ); ?>
					<?php echo esc_html( $user ? $user->display_name : '#' . $entry['user'] ); ?>
					— <?php echo esc_html( sprintf( _n( '%d licence', '%d licences', $entry['short'], 'bluegroup-project-afristream' ), $entry['short'] ) ); ?>
					<?php if ( $entry['since'] ) : ?>
						(<?php echo esc_html( human_time_diff( $entry['since'], current_time( 'timestamp' ) ) ); ?>)
					<?php endif; ?>
					<br>
				<?php endforeach; ?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $over ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Holding more licences than they are paying for:', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php esc_html_e( 'Nothing has been taken away — decide for each one.', 'bluegroup-project-afristream' ); ?><br>
				<?php foreach ( $over as $entry ) : ?>
					<?php $user = get_userdata( $entry['user'] ); ?>
					<a href="<?php echo esc_url( get_edit_user_link( $entry['user'] ) ); ?>"><?php echo esc_html( $user ? $user->display_name : '#' . $entry['user'] ); ?></a>
					— <?php echo esc_html( sprintf( __( 'holds %1$d, entitled to %2$d. Surplus:', 'bluegroup-project-afristream' ), $entry['held'], $entry['entitled'] ) ); ?>
					<?php
					$names = array();
					foreach ( $entry['surplus'] as $license_id ) {
						$names[] = get_the_title( $license_id );
					}
					echo esc_html( implode( ', ', $names ) );
					?>
					<br>
				<?php endforeach; ?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $conflicts ) || ! empty( $mismatch ) ) : ?>
			<div class="notice notice-error inline"><p>
				<?php if ( ! empty( $conflicts ) ) : ?>
					<strong><?php esc_html_e( 'Licences that were claimed by two users before the migration:', 'bluegroup-project-afristream' ); ?></strong><br>
					<?php foreach ( $conflicts as $conflict ) : ?>
						<?php echo esc_html( get_the_title( $conflict['license'] ) ); ?>
						— <?php echo esc_html( sprintf( __( 'kept by user %d', 'bluegroup-project-afristream' ), $conflict['kept'] ) ); ?><br>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ( ! empty( $mismatch ) ) : ?>
					<strong><?php esc_html_e( 'Users whose stored licence list disagrees with their licences:', 'bluegroup-project-afristream' ); ?></strong>
					<?php echo esc_html( implode( ', ', array_map( 'strval', $mismatch ) ) ); ?><br>
					<?php esc_html_e( 'Re-saving each profile rebuilds it.', 'bluegroup-project-afristream' ); ?>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>

		<?php afristream_render_unresolved_events( afristream_unresolved_events() ); ?>

		<h2><?php esc_html_e( 'ACF', 'bluegroup-project-afristream' ); ?></h2>
		<?php afristream_render_acf_state( afristream_acf_present() ); ?>

		<h2><?php esc_html_e( 'What this plugin registers', 'bluegroup-project-afristream' ); ?></h2>
		<?php
		$current_group = '';
		foreach ( $registry as $item ) {
			if ( $item['group'] !== $current_group ) {
				if ( '' !== $current_group ) {
					echo '</tbody></table>';
				}
				$current_group = $item['group'];
				echo '<h3>' . esc_html( $current_group ) . '</h3>';
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th style="width:24%;">' . esc_html__( 'Feature', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th style="width:10%;">' . esc_html__( 'Type', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th style="width:24%;">' . esc_html__( 'Handle', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th style="width:20%;">' . esc_html__( 'Source', 'bluegroup-project-afristream' ) . '</th>';
				echo '<th>' . esc_html__( 'Status', 'bluegroup-project-afristream' ) . '</th>';
				echo '</tr></thead><tbody>';
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $item['type'] ) . '</td>';
			echo '<td><code>' . esc_html( $item['handle'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $item['file'] ) . '</code></td>';
			// Not run through wp_kses_post(): this plugin built the markup a few
			// lines above and escaped the only two values in it. Filtering it
			// again would put safecss_filter_attr() in front of the inline style
			// and it drops display:inline-block, which is what makes a pill a
			// pill rather than a strip across the cell.
			echo '<td>' . afristream_status_pill( $item['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		if ( '' !== $current_group ) {
			echo '</tbody></table>';
		}
		?>

		<h2><?php esc_html_e( 'Recent licence events', 'bluegroup-project-afristream' ); ?></h2>
		<?php $recent = afristream_license_log_recent( 20 ); ?>
		<?php if ( empty( $recent ) ) : ?>
			<p><?php esc_html_e( 'Nothing has happened to a licence yet.', 'bluegroup-project-afristream' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'Licence', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'Event', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'User', 'bluegroup-project-afristream' ); ?></th>
					<th><?php esc_html_e( 'By', 'bluegroup-project-afristream' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $recent as $entry ) : ?>
					<?php $user = $entry['user'] ? get_userdata( $entry['user'] ) : false; ?>
					<?php
					// 'raw' because the display form comes back with & already
					// turned into &amp; and esc_url() would be escaping escaped
					// output. It returns null for a licence that has since been
					// deleted or that this user cannot edit, and a log entry is
					// still worth showing without a link.
					$edit_link = get_edit_post_link( $entry['license'], 'raw' );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'j M Y, H:i', $entry['time'] ) ); ?></td>
						<td>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( get_the_title( $entry['license'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( get_the_title( $entry['license'] ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $entry['event'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
						<td><?php echo esc_html( $entry['actor'] . ( $entry['context'] ? ' (' . $entry['context'] . ')' : '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Warn on every admin screen when someone is waiting for a licence.
 *
 * Buried on one page it would be found a week late, and the whole point of
 * queueing rather than dropping the request is that somebody acts on it.
 */
function afristream_pending_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pending = afristream_pending_all();
	if ( empty( $pending ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	printf(
		/* translators: 1: number of users, 2: link to the Configurations page. */
		esc_html( _n( '%1$d AfriStream customer is waiting for a licence — none was available when they paid. %2$s', '%1$d AfriStream customers are waiting for a licence — none was available when they paid. %2$s', count( $pending ), 'bluegroup-project-afristream' ) ),
		count( $pending ),
		'<a href="' . esc_url( admin_url( 'admin.php?page=afristream-configurations' ) ) . '">' . esc_html__( 'View', 'bluegroup-project-afristream' ) . '</a>'
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'afristream_pending_admin_notice' );
