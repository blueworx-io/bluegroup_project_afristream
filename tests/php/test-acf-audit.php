<?php
/**
 * The ACF-readiness audit.
 *
 * Every test here exists because of one property: this audit is acted on. A
 * site owner reads "safe", deletes ACF's field groups and deactivates it, and
 * if the audit was wrong the Elementor pages go down. So the assertions below
 * care much less about the happy path than about every way the check can fail —
 * a query that times out, a directory that will not open, a file scan that hits
 * its own cap — and about the one thing that must never happen, which is any of
 * those coming back as 'safe' => true.
 */

require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';
require_once __DIR__ . '/../../includes/configurations.php';

/**
 * Cap the file scan, so truncation can be exercised without creating the eight
 * hundred files it would otherwise take.
 *
 * @param int $limit Files to read per directory.
 */
function af_cap_acf_scan( $limit ) {
	add_filter(
		'afristream_acf_scan_file_limit',
		function () use ( $limit ) {
			return $limit;
		}
	);
}

// -- A query that fails is never a clean site ---------------------------------

af_test( 'a failed Elementor query is undetermined, not safe', function () {
	af_wpdb_fail( 'elementor' );

	$audit = afristream_acf_audit();

	af_assert_same( 'undetermined', $audit['state'], 'the state says so plainly' );
	af_assert_same( false, $audit['safe'], 'and safe is false — this is the case that used to read as clean' );
	af_assert_same( 1, count( $audit['undetermined'] ), 'one thing went unchecked' );
	af_assert( false !== strpos( $audit['undetermined'][0], 'Elementor' ), 'and it names Elementor as the thing it could not search' );
} );

af_test( 'a failed ACF post-type query is undetermined, not safe', function () {
	af_wpdb_fail( 'acf_posts' );

	$audit = afristream_acf_audit();

	af_assert_same( 'undetermined', $audit['state'], 'undetermined' );
	af_assert_same( false, $audit['safe'], 'never safe' );
	af_assert_same( array(), $audit['acf_posts'], 'and no field groups are claimed to have been found' );
} );

af_test( 'a failed post-content query is undetermined, not safe', function () {
	af_wpdb_fail( 'content' );

	$audit = afristream_acf_audit();

	af_assert_same( 'undetermined', $audit['state'], 'undetermined' );
	af_assert_same( false, $audit['safe'], 'never safe' );
} );

af_test( 'the database being gone entirely is undetermined, not safe', function () {
	af_wpdb_fail( '*' );

	$audit = afristream_acf_audit();

	af_assert_same( 'undetermined', $audit['state'], 'undetermined' );
	af_assert_same( false, $audit['safe'], 'never safe' );
	af_assert_same( 3, count( $audit['undetermined'], COUNT_NORMAL ), 'all three queries are named as unchecked' );
} );

af_test( 'a query that failed is not silently mistaken for an empty result', function () {
	// The specific bug: get_results() answers null on failure, (array) null is
	// array(), and an empty array reads exactly like "nothing uses ACF".
	global $wpdb;

	af_wpdb_fail( 'elementor' );
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

	af_assert_same( null, $rows, 'null, the way the real one answers' );
	af_assert_same( array(), (array) $rows, 'which casts to the same empty array a clean site gives' );
	af_assert( '' !== $wpdb->last_error, 'so last_error is the only thing that tells them apart' );
} );

// -- A scan that stopped early is never a clean scan ---------------------------

af_test( 'a directory scan that hits its file cap is undetermined, not clean', function () {
	af_cap_acf_scan( 1 );
	update_option( 'active_plugins', array( 'clean-plugin/clean-plugin.php' ) );

	$audit = afristream_acf_audit();

	af_assert_same( 'undetermined', $audit['state'], 'the scan stopped with files still to read' );
	af_assert_same( false, $audit['safe'], 'so it cannot claim safe — WooCommerce alone is past this cap' );
	af_assert_same( array(), $audit['plugins'], 'nothing is accused of using ACF either' );
	af_assert( false !== strpos( $audit['undetermined'][0], 'clean-plugin' ), 'the plugin that went unread is named' );
} );

af_test( 'the scan verdict puts a hit above everything and a gap above clean', function () {
	// The truncation decision itself, pinned down directly. Eight hundred files
	// and an unreadable directory are not things to create on the way past, so
	// this is the part of the walk that can be tested and is.
	af_assert_same( 'found', afristream_acf_scan_verdict( true, false, false, false ), 'a plain hit' );
	af_assert_same( 'found', afristream_acf_scan_verdict( true, true, true, true ), 'a hit stands even though the scan then stopped — it stopped because of the hit' );
	af_assert_same( 'incomplete', afristream_acf_scan_verdict( false, true, false, false ), 'hitting the file cap' );
	af_assert_same( 'incomplete', afristream_acf_scan_verdict( false, false, true, false ), 'skipping a file that was too big to read' );
	af_assert_same( 'incomplete', afristream_acf_scan_verdict( false, false, false, true ), 'the walk itself failing' );
	af_assert_same( 'clean', afristream_acf_scan_verdict( false, false, false, false ), 'and only a walk that read everything reports clean' );
} );

af_test( 'an unreadable directory is undetermined rather than fatal', function () {
	// RecursiveDirectoryIterator throws when handed a path it cannot open. That
	// used to take the whole admin page with it; now the walk is caught and the
	// result is the honest one.
	$verdict = afristream_dir_uses_acf( __DIR__ . '/fixtures/does-not-exist' );

	af_assert_same( 'incomplete', $verdict, 'not clean, and not a fatal error either' );
} );

// -- Things that genuinely use ACF ---------------------------------------------

af_test( 'an ACF dynamic tag in Elementor data is unsafe', function () {
	af_seed_post( 40, 'Home', 'publish', 'page' );
	update_post_meta( 40, '_elementor_data', '[{"settings":{"__dynamic__":{"title":"[elementor-tag name=\"acf-text\"]"}}}]' );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'this is the page that would break' );
	af_assert_same( false, $audit['safe'], 'not safe' );
	af_assert_same( 1, count( $audit['elementor'] ), 'one page named' );
	af_assert_same( 'Home', $audit['elementor'][0]['title'], 'by title, so it can be found and fixed' );
} );

af_test( 'a revision of an Elementor page is not counted again', function () {
	af_seed_post( 41, 'Home', 'publish', 'page' );
	update_post_meta( 41, '_elementor_data', '[{"name":"acf-text"}]' );
	af_seed_post( 42, 'Home', 'inherit', 'revision' );
	update_post_meta( 42, '_elementor_data', '[{"name":"acf-text"}]' );

	$audit = afristream_acf_audit();

	af_assert_same( 1, count( $audit['elementor'] ), 'one row, not one per save' );
	af_assert_same( 41, $audit['elementor'][0]['id'], 'the page itself, not its revision' );
} );

af_test( 'an active plugin calling ACF is unsafe', function () {
	update_option( 'active_plugins', array( 'acf-plugin/acf-plugin.php' ) );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'unsafe' );
	af_assert_same( false, $audit['safe'], 'not safe' );
	af_assert_same( array( 'acf-plugin/acf-plugin.php' ), $audit['plugins'], 'named so it can be looked at' );
} );

af_test( 'a plugin that only hooks acf/init is caught too', function () {
	// It never calls get_field(), so the old function-name-only regex saw
	// nothing here at all.
	update_option( 'active_plugins', array( 'hook-plugin/hook-plugin.php' ) );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'extending ACF counts as using it' );
	af_assert_same( array( 'hook-plugin/hook-plugin.php' ), $audit['plugins'], 'named' );
} );

af_test( 'an ACF block in post content is unsafe', function () {
	af_seed_post( 43, 'About', 'publish', 'page', '<!-- wp:acf/testimonial {"name":"acf/testimonial"} /-->' );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'a block editor site can depend on ACF without an Elementor page in sight' );
	af_assert_same( 1, count( $audit['content'] ), 'the page is named' );
	af_assert_same( 'About', $audit['content'][0]['title'], 'by title' );
} );

af_test( 'an [acf] shortcode in post content is unsafe', function () {
	af_seed_post( 44, 'Contact', 'publish', 'page', 'Call us on [acf field="phone"] today.' );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'the shortcode stops rendering when ACF goes' );
	af_assert_same( 1, count( $audit['content'] ), 'named' );
} );

af_test( 'a parent theme using ACF is found behind a clean child theme', function () {
	// The near-universal Elementor shape: the child holds the stylesheet, the
	// parent holds the template code that would break.
	af_set_theme( 'clean-theme', 'acf-parent-theme' );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'scanning only the stylesheet directory would have missed this' );
	af_assert_same( array( '(parent theme) acf-parent-theme' ), $audit['plugins'], 'and it says which theme' );
} );

af_test( 'a network-activated plugin using ACF is found on multisite', function () {
	// It is in a different option, and never appears in active_plugins.
	af_set_multisite();
	update_site_option( 'active_sitewide_plugins', array( 'acf-plugin/acf-plugin.php' => 1785024000 ) );

	$audit = afristream_acf_audit();

	af_assert_same( 'unsafe', $audit['state'], 'unsafe' );
	af_assert_same( array( '(network) acf-plugin/acf-plugin.php' ), $audit['plugins'], 'named as a network plugin' );
} );

af_test( 'a network-activated plugin is ignored on a single site', function () {
	update_site_option( 'active_sitewide_plugins', array( 'acf-plugin/acf-plugin.php' => 1785024000 ) );

	$audit = afristream_acf_audit();

	af_assert_same( true, $audit['safe'], 'there is no network, so there is nothing there to run' );
} );

// -- A single-file plugin is one file, not the whole plugins directory ---------

af_test( 'a single-file plugin is scanned as the one file it is', function () {
	// dirname( 'hello.php' ) is '.', and WP_PLUGIN_DIR . '/.' is every plugin on
	// the site. The scan used to walk all of them, find ACF's own source, and
	// report hello.php as the reason ACF could not be retired.
	update_option( 'active_plugins', array( 'hello.php' ) );

	$audit = afristream_acf_audit();

	af_assert_same( true, $audit['safe'], 'hello.php does not use ACF, and neither does anything else active' );
	af_assert_same( array(), $audit['plugins'], 'nothing is wrongly accused' );
} );

af_test( 'a single-file plugin resolves to its own file, not the plugins root', function () {
	af_assert_same( WP_PLUGIN_DIR . '/hello.php', afristream_active_plugin_path( 'hello.php' ), 'the file itself' );
	af_assert_same( WP_PLUGIN_DIR . '/acf-plugin', afristream_active_plugin_path( 'acf-plugin/acf-plugin.php' ), 'and a foldered plugin still resolves to its folder' );
} );

// -- A genuinely clean site ----------------------------------------------------

af_test( 'a site where every check completed and found nothing is safe', function () {
	update_option( 'active_plugins', array( 'clean-plugin/clean-plugin.php', 'hello.php' ) );
	af_seed_post( 45, 'A page', 'publish', 'page', 'Ordinary content.' );

	$audit = afristream_acf_audit();

	af_assert_same( 'clean', $audit['state'], 'clean' );
	af_assert_same( true, $audit['safe'], 'and this is the only case that may say so' );
	af_assert_same( array(), $audit['undetermined'], 'nothing went unchecked' );
	af_assert_same( array(), $audit['elementor'], 'no Elementor usage' );
	af_assert_same( array(), $audit['plugins'], 'no plugin or theme usage' );
	af_assert_same( array(), $audit['content'], 'no content usage' );
} );

af_test( 'a plugin reading meta directly is not treated as an ACF dependency', function () {
	// get_post_meta() keeps working once ACF is gone — the rows stay. Flagging
	// it would bury the hits that matter under noise.
	update_option( 'active_plugins', array( 'clean-plugin/clean-plugin.php' ) );

	$audit = afristream_acf_audit();

	af_assert_same( true, $audit['safe'], 'safe' );
} );

af_test( 'a missing must-use directory is nothing to read, not something unread', function () {
	// The fixtures have no mu-plugins directory, which is the common case on a
	// real site too. A permanent warning about it would teach people to ignore
	// the panel.
	$audit = afristream_acf_audit();

	af_assert_same( 'clean', $audit['state'], 'no warning' );
	af_assert_same( 'missing', afristream_path_uses_acf( WPMU_PLUGIN_DIR ), 'and the path itself reports as nothing to scan' );
} );

af_test( "ACF's own code and this plugin's are not scanned", function () {
	// Both are full of ACF function names — ACF because it defines them, this
	// plugin because it replaced them — so either would report itself.
	update_option(
		'active_plugins',
		array(
			'advanced-custom-fields-pro/acf.php',
			'bluegroup-project-afristream/bluegroup-project-afristream.php',
			'clean-plugin/clean-plugin.php',
		)
	);

	$targets = afristream_acf_scan_targets();

	af_assert( ! isset( $targets['advanced-custom-fields-pro/acf.php'] ), 'ACF is not scanned' );
	af_assert( ! isset( $targets['bluegroup-project-afristream/bluegroup-project-afristream.php'] ), 'nor is this plugin' );
	af_assert( isset( $targets['clean-plugin/clean-plugin.php'] ), 'everything else is' );
} );

// -- ACF's own posts -----------------------------------------------------------

af_test( "ACF's field groups are listed as work to do without being called unsafe", function () {
	af_seed_post( 46, 'Licence fields', 'publish', 'acf-field-group' );

	$audit = afristream_acf_audit();

	af_assert_same( 1, count( $audit['acf_posts'] ), 'the group is listed' );
	af_assert_same( 'Licence fields', $audit['acf_posts'][0]['title'], 'by name' );
	af_assert_same( true, $audit['safe'], 'deleting them is the step being advised, not a reason not to take it' );
} );

// -- Caching -------------------------------------------------------------------

af_test( 'the result is cached rather than rescanned on every admin page load', function () {
	$first = afristream_acf_audit();
	af_assert_same( true, $first['safe'], 'clean to start with' );

	$cached = get_transient( afristream_acf_audit_cache_key() );
	af_assert( is_array( $cached ), 'and it was written to a transient' );

	// Something changes underneath it; the cached answer is what comes back,
	// which is the whole point of a cache and is why it cannot be held long.
	af_seed_post( 47, 'New page', 'publish', 'page', '[acf field="x"]' );
	$second = afristream_acf_audit();
	af_assert_same( true, $second['safe'], 'served from the cache, not rescanned' );
} );

af_test( 'the cache is keyed per site so a network cannot share one answer', function () {
	af_assert_same( 'afristream_acf_audit_1', afristream_acf_audit_cache_key(), 'the blog id is in the key' );
} );

af_test( 'an undetermined result is held for far less time than a clean one', function () {
	$undetermined = afristream_acf_audit_ttl( 'undetermined' );
	$unsafe       = afristream_acf_audit_ttl( 'unsafe' );
	$clean        = afristream_acf_audit_ttl( 'clean' );

	af_assert( $undetermined < $clean, 'a check that did not finish is retried soon, not held for hours' );
	af_assert( $undetermined <= $unsafe, 'and it is the shortest-lived of the three' );
	af_assert_same( 6 * HOUR_IN_SECONDS, $clean, 'a clean answer is the expensive one and is held longest' );
} );

// -- Status pills --------------------------------------------------------------

af_test( '"cannot tell" gets its own colour rather than the one for "switched off"', function () {
	$unknown = afristream_status_pill( array( 'state' => 'unknown', 'label' => 'Cannot tell' ) );
	$off     = afristream_status_pill( array( 'state' => 'off', 'label' => 'Inactive' ) );
	$ok      = afristream_status_pill( array( 'state' => 'ok', 'label' => 'Active' ) );

	af_assert( false !== strpos( $unknown, '#dbeafe' ), 'unknown has a colour of its own' );
	af_assert( false === strpos( $unknown, '#f3f4f6' ), 'and it is not the grey used for off, which reads as disabled' );
	af_assert( false === strpos( $unknown, '#dcfce7' ), 'nor the green used for ok' );
	af_assert( false !== strpos( $off, '#f3f4f6' ), 'off is still grey' );
	af_assert( false !== strpos( $ok, '#dcfce7' ), 'ok is still green' );
} );

af_test( 'a state the pill has never heard of reads as unknown, not as off', function () {
	$pill = afristream_status_pill( array( 'state' => 'something-new', 'label' => 'Hm' ) );

	af_assert( false !== strpos( $pill, '#dbeafe' ), 'it is painted as something that cannot be judged' );
	af_assert( false === strpos( $pill, '#f3f4f6' ), 'rather than guessed at as switched off' );
} );

af_test( 'the pill keeps the inline-block that makes it a pill', function () {
	// It is echoed straight out, unfiltered, because wp_kses_post() would put
	// safecss_filter_attr() in front of this style and strip the display.
	$pill = afristream_status_pill( array( 'state' => 'ok', 'label' => 'Active' ) );

	af_assert( false !== strpos( $pill, 'display:inline-block' ), 'still there' );
	af_assert( false !== strpos( $pill, 'Active' ), 'label intact' );
} );

af_test( 'a feature reporting no status shows a dash rather than a blank cell', function () {
	af_assert_same( '<span style="color:#6b7280;">—</span>', afristream_status_pill( null ), 'an em dash' );
} );
