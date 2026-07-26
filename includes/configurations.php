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
 * @return array{total:int,available:int,assigned:int,expired:int,unreadable:int}
 */
function afristream_license_stock() {
	$total      = 0;
	$available  = 0;
	$assigned   = 0;
	$expired    = 0;
	$unreadable = 0;

	foreach ( afristream_all_license_ids() as $license_id ) {
		$total++;

		if ( afristream_license_owner( $license_id ) ) {
			$assigned++;
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
		'total'      => $total,
		'available'  => $available,
		'assigned'   => $assigned,
		'expired'    => $expired,
		'unreadable' => $unreadable,
	);
}

/**
 * What still depends on ACF, so it can be retired without guessing.
 *
 * Deactivating ACF blind would break any Elementor page using an ACF dynamic
 * tag, and there is no way to tell from this repo alone. This looks.
 *
 * @return array{elementor:array<int,array{id:int,title:string,type:string}>,plugins:string[],acf_posts:array<int,array{id:int,title:string,type:string}>,safe:bool}
 */
function afristream_acf_audit() {
	global $wpdb;

	$elementor = array();
	$acf_posts = array();
	$plugins   = array();

	// Elementor stores its tree as JSON in _elementor_data; an ACF dynamic tag
	// appears in it as an "acf-" prefixed name.
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_type
		 FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_elementor_data'
		   AND m.meta_value LIKE '%acf-%'
		   AND p.post_status != 'trash'"
	);
	foreach ( (array) $rows as $row ) {
		$elementor[] = array(
			'id'    => (int) $row->ID,
			'title' => (string) $row->post_title,
			'type'  => (string) $row->post_type,
		);
	}

	// ACF's own field groups, post types and taxonomies. These must be deleted
	// before ACF is deactivated, or ACF and this plugin both register the
	// licence post type and the editor shows two of every field.
	$acf_rows = $wpdb->get_results(
		"SELECT ID, post_title, post_type
		 FROM {$wpdb->posts}
		 WHERE post_type IN ( 'acf-field-group', 'acf-post-type', 'acf-taxonomy' )
		   AND post_status != 'trash'"
	);
	foreach ( (array) $acf_rows as $row ) {
		$acf_posts[] = array(
			'id'    => (int) $row->ID,
			'title' => (string) $row->post_title,
			'type'  => (string) $row->post_type,
		);
	}

	// Other active plugins calling ACF's API. The theme is checked too.
	$paths = array();
	foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
		if ( 0 === strpos( $plugin, 'advanced-custom-fields' ) || 0 === strpos( $plugin, 'bluegroup-project-afristream' ) ) {
			continue;
		}
		$paths[ $plugin ] = WP_PLUGIN_DIR . '/' . dirname( $plugin );
	}
	$paths['(theme) ' . get_stylesheet()] = get_stylesheet_directory();

	foreach ( $paths as $label => $dir ) {
		if ( ! is_dir( $dir ) || afristream_dir_uses_acf( $dir ) ) {
			if ( is_dir( $dir ) ) {
				$plugins[] = (string) $label;
			}
		}
	}

	return array(
		'elementor' => $elementor,
		'plugins'   => $plugins,
		'acf_posts' => $acf_posts,
		'safe'      => empty( $elementor ) && empty( $plugins ),
	);
}

/**
 * Whether any PHP file under a directory calls ACF's API.
 *
 * Depth-limited and short-circuiting: this runs on an admin page load, not a
 * build step, and reading every line of every plugin would be felt.
 *
 * @param string $dir Directory to scan.
 * @return bool
 */
function afristream_dir_uses_acf( $dir ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	$checked = 0;
	foreach ( $iterator as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		if ( ++$checked > 800 ) {
			break;
		}
		if ( $file->getSize() > 2 * MB_IN_BYTES ) {
			continue;
		}

		$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $contents && preg_match( '/\b(get_field|the_field|have_rows|get_sub_field|acf_add_local_field_group)\s*\(/', $contents ) ) {
			return true;
		}
	}

	return false;
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
 * @param array|null $status Status array.
 * @return string
 */
function afristream_status_pill( $status ) {
	if ( empty( $status['label'] ) ) {
		return '<span style="color:#6b7280;">—</span>';
	}

	$colours = array(
		'ok'   => array( '#dcfce7', '#166534' ),
		'warn' => array( '#fef9c3', '#854d0e' ),
		'off'  => array( '#f3f4f6', '#374151' ),
		'bad'  => array( '#fee2e2', '#b91c1c' ),
	);
	$state = isset( $status['state'] ) && isset( $colours[ $status['state'] ] ) ? $status['state'] : 'off';
	list( $bg, $fg ) = $colours[ $state ];

	return '<span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1; background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . ';">' . esc_html( $status['label'] ) . '</span>';
}

/**
 * Render the page.
 */
function afristream_render_configurations_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stock     = afristream_license_stock();
	$pending   = afristream_pending_all();
	$over      = afristream_over_allocated();
	$mismatch  = afristream_mirror_mismatches();
	$conflicts = afristream_ownership_conflicts();
	$audit     = afristream_acf_audit();
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
				/* translators: 1: total, 2: available, 3: assigned, 4: expired, 5: unreadable expiry. */
				esc_html__( '%1$d licences — %2$d available, %3$d assigned, %4$d expired, %5$d with an expiry date that could not be read (fix these — they are not being handed out).', 'bluegroup-project-afristream' ),
				(int) $stock['total'],
				(int) $stock['available'],
				(int) $stock['assigned'],
				(int) $stock['expired'],
				(int) $stock['unreadable']
			);
			?>
		</p>

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

		<h2><?php esc_html_e( 'ACF readiness', 'bluegroup-project-afristream' ); ?></h2>
		<?php if ( $audit['safe'] ) : ?>
			<p><?php esc_html_e( 'Nothing outside this plugin uses ACF. It is safe to delete ACF\'s field groups and post types and deactivate it.', 'bluegroup-project-afristream' ); ?></p>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Something still uses ACF — do not deactivate it yet.', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php if ( ! empty( $audit['elementor'] ) ) : ?>
					<?php esc_html_e( 'Elementor content with ACF dynamic tags:', 'bluegroup-project-afristream' ); ?>
					<?php
					$titles = array();
					foreach ( $audit['elementor'] as $item ) {
						$titles[] = $item['title'] . ' (' . $item['type'] . ')';
					}
					echo esc_html( implode( ', ', $titles ) );
					?><br>
				<?php endif; ?>
				<?php if ( ! empty( $audit['plugins'] ) ) : ?>
					<?php esc_html_e( 'Plugins or themes calling ACF:', 'bluegroup-project-afristream' ); ?>
					<?php echo esc_html( implode( ', ', $audit['plugins'] ) ); ?>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $audit['acf_posts'] ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'ACF still defines these, and they must be deleted before it is deactivated or the licence editor will show every field twice:', 'bluegroup-project-afristream' ); ?>
				<?php
				$titles = array();
				foreach ( $audit['acf_posts'] as $item ) {
					$titles[] = $item['title'] . ' (' . $item['type'] . ')';
				}
				echo esc_html( implode( ', ', $titles ) );
				?>
			</p>
		<?php endif; ?>

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
			echo '<td>' . wp_kses_post( afristream_status_pill( $item['status'] ) ) . '</td>';
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
					<tr>
						<td><?php echo esc_html( wp_date( 'j M Y, H:i', $entry['time'] ) ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $entry['license'] ) ); ?>"><?php echo esc_html( get_the_title( $entry['license'] ) ); ?></a></td>
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
