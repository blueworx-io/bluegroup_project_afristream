<?php
if ( ! defined( 'AFRISTREAM_PORTAL_VERSION' ) ) {
	define( 'AFRISTREAM_PORTAL_VERSION', '0.34.0' );
}
require_once __DIR__ . '/../../bluegroup-project-afristream.php';

/**
 * Stand-ins for the three BlueWorx Labs functions the dashboard integration
 * asks about. Labs is a separate plugin, so on a real site these either exist
 * or do not; here they always exist and answer from the per-test store, which
 * af_reset_store() wipes — so every other test in the suite sees a site with
 * Labs present but its store pages switched off, and nothing changes for them.
 */
function blueworx_feature_enabled( $feature ) {
	return 'store_pages' === $feature && ! empty( $GLOBALS['af_store']['labs']['store_pages'] );
}

function blueworx_store_page_url( $key ) {
	return (string) ( $GLOBALS['af_store']['labs']['pages'][ $key ] ?? '' );
}

function af_set_labs( $store_pages, $dashboard_url = '' ) {
	$GLOBALS['af_store']['labs'] = array(
		'store_pages' => $store_pages,
		'pages'       => array( 'dashboard' => $dashboard_url ),
	);
}

/** The views Labs starts from when SureCart is active, trimmed to the keys. */
function af_labs_default_views() {
	return array(
		array( 'key' => 'dashboard' ),
		array( 'key' => 'orders' ),
		array( 'key' => 'invoices' ),
		array( 'key' => 'billing' ),
		array( 'key' => 'plans' ),
		array( 'key' => 'profile' ),
		array( 'key' => 'account' ),
	);
}

function af_view_keys( $views ) {
	return array_map(
		static function ( $view ) {
			return $view['key'];
		},
		$views
	);
}

/** The section-to-panel map a mount carries, decoded. */
function af_section_views_attr( $html ) {
	if ( ! preg_match( '/data-section-views="([^"]*)"/', $html, $m ) ) {
		return array();
	}
	$map = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	return is_array( $map ) ? $map : array();
}

af_test( 'our sections join the Labs dashboard straight after the overview', function () {
	$views = afristream_dashboard_views( af_labs_default_views() );
	af_assert_same(
		array( 'dashboard', 'streaming', 'setup', 'watch', 'editor-picks', 'download', 'orders', 'invoices', 'billing', 'plans', 'profile', 'account' ),
		af_view_keys( $views ),
		'sections sit between the overview and the shop panels, and no Labs view is lost'
	);
} );

af_test( 'every section is a whole-panel shortcode that is actually registered', function () {
	foreach ( afristream_dashboard_views( af_labs_default_views() ) as $view ) {
		if ( ! isset( $view['label'] ) ) {
			continue; // A Labs view, passed through untouched.
		}
		af_assert( '' !== $view['shortcode'], $view['key'] . ' names a shortcode' );
		af_assert( array() === $view['blocks'], $view['key'] . ' has no blocks' );
		af_assert( in_array( $view['where'], array( 'both', 'side', 'bar' ), true ), $view['key'] . ' says where it shows' );
		af_assert( '' !== $view['title'] && '' !== $view['lede'] && '' !== $view['icon'], $view['key'] . ' has a title, lede and icon' );
		af_assert( is_callable( afristream_dashboard_shortcode_callback( $view['shortcode'] ) ), $view['shortcode'] . ' renders' );
	}
} );

af_test( 'section keys never collide with the views Labs already has', function () {
	$ours = array_map(
		static function ( $section ) {
			return $section['key'];
		},
		afristream_dashboard_sections()
	);
	af_assert_same( array(), array_values( array_intersect( $ours, af_view_keys( af_labs_default_views() ) ) ), 'no shared keys' );
} );

af_test( 'the Affiliates panel is only there for an affiliate', function () {
	af_assert( ! in_array( 'affiliates', af_view_keys( afristream_dashboard_views( af_labs_default_views() ) ), true ), 'signed out: no Affiliates panel' );

	af_set_current_user( 7 );
	set_transient( 'afristream_affiliate_0_7', array( 'affiliate' => false ) );
	af_assert( ! in_array( 'affiliates', af_view_keys( afristream_dashboard_views( af_labs_default_views() ) ), true ), 'ordinary customer: no Affiliates panel' );

	set_transient( 'afristream_affiliate_0_7', array( 'affiliate' => true ) );
	$keys = af_view_keys( afristream_dashboard_views( af_labs_default_views() ) );
	af_assert( in_array( 'affiliates', $keys, true ), 'affiliate: Affiliates panel shown' );
	af_assert_same( 'orders', $keys[ array_search( 'affiliates', $keys, true ) + 1 ], 'Affiliates is the last of our sections' );
} );

af_test( 'a section panel is the portal locked to that section, fetching only what it shows', function () {
	$watch = afristream_dashboard_render( 'watch' );
	af_assert( false !== strpos( $watch, 'data-single-section="true"' ), 'locked to one section' );
	af_assert( false !== strpos( $watch, 'data-default-tab="watch"' ), 'opens on What to Watch' );
	af_assert( false !== strpos( $watch, 'data-endpoint="/wp-json/afristream/v1/watch"' ), 'fetches the catalogue' );
	af_assert( false !== strpos( $watch, 'data-detail-endpoint=' ), 'can open a title' );
	af_assert( false === strpos( $watch, 'data-credentials-endpoint' ), 'does not fetch logins' );
	af_assert( false === strpos( $watch, 'data-affiliate-endpoint' ), 'does not ask about affiliation' );
	af_assert( false === strpos( $watch, 'data-editor-endpoint' ), 'does not fetch Editor Picks' );

	$login = afristream_dashboard_render( 'streaming' );
	af_assert( false !== strpos( $login, 'data-default-tab="profile"' ), 'the login panel is the portal Account section' );
	af_assert( false !== strpos( $login, 'data-credentials-endpoint=' ), 'fetches logins' );
	af_assert( false === strpos( $login, 'data-endpoint=' ), 'does not fetch the catalogue' );

	$views = af_section_views_attr( $watch );
	af_assert_same( 'setup', $views['setup'] ?? '', 'a link to Setup inside a panel goes to the Setup panel' );
	af_assert_same( 'streaming', $views['profile'] ?? '', 'and a link to Account goes to the login panel' );
} );

af_test( 'the standalone portal shortcode is unchanged', function () {
	$html = afristream_portal_shortcode( array() );
	af_assert( false === strpos( $html, 'data-single-section' ), 'still the full portal with its own tabs' );
	foreach ( array( 'data-endpoint', 'data-editor-endpoint', 'data-detail-endpoint', 'data-credentials-endpoint', 'data-affiliate-endpoint' ) as $attr ) {
		af_assert( false !== strpos( $html, $attr . '=' ), 'still carries ' . $attr );
	}
} );

af_test( 'Dashboard links point at the Labs dashboard once it is live', function () {
	af_seed_post( 42, 'Portal', 'publish', 'page' );
	af_seed_permalink( 42, 'https://example.test/portal/' );
	update_option( 'afristream_portal_page_id', 42 );

	af_set_labs( false, 'https://example.test/customer-dashboard/' );
	af_assert_same( 'https://example.test/portal/', afristream_landing_portal_url(), 'store pages off: the portal page' );

	af_set_labs( true, '' );
	af_assert_same( 'https://example.test/portal/', afristream_landing_portal_url(), 'no dashboard page yet: the portal page' );

	af_set_labs( true, 'https://example.test/customer-dashboard/' );
	af_assert_same( 'https://example.test/customer-dashboard/', afristream_landing_portal_url(), 'store pages on: the Labs dashboard' );
} );
