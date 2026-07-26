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
 * tag, and there is no way to tell from this repo alone. This looks at the
 * live site.
 *
 * It answers with three states, not two, and the third one is the reason this
 * function is worth trusting. An audit that cannot fail is worse than no audit:
 * every way this check can go wrong — a query that times out, a directory it
 * cannot read, a file scan that gives up at its own cap — used to come back
 * indistinguishable from "nothing found", and somebody would deactivate ACF on
 * the strength of it and take the site's Elementor pages down. So each failure
 * path lands in 'undetermined' instead, naming what went unchecked, and
 * 'safe' => true is returned only when every check ran to completion and every
 * one of them came back empty.
 *
 * WHAT IT LOOKS AT
 * - ACF's own field groups, post types and taxonomies, as posts.
 * - ACF dynamic tags inside Elementor's stored JSON.
 * - ACF blocks and [acf…] shortcodes inside post content.
 * - Calls to ACF's API, and references to its acf/* hooks, in the PHP of the
 *   active plugins, the network-activated plugins, the must-use plugins, and
 *   the active theme together with its parent.
 *
 * WHAT IT CANNOT SEE — read this before acting on a clean result
 * - Anything outside the places listed above. Inactive plugins and other
 *   installed themes are never scanned, so activating one after this said safe
 *   proves nothing about it.
 * - ACF reached indirectly: $fn = 'get_field'; $fn( … ), call_user_func(), a
 *   wrapper function in a library, or a name built out of string pieces.
 * - Page builders other than Elementor, and Elementor content held anywhere
 *   other than the _elementor_data meta key — template parts and kit settings
 *   stored elsewhere are not searched.
 * - JavaScript, which can call ACF's REST routes without a line of PHP.
 * - Options, widgets and theme mods, which are not searched at all.
 * - Field *values* read straight out of postmeta with get_post_meta(). Those
 *   are deliberately not looked for: the rows stay in the database when ACF
 *   goes, so that code keeps working, and flagging it would bury the real hits.
 * - Non-PHP files, and any PHP file the scan skipped for being too large.
 *
 * A hit here is strong evidence. A clean result is weaker evidence, and it is
 * only ever offered when nothing at all was left unread.
 *
 * @return array{state:string,elementor:array<int,array{id:int,title:string,type:string}>,plugins:string[],acf_posts:array<int,array{id:int,title:string,type:string}>,content:array<int,array{id:int,title:string,type:string}>,undetermined:string[],safe:bool}
 *         state is one of clean, unsafe, undetermined.
 */
function afristream_acf_audit() {
	global $wpdb;

	$cache_key = afristream_acf_audit_cache_key();
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && isset( $cached['state'], $cached['safe'] ) ) {
		return $cached;
	}

	$elementor    = array();
	$acf_posts    = array();
	$content      = array();
	$plugins      = array();
	$undetermined = array();

	// Elementor stores its tree as JSON in _elementor_data; an ACF dynamic tag
	// appears in it as an "acf-" prefixed name. Revisions are excluded because
	// every save leaves another copy of the same tree and the list would read
	// as a dozen problems where there is one. The only interpolation into the
	// SQL is $wpdb's own table names; the search term is bound.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_elementor_data'
			   AND m.meta_value LIKE %s
			   AND p.post_status != 'trash'
			   AND p.post_type != 'revision'",
			'%' . $wpdb->esc_like( 'acf-' ) . '%'
		)
	);
	// A leading-wildcard LIKE over postmeta is a full table scan, which is
	// exactly the query that times out on a site big enough to have something
	// to lose. null back from get_results() is that failure, and casting it to
	// an empty array would report the most dangerous case as the safest one.
	if ( '' !== (string) $wpdb->last_error ) {
		$undetermined[] = __( 'Elementor content could not be searched — the database query failed or timed out. Any page using an ACF dynamic tag is still unaccounted for.', 'bluegroup-project-afristream' );
	} else {
		foreach ( (array) $rows as $row ) {
			$elementor[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'type'  => (string) $row->post_type,
			);
		}
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
	if ( '' !== (string) $wpdb->last_error ) {
		$undetermined[] = __( "ACF's own field groups, post types and taxonomies could not be listed — the database query failed. Deleting them first is a required step and it cannot be confirmed as done.", 'bluegroup-project-afristream' );
	} else {
		foreach ( (array) $acf_rows as $row ) {
			$acf_posts[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'type'  => (string) $row->post_type,
			);
		}
	}

	// Usage that never goes near Elementor: an ACF block saved by the block
	// editor leaves a "<!-- wp:acf/… -->" comment in post_content, and ACF's
	// shortcode leaves "[acf…]". Both break the same way when ACF goes.
	$content_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title, post_type
			 FROM {$wpdb->posts}
			 WHERE post_status != 'trash'
			   AND post_type != 'revision'
			   AND ( post_content LIKE %s OR post_content LIKE %s )",
			'%' . $wpdb->esc_like( '<!-- wp:acf/' ) . '%',
			'%' . $wpdb->esc_like( '[acf' ) . '%'
		)
	);
	if ( '' !== (string) $wpdb->last_error ) {
		$undetermined[] = __( 'Post content could not be searched — the database query failed or timed out. ACF blocks and [acf…] shortcodes are still unaccounted for.', 'bluegroup-project-afristream' );
	} else {
		foreach ( (array) $content_rows as $row ) {
			$content[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'type'  => (string) $row->post_type,
			);
		}
	}

	// Code that calls ACF. Every place WordPress will actually load PHP from on
	// this request, not just the stylesheet directory: a child theme is close to
	// universal alongside Elementor and the parent is where the template code
	// usually is, must-use plugins load before anything can deactivate them, and
	// on multisite a network-activated plugin never appears in active_plugins.
	foreach ( afristream_acf_scan_targets() as $label => $path ) {
		$verdict = afristream_path_uses_acf( $path );

		if ( 'found' === $verdict ) {
			$plugins[] = (string) $label;
			continue;
		}
		if ( 'incomplete' === $verdict ) {
			$undetermined[] = sprintf(
				/* translators: %s: plugin or theme name. */
				__( '%s could not be read all the way through, so whether it calls ACF is unknown.', 'bluegroup-project-afristream' ),
				(string) $label
			);
		}
	}

	$found = ! empty( $elementor ) || ! empty( $plugins ) || ! empty( $content );

	// Precedence: a hit is decisive and is reported as such even when some other
	// check did not finish, because the answer — do not deactivate ACF — is the
	// same either way and is more use stated plainly. Only when nothing was
	// found does an unfinished check decide the state, and it decides it against
	// "clean" every time.
	if ( $found ) {
		$state = 'unsafe';
	} elseif ( ! empty( $undetermined ) ) {
		$state = 'undetermined';
	} else {
		$state = 'clean';
	}

	$result = array(
		'state'        => $state,
		'elementor'    => $elementor,
		'plugins'      => $plugins,
		'acf_posts'    => $acf_posts,
		'content'      => $content,
		'undetermined' => $undetermined,
		'safe'         => 'clean' === $state,
	);

	set_transient( $cache_key, $result, afristream_acf_audit_ttl( $state ) );

	return $result;
}

/**
 * Where the cached audit is kept.
 *
 * Keyed by blog id so a network install cannot serve one site's answer to
 * another. The sites in a network have different plugins active and different
 * content, and "safe" borrowed from a sibling site is the same wrong answer
 * this whole function exists to avoid.
 *
 * @return string
 */
function afristream_acf_audit_cache_key() {
	return 'afristream_acf_audit_' . (int) get_current_blog_id();
}

/**
 * How long an audit result may be trusted, by state.
 *
 * A clean result is the expensive one to produce — a full filesystem walk plus
 * two table scans — and the things it depends on change when somebody installs
 * a plugin, not minute to minute, so it is held for hours.
 *
 * An undetermined result is held for minutes only. It is not a finding, it is a
 * report of a check that did not finish, and the reasons it does not finish —
 * a busy database, a locked directory — are usually passing. Caching it as long
 * as a clean result would leave a site stuck looking broken for hours after it
 * had recovered, which teaches people to ignore the panel.
 *
 * @param string $state clean, unsafe or undetermined.
 * @return int Seconds.
 */
function afristream_acf_audit_ttl( $state ) {
	if ( 'undetermined' === $state ) {
		return 5 * MINUTE_IN_SECONDS;
	}
	if ( 'unsafe' === $state ) {
		return 15 * MINUTE_IN_SECONDS;
	}
	return 6 * HOUR_IN_SECONDS;
}

/**
 * Every place PHP that could call ACF is loaded from, labelled for display.
 *
 * @return array<string,string> Label => absolute path to a file or directory.
 */
function afristream_acf_scan_targets() {
	$targets = array();

	foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
		if ( afristream_acf_scan_skips( $plugin ) ) {
			continue;
		}
		$targets[ (string) $plugin ] = afristream_active_plugin_path( $plugin );
	}

	// Network-activated plugins are stored as file => activation time, in a
	// different option, and are invisible to active_plugins on every site in the
	// network. On a multisite install this is where ACF-dependent code most
	// often is.
	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin ) {
			if ( afristream_acf_scan_skips( $plugin ) ) {
				continue;
			}
			$targets[ '(network) ' . $plugin ] = afristream_active_plugin_path( $plugin );
		}
	}

	// Must-use plugins cannot be deactivated from the admin, so code in here is
	// the worst place to find an ACF call after the fact.
	if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
		$targets['(must-use plugins)'] = WPMU_PLUGIN_DIR;
	}

	$targets[ '(theme) ' . get_stylesheet() ] = get_stylesheet_directory();

	// The parent of a child theme. Almost every Elementor site runs a child
	// theme, and the template code that uses ACF is usually in the parent.
	if ( get_template_directory() !== get_stylesheet_directory() ) {
		$targets[ '(parent theme) ' . get_template() ] = get_template_directory();
	}

	return $targets;
}

/**
 * Whether a plugin is one of the two there is no point scanning.
 *
 * ACF's own code is full of ACF calls, and so is this plugin's — it replaced
 * ACF, so it names the same functions in its migration and audit code. Either
 * one would report itself as the reason ACF cannot be retired.
 *
 * @param string $plugin Plugin file, relative to the plugins directory.
 * @return bool
 */
function afristream_acf_scan_skips( $plugin ) {
	$plugin = (string) $plugin;

	return 0 === strpos( $plugin, 'advanced-custom-fields' )
		|| 0 === strpos( $plugin, 'bluegroup-project-afristream' );
}

/**
 * Where an active plugin's code actually lives.
 *
 * A plugin is usually a folder ("akismet/akismet.php") but it can equally be a
 * single file sitting at the top of the plugins directory — hello.php ships
 * with WordPress. dirname() of that is ".", and WP_PLUGIN_DIR . '/.' is the
 * whole plugins directory: the scan would then walk every plugin on the site,
 * find ACF's own source, blame it on hello.php, and burn the file cap doing it.
 *
 * @param string $plugin Plugin file, relative to the plugins directory.
 * @return string Absolute path to a directory or a single file.
 */
function afristream_active_plugin_path( $plugin ) {
	$plugin = ltrim( (string) $plugin, '/' );
	$dir    = dirname( $plugin );

	if ( '.' === $dir || '' === $dir || '/' === $dir ) {
		return WP_PLUGIN_DIR . '/' . basename( $plugin );
	}

	return WP_PLUGIN_DIR . '/' . $dir;
}

/**
 * Whether the PHP at a path calls ACF, for a path that may be either a single
 * file or a directory.
 *
 * A path that is neither is reported as nothing to scan rather than as a
 * failure. An active plugin whose file has been deleted is not loaded by
 * WordPress, so it cannot be calling ACF, and a must-use directory that does
 * not exist holds no code — treating those as unknown would put a permanent
 * warning on a healthy site and teach people to skip past it.
 *
 * @param string $path Absolute path.
 * @return string found, clean, incomplete, or missing.
 */
function afristream_path_uses_acf( $path ) {
	if ( is_file( $path ) ) {
		return afristream_file_uses_acf( $path );
	}
	if ( is_dir( $path ) ) {
		return afristream_dir_uses_acf( $path );
	}
	return 'missing';
}

/**
 * Whether any PHP file under a directory calls ACF's API.
 *
 * Capped and short-circuiting: this runs on an admin page load, not a build
 * step, and reading every line of every plugin would be felt. The cap is the
 * problem, though — WooCommerce on its own has more PHP files than fit under it
 * — so hitting it is reported as "incomplete", never as a finished clean scan.
 *
 * @param string $dir Directory to scan.
 * @return string found, clean, or incomplete.
 */
function afristream_dir_uses_acf( $dir ) {
	$limit   = afristream_acf_scan_file_limit();
	$checked = 0;
	$found   = false;
	$capped  = false;
	$skipped = false;
	$failed  = false;

	try {
		// SELF_FIRST so an unreadable subdirectory is seen and counted rather
		// than silently walked past, and CATCH_GET_CHILD so meeting one does not
		// throw and take the whole admin page down with it.
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				if ( ! $file->isReadable() ) {
					$skipped = true;
				}
				continue;
			}
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			if ( $checked >= $limit ) {
				$capped = true;
				break;
			}
			$checked++;

			// Large files are skipped because reading a multi-megabyte generated
			// file on an admin request is worse than not knowing — but not
			// knowing is what it is, and it is recorded as such.
			if ( $file->getSize() > 2 * MB_IN_BYTES || ! $file->isReadable() ) {
				$skipped = true;
				continue;
			}

			$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $contents ) {
				$skipped = true;
				continue;
			}
			if ( preg_match( afristream_acf_source_pattern(), $contents ) ) {
				$found = true;
				break;
			}
		}
	} catch ( Throwable $e ) {
		// The directory itself could not be opened, or vanished mid-walk. Either
		// way part of it went unread, which is not the same as being clean.
		$failed = true;
	}

	return afristream_acf_scan_verdict( $found, $capped, $skipped, $failed );
}

/**
 * Whether one PHP file calls ACF's API.
 *
 * @param string $file Absolute path to a file.
 * @return string found, clean, or incomplete.
 */
function afristream_file_uses_acf( $file ) {
	if ( 'php' !== strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
		return 'clean';
	}
	if ( ! is_readable( $file ) || filesize( $file ) > 2 * MB_IN_BYTES ) {
		return 'incomplete';
	}

	$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $contents ) {
		return 'incomplete';
	}

	return preg_match( afristream_acf_source_pattern(), $contents ) ? 'found' : 'clean';
}

/**
 * How many PHP files one directory scan will read before giving up.
 *
 * Filterable so a site with one enormous plugin can raise it and get a real
 * answer instead of a permanent "could not finish", and so the truncation
 * behaviour can be exercised in a test without creating eight hundred files.
 *
 * @return int
 */
function afristream_acf_scan_file_limit() {
	return max( 1, (int) apply_filters( 'afristream_acf_scan_file_limit', 800 ) );
}

/**
 * What a completed, capped, or failed scan concludes.
 *
 * Split out from the walk itself because it is the decision that matters and
 * the walk is the part that cannot be stood up in a test: eight hundred files
 * and an unreadable directory are not things to create on the way past. Pure,
 * so the precedence below is pinned down directly.
 *
 * Precedence: a hit stands whatever else happened — the scan stops at the first
 * one, so of course the rest went unread, and that does not make the hit less
 * real. Otherwise anything left unread means the answer is not known, and only
 * a walk that read everything it meant to reports clean.
 *
 * @param bool $found   ACF usage was seen.
 * @param bool $capped  The file cap was reached with files still to go.
 * @param bool $skipped At least one file or subdirectory went unread.
 * @param bool $failed  The walk itself could not be completed.
 * @return string found, incomplete, or clean.
 */
function afristream_acf_scan_verdict( $found, $capped, $skipped, $failed ) {
	if ( $found ) {
		return 'found';
	}
	if ( $capped || $skipped || $failed ) {
		return 'incomplete';
	}
	return 'clean';
}

/**
 * The signature of code that depends on ACF.
 *
 * Two shapes. The first is a call to one of ACF's template or API functions —
 * the read helpers, the repeater helpers, the write helpers, and the
 * registration functions. The second is a reference to one of ACF's own hooks,
 * 'acf/init' and its neighbours, which is how a plugin extends ACF without
 * calling a single one of those functions.
 *
 * Deliberately over-inclusive. A name that merely looks like ACF's costs
 * somebody an afternoon confirming it is a false alarm; a name this misses
 * costs a broken site.
 *
 * @return string PCRE pattern.
 */
function afristream_acf_source_pattern() {
	$functions = array(
		'get_field', 'the_field', 'get_fields', 'the_fields',
		'get_sub_field', 'the_sub_field', 'get_sub_fields',
		'get_field_object', 'get_field_objects',
		'have_rows', 'the_row', 'the_row_index', 'get_row', 'get_row_layout', 'get_row_index',
		'add_row', 'add_sub_row', 'update_row', 'update_sub_row', 'delete_row', 'delete_sub_row',
		'update_field', 'update_sub_field', 'delete_field', 'delete_sub_field',
		'acf_add_local_field_group', 'acf_add_local_field', 'acf_add_options_page',
		'acf_register_block_type', 'acf_register_block', 'register_field_group',
		'acf_form', 'acf_form_head', 'acf_shortcode',
	);

	return '/(?:\b(?:' . implode( '|', $functions ) . ')\s*\()|(?:[\'"]acf\/[a-z0-9_\/-]+[\'"])/i';
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
		<?php if ( 'clean' === $audit['state'] ) : ?>
			<p><?php esc_html_e( 'Every check finished and none of them found anything outside this plugin using ACF. It is safe to delete ACF\'s field groups and post types and deactivate it.', 'bluegroup-project-afristream' ); ?></p>
			<p class="description"><?php esc_html_e( 'This searched Elementor content, post content, and the PHP of the active plugins, must-use plugins and the active theme and its parent. It cannot see ACF called through a variable function name, other page builders, JavaScript, or code in plugins and themes that are not active.', 'bluegroup-project-afristream' ); ?></p>
		<?php elseif ( 'unsafe' === $audit['state'] ) : ?>
			<div class="notice notice-error inline"><p>
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
				<?php if ( ! empty( $audit['content'] ) ) : ?>
					<?php esc_html_e( 'Content with ACF blocks or [acf] shortcodes:', 'bluegroup-project-afristream' ); ?>
					<?php
					$titles = array();
					foreach ( $audit['content'] as $item ) {
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
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'This check did not finish — treat it as "do not know", not as "safe".', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php esc_html_e( 'Nothing was found in the parts that could be read, but these were not checked at all, so deactivating ACF now would be a guess:', 'bluegroup-project-afristream' ); ?>
			</p>
			<ul style="list-style:disc; margin:0 0 1em 2em;">
				<?php foreach ( $audit['undetermined'] as $reason ) : ?>
					<li><?php echo esc_html( $reason ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p><?php esc_html_e( 'Reload this page in a few minutes to try again — the answer is only held briefly while it is unknown.', 'bluegroup-project-afristream' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'undetermined' !== $audit['state'] && ! empty( $audit['undetermined'] ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Some of the checks also did not finish, so there may be more than is listed above:', 'bluegroup-project-afristream' ); ?>
				<?php echo esc_html( implode( ' ', $audit['undetermined'] ) ); ?>
			</p>
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
