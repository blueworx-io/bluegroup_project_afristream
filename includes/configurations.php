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
 * It answers with four states, not two, and the extra two are the reason this
 * function is worth trusting. An audit that cannot fail is worse than no audit:
 * every way this check can go wrong — a query that times out, a directory it
 * cannot read, a file scan that gives up at its own cap or hits a PCRE error
 * partway through — used to come back indistinguishable from "nothing found",
 * and somebody would deactivate ACF on the strength of it and take the site's
 * Elementor pages down. So each failure path lands in 'undetermined' instead,
 * naming what went unchecked. ACF's own field groups, post types and
 * taxonomies still existing get a state of their own too — 'cleanup_needed' —
 * because the remedy is different from either of the others: delete these,
 * rather than investigate a dependency or wait and retry. 'safe' => true is
 * returned only when every check ran to completion, every one of them came
 * back empty, and none of ACF's own posts remain either.
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
 * - Non-PHP files, any PHP file the scan skipped for being too large, and any
 *   file that made the regex engine itself fail partway through — all three
 *   leave the scan incomplete, not clean.
 * - Elementor content, ACF's own posts and flagged post content are each
 *   listed up to a display cap. Past that cap, more may exist than are shown —
 *   that is disclosed on the page, not silently dropped.
 *
 * A hit here is strong evidence. A clean result is weaker evidence, and it is
 * only ever offered when nothing at all was left unread.
 *
 * @return array{state:string,elementor:array<int,array{id:int,title:string,type:string}>,elementor_more:bool,plugins:string[],acf_posts:array<int,array{id:int,title:string,type:string}>,acf_posts_more:bool,content:array<int,array{id:int,title:string,type:string}>,content_more:bool,undetermined:string[],safe:bool}
 *         state is one of clean, cleanup_needed, unsafe, undetermined.
 */
function afristream_acf_audit() {
	global $wpdb;

	$cache_key = afristream_acf_audit_cache_key();
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && isset( $cached['state'], $cached['safe'] ) ) {
		return $cached;
	}

	$elementor      = array();
	$elementor_more = false;
	$acf_posts      = array();
	$acf_posts_more = false;
	$content        = array();
	$content_more   = false;
	$plugins        = array();
	$undetermined   = array();
	$display_limit  = afristream_acf_audit_display_limit();

	// Elementor stores its tree as JSON in _elementor_data; an ACF dynamic tag
	// appears in it as an "acf-" prefixed name. Revisions are excluded because
	// every save leaves another copy of the same tree and the list would read
	// as a dozen problems where there is one. The only interpolation into the
	// SQL is $wpdb's own table names; the search term and the row cap are
	// bound. One extra row is asked for beyond the display cap purely so a
	// full page of results can be told apart from one that happened to stop
	// exactly at the edge.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_elementor_data'
			   AND m.meta_value LIKE %s
			   AND p.post_status != 'trash'
			   AND p.post_type != 'revision'
			 LIMIT %d",
			'%' . $wpdb->esc_like( 'acf-' ) . '%',
			$display_limit + 1
		)
	);
	// A leading-wildcard LIKE over postmeta is a full table scan, which is
	// exactly the query that times out on a site big enough to have something
	// to lose. null back from get_results() is that failure — whether or not
	// last_error was set, since $wpdb->ready being false leaves last_error
	// empty too and hands back a stale, usually-empty last_result — and
	// casting either one to an empty array would report the most dangerous
	// case as the safest one.
	if ( '' !== (string) $wpdb->last_error || null === $rows ) {
		$undetermined[] = __( 'Elementor content could not be searched — the database query failed or timed out. Any page using an ACF dynamic tag is still unaccounted for.', 'bluegroup-project-afristream' );
	} else {
		foreach ( (array) $rows as $row ) {
			$elementor[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'type'  => (string) $row->post_type,
			);
		}
		if ( count( $elementor ) > $display_limit ) {
			$elementor_more = true;
			$elementor      = array_slice( $elementor, 0, $display_limit );
		}
	}

	// ACF's own field groups, post types and taxonomies. These must be deleted
	// before ACF is deactivated, or ACF and this plugin both register the
	// licence post type and the editor shows two of every field. Found rows
	// here get their own 'cleanup_needed' verdict below rather than counting
	// toward 'unsafe' — the fix is deleting these, not investigating a
	// dependency, and folding the two together would bury that difference.
	$acf_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title, post_type
			 FROM {$wpdb->posts}
			 WHERE post_type IN ( 'acf-field-group', 'acf-post-type', 'acf-taxonomy' )
			   AND post_status != 'trash'
			 LIMIT %d",
			$display_limit + 1
		)
	);
	if ( '' !== (string) $wpdb->last_error || null === $acf_rows ) {
		$undetermined[] = __( "ACF's own field groups, post types and taxonomies could not be listed — the database query failed. Deleting them first is a required step and it cannot be confirmed as done.", 'bluegroup-project-afristream' );
	} else {
		foreach ( (array) $acf_rows as $row ) {
			$acf_posts[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'type'  => (string) $row->post_type,
			);
		}
		if ( count( $acf_posts ) > $display_limit ) {
			$acf_posts_more = true;
			$acf_posts      = array_slice( $acf_posts, 0, $display_limit );
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
			   AND ( post_content LIKE %s OR post_content LIKE %s )
			 LIMIT %d",
			'%' . $wpdb->esc_like( '<!-- wp:acf/' ) . '%',
			'%' . $wpdb->esc_like( '[acf' ) . '%',
			$display_limit + 1
		)
	);
	if ( '' !== (string) $wpdb->last_error || null === $content_rows ) {
		$undetermined[] = __( 'Post content could not be searched — the database query failed or timed out. ACF blocks and [acf…] shortcodes are still unaccounted for.', 'bluegroup-project-afristream' );
	} else {
		foreach ( (array) $content_rows as $row ) {
			$content[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
				'type'  => (string) $row->post_type,
			);
		}
		if ( count( $content ) > $display_limit ) {
			$content_more = true;
			$content      = array_slice( $content, 0, $display_limit );
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
	// same either way and is more use stated plainly. ACF's own posts are a hit
	// too, just one with a different remedy, so they outrank an unfinished check
	// the same way. Only when nothing was found at all does an unfinished check
	// decide the state, and it decides it against "clean" every time.
	if ( $found ) {
		$state = 'unsafe';
	} elseif ( ! empty( $acf_posts ) ) {
		$state = 'cleanup_needed';
	} elseif ( ! empty( $undetermined ) ) {
		$state = 'undetermined';
	} else {
		$state = 'clean';
	}

	$result = array(
		'state'          => $state,
		'elementor'      => $elementor,
		'elementor_more' => $elementor_more,
		'plugins'        => $plugins,
		'acf_posts'      => $acf_posts,
		'acf_posts_more' => $acf_posts_more,
		'content'        => $content,
		'content_more'   => $content_more,
		'undetermined'   => $undetermined,
		'safe'           => 'clean' === $state,
	);

	set_transient( $cache_key, $result, afristream_acf_audit_ttl( $state ) );

	return $result;
}

/**
 * Drop the cached audit so a change that can make a 'safe' answer wrong does
 * not go on being read as safe for hours.
 *
 * Hooked to plugin activation, plugin deactivation and a theme switch — the
 * three events after which something that was not there before could now be
 * calling ACF, or something that was could now be gone. It only ever deletes
 * the transient; the next read recomputes it, the same as the very first one.
 * Also called directly from the Configurations page when someone follows the
 * "Recheck now" link there, which is a deliberate, nonce-guarded action and
 * not something a page load triggers by itself — this page stays read-only
 * about the site's actual configuration, and clearing a stale diagnostic is
 * not the same thing as flipping a feature off.
 */
function afristream_acf_audit_invalidate() {
	delete_transient( afristream_acf_audit_cache_key() );
}
add_action( 'activated_plugin', 'afristream_acf_audit_invalidate' );
add_action( 'deactivated_plugin', 'afristream_acf_audit_invalidate' );
add_action( 'switch_theme', 'afristream_acf_audit_invalidate' );

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
 * @param string $state clean, cleanup_needed, unsafe or undetermined.
 * @return int Seconds.
 */
function afristream_acf_audit_ttl( $state ) {
	if ( 'undetermined' === $state ) {
		return 5 * MINUTE_IN_SECONDS;
	}
	if ( 'unsafe' === $state ) {
		return 15 * MINUTE_IN_SECONDS;
	}
	// clean and cleanup_needed both mean every check ran to completion — the
	// expensive part — so both are held the same, long way. A cleanup_needed
	// result does not go stale on its own the way unsafe or undetermined can;
	// it waits on someone deleting ACF's posts, and the "Recheck now" link on
	// the page is what that person is expected to use afterwards.
	return 6 * HOUR_IN_SECONDS;
}

/**
 * How many rows of any one kind — Elementor pages, ACF's own posts, flagged
 * post content — the audit will put into a single paragraph before it stops.
 *
 * Filterable for the same reason the file-scan cap is: a site that genuinely
 * has hundreds of hits can raise it, and the truncation path can be exercised
 * in a test without seeding hundreds of posts.
 *
 * @return int
 */
function afristream_acf_audit_display_limit() {
	return max( 1, (int) apply_filters( 'afristream_acf_audit_display_limit', 50 ) );
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
 * Whether a plugin is one there is no point scanning.
 *
 * ACF's own code is full of ACF calls, and so is this plugin's — it replaced
 * ACF, so it names the same functions in its migration and audit code. Either
 * one would report itself as the reason ACF cannot be retired.
 *
 * Matched by exact folder name, not by prefix. ACF ships as
 * "advanced-custom-fields" (free) or "advanced-custom-fields-pro"; a prefix
 * match also swallows "advanced-custom-fields-multilingual" and
 * "advanced-custom-fields-table-field", which are separate add-on plugins
 * that genuinely depend on ACF and would break exactly like any other ACF
 * dependency once it goes. Skipping them silently would hide a real
 * dependency rather than merely omitting it from the "what this cannot see"
 * list — so it is not skipped at all.
 *
 * @param string $plugin Plugin file, relative to the plugins directory.
 * @return bool
 */
function afristream_acf_scan_skips( $plugin ) {
	$plugin = (string) $plugin;
	$folder = strtolower( dirname( str_replace( '\\', '/', $plugin ) ) );

	if ( in_array( $folder, array( 'advanced-custom-fields', 'advanced-custom-fields-pro' ), true ) ) {
		return true;
	}

	return 0 === strpos( $plugin, 'bluegroup-project-afristream' );
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
 * A path that is genuinely neither is reported as nothing to scan rather than
 * as a failure. An active plugin whose file has been deleted is not loaded by
 * WordPress, so it cannot be calling ACF, and a must-use directory that does
 * not exist holds no code — treating those as unknown would put a permanent
 * warning on a healthy site and teach people to skip past it.
 *
 * But "is_file() and is_dir() both say no" is not by itself proof of that —
 * they answer identically for "nothing here" and for "something here that PHP
 * could not stat", which a permissions problem or a filesystem hiccup can
 * both cause. afristream_path_exists_but_unreadable() is the one place that
 * tells those two apart, and only when it agrees nothing is there does this
 * report 'missing'.
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
	if ( afristream_path_exists_but_unreadable( $path ) ) {
		return 'incomplete';
	}
	return 'missing';
}

/**
 * Whether something is sitting at a path that neither is_file() nor is_dir()
 * could make sense of.
 *
 * Both of those answer identically — false — for a path with nothing there at
 * all and for a path PHP could not stat because of a permissions problem or a
 * filesystem hiccup; neither function distinguishes "not there" from "there,
 * but unreadable". Listing the parent directory does, because reading a
 * directory's own entries does not require stat'ing each one individually —
 * so a name that shows up in that listing but that is_file()/is_dir() could
 * not resolve is something real that went unread, not nothing.
 *
 * @param string $path Absolute path that already failed both is_file() and is_dir().
 * @return bool
 */
function afristream_path_exists_but_unreadable( $path ) {
	$parent = dirname( (string) $path );
	$name   = basename( (string) $path );

	if ( '' === $name || ! is_dir( $parent ) || ! is_readable( $parent ) ) {
		return false;
	}

	$entries = @scandir( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_scandir

	return is_array( $entries ) && in_array( $name, $entries, true );
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

			$matched = preg_match( afristream_acf_source_pattern(), $contents );
			// preg_match() itself can fail — a backtrack or recursion limit on a
			// large or pathological file — and answers false when it does, which
			// is falsy exactly like "0 matches" unless checked for by identity.
			// A regex engine that gave up is not the same as a file read clean.
			if ( false === $matched ) {
				$skipped = true;
				continue;
			}
			if ( $matched ) {
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

	$matched = preg_match( afristream_acf_source_pattern(), $contents );
	// Same distinction as the directory walk: preg_match() returning false is a
	// PCRE failure, not a clean read that happened to match nothing.
	if ( false === $matched ) {
		return 'incomplete';
	}

	return $matched ? 'found' : 'clean';
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

	$pattern = '/(?:\b(?:' . implode( '|', $functions ) . ')\s*\()|(?:[\'"]acf\/[a-z0-9_\/-]+[\'"])/i';

	// Filterable so a test can hand back a pattern PCRE itself cannot evaluate
	// — the only practical way to exercise "the scan engine failed partway
	// through" without crafting a real multi-megabyte pathological file to
	// trigger a genuine backtrack-limit error.
	return apply_filters( 'afristream_acf_source_pattern', $pattern );
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

	// The one deliberate write this read-only page makes: following the
	// "Recheck now" link clears the cached ACF audit before it is read below,
	// so the page that requested a fresh answer is the one that gets it
	// rather than the transient it just asked to be dropped. Nonce-guarded so
	// a crawler or a cached copy of the link cannot fire it on every visit —
	// clearing a diagnostic cache cannot break anything, but it still should
	// only happen because someone clicked it.
	if ( isset( $_GET['afristream_recheck_acf'] ) && check_admin_referer( 'afristream_recheck_acf' ) ) {
		afristream_acf_audit_invalidate();
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
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=afristream-configurations&afristream_recheck_acf=1' ), 'afristream_recheck_acf' ) ); ?>">
				<?php esc_html_e( 'Recheck now', 'bluegroup-project-afristream' ); ?>
			</a>
			<?php esc_html_e( 'The result below can be up to a few hours old — use this after activating, deactivating or deleting something.', 'bluegroup-project-afristream' ); ?>
		</p>
		<?php if ( 'clean' === $audit['state'] ) : ?>
			<p><?php esc_html_e( 'Every check finished and none of them found anything outside this plugin using ACF. It is safe to deactivate it.', 'bluegroup-project-afristream' ); ?></p>
			<p class="description"><?php esc_html_e( 'This searched Elementor content, post content, and the PHP of the active plugins, must-use plugins and the active theme and its parent. It cannot see ACF called through a variable function name, other page builders, JavaScript, or code in plugins and themes that are not active.', 'bluegroup-project-afristream' ); ?></p>
		<?php elseif ( 'cleanup_needed' === $audit['state'] ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Not yet safe to deactivate ACF — its own field groups, post types or taxonomies are still here.', 'bluegroup-project-afristream' ); ?></strong><br>
				<?php esc_html_e( 'Nothing else on the site was found to depend on ACF. But while ACF is active, it and this plugin both register the licence post type and the editor shows every field twice, and once ACF is deactivated these simply stop working. Delete them, then use "Recheck now" above:', 'bluegroup-project-afristream' ); ?>
				<?php
				$titles = array();
				foreach ( $audit['acf_posts'] as $item ) {
					$titles[] = $item['title'] . ' (' . $item['type'] . ')';
				}
				echo esc_html( implode( ', ', $titles ) );
				?>
				<?php if ( ! empty( $audit['acf_posts_more'] ) ) : ?>
					<?php echo esc_html( sprintf( __( ' — showing the first %d; more than that were found.', 'bluegroup-project-afristream' ), count( $audit['acf_posts'] ) ) ); ?>
				<?php endif; ?>
			</p></div>
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
					?>
					<?php if ( ! empty( $audit['elementor_more'] ) ) : ?>
						<?php echo esc_html( sprintf( __( ' — showing the first %d; more than that were found.', 'bluegroup-project-afristream' ), count( $audit['elementor'] ) ) ); ?>
					<?php endif; ?>
					<br>
				<?php endif; ?>
				<?php if ( ! empty( $audit['content'] ) ) : ?>
					<?php esc_html_e( 'Content with ACF blocks or [acf] shortcodes:', 'bluegroup-project-afristream' ); ?>
					<?php
					$titles = array();
					foreach ( $audit['content'] as $item ) {
						$titles[] = $item['title'] . ' (' . $item['type'] . ')';
					}
					echo esc_html( implode( ', ', $titles ) );
					?>
					<?php if ( ! empty( $audit['content_more'] ) ) : ?>
						<?php echo esc_html( sprintf( __( ' — showing the first %d; more than that were found.', 'bluegroup-project-afristream' ), count( $audit['content'] ) ) ); ?>
					<?php endif; ?>
					<br>
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
		<?php if ( 'cleanup_needed' !== $audit['state'] && ! empty( $audit['acf_posts'] ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'ACF still defines these, and they must be deleted before it is deactivated or the licence editor will show every field twice:', 'bluegroup-project-afristream' ); ?>
				<?php
				$titles = array();
				foreach ( $audit['acf_posts'] as $item ) {
					$titles[] = $item['title'] . ' (' . $item['type'] . ')';
				}
				echo esc_html( implode( ', ', $titles ) );
				?>
				<?php if ( ! empty( $audit['acf_posts_more'] ) ) : ?>
					<?php echo esc_html( sprintf( __( ' — showing the first %d; more than that were found.', 'bluegroup-project-afristream' ), count( $audit['acf_posts'] ) ) ); ?>
				<?php endif; ?>
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
