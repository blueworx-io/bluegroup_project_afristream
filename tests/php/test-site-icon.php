<?php
require_once __DIR__ . '/../../bluegroup-project-afristream.php';

// Captured at require time, before af_reset_store() wipes the actions array.
$GLOBALS['af_icon_boot_actions'] = array(
	'wp_head'    => af_registered_actions( 'wp_head' ),
	'admin_head' => af_registered_actions( 'admin_head' ),
	'login_head' => af_registered_actions( 'login_head' ),
);

/**
 * The favicon the plugin prints when the site has not set one of its own.
 */

/**
 * afristream_portal_site_icon()'s output, captured.
 *
 * @return string
 */
function af_icon_output() {
	ob_start();
	afristream_portal_site_icon();
	return (string) ob_get_clean();
}

af_test( 'the favicon is printed on the front end, the admin and the login screen', function () {
	// All three, because the landing page renders its own document: hooking only
	// wp_head would leave the mark showing on the marketing page and nowhere a
	// logged-in customer or an administrator actually looks.
	foreach ( array( 'wp_head', 'admin_head', 'login_head' ) as $hook ) {
		af_assert(
			in_array( 'afristream_portal_site_icon', $GLOBALS['af_icon_boot_actions'][ $hook ], true ),
			$hook . ' carries the favicon'
		);
	}
} );

af_test( 'it prints an SVG icon, a PNG fallback and a touch icon', function () {
	delete_option( 'site_icon' );

	$html = af_icon_output();

	af_assert( false !== strpos( $html, 'rel="icon"' ), 'an icon link' );
	af_assert( false !== strpos( $html, 'type="image/svg+xml"' ), 'the SVG is the primary icon' );
	af_assert( false !== strpos( $html, 'assets/afristream-icon.svg' ), 'pointing at the bundled mark' );
	// Not every browser accepts an SVG favicon, so a raster one is offered too.
	af_assert( false !== strpos( $html, 'rel="alternate icon"' ), 'a PNG fallback' );
	af_assert( false !== strpos( $html, 'rel="apple-touch-icon"' ), 'and a touch icon for home screens' );
	af_assert( false !== strpos( $html, 'assets/logo.png' ), 'both raster links use the bundled PNG' );
} );

af_test( 'the icon URLs carry the plugin version, so an upgrade is not served a stale favicon', function () {
	delete_option( 'site_icon' );

	af_assert(
		false !== strpos( af_icon_output(), 'ver=' . AFRISTREAM_PORTAL_VERSION ),
		'versioned'
	);
} );

af_test( 'a Site Icon set in the Customizer wins — the plugin stands down', function () {
	// Somebody who set one has said what they want the favicon to be. Core
	// prints it from wp_site_icon() on the same hook, so printing ours too
	// would put two competing icon links in one head.
	update_option( 'site_icon', 4242 );

	af_assert_same( '', af_icon_output(), 'nothing printed over the top of it' );
} );
