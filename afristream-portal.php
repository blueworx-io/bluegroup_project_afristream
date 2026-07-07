<?php
/**
 * Plugin Name: AfriStream Customer Portal
 * Plugin URI:  https://github.com/blueworx-io/bluegroup_project_afristream
 * Description: Customer portal for AfriStream subscribers — app profile credentials, what to watch, tips & tricks, and troubleshooting guides. Rendered via the [afristream_portal] shortcode.
 * Version:     0.1.0
 * Author:      BlueWorx
 * License:     GPL-2.0-or-later
 * Text Domain: afristream-portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFRISTREAM_PORTAL_VERSION', '0.1.0' );

/**
 * Register (but don't enqueue) the portal assets — they only load on pages
 * that actually render the shortcode.
 */
function afristream_portal_register_assets() {
	wp_register_style(
		'afristream-portal-fonts',
		'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap',
		array(),
		null
	);
	wp_register_style(
		'afristream-portal',
		plugins_url( 'assets/portal.css', __FILE__ ),
		array( 'afristream-portal-fonts' ),
		AFRISTREAM_PORTAL_VERSION
	);
	wp_register_script(
		'afristream-portal',
		plugins_url( 'assets/portal.js', __FILE__ ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'afristream_portal_register_assets' );

/**
 * [afristream_portal default_tab="profile" show_sport="true"]
 *
 * default_tab: profile | watch | tips | help
 */
function afristream_portal_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'default_tab' => 'profile',
			'show_sport'  => 'true',
		),
		$atts,
		'afristream_portal'
	);

	wp_enqueue_style( 'afristream-portal' );
	wp_enqueue_script( 'afristream-portal' );

	return sprintf(
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] )
	);
}
add_shortcode( 'afristream_portal', 'afristream_portal_shortcode' );
