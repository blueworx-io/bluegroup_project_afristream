<?php
/**
 * The portal's sections inside the BlueWorx Labs customer dashboard.
 *
 * Labs now owns the customer dashboard: SureCart's dashboard page, dressed in
 * one design with one nav, and each panel filled by whichever plugin owns that
 * data. This file hands it ours — the streaming login, setup guides, What to
 * Watch, Editor Picks, downloads and, for affiliates, the affiliate tools —
 * one panel each, through Labs' blueworx_store_views filter. Orders, billing
 * and the member's profile stay Labs' and SureCart's.
 *
 * Each panel is the same portal the [afristream_portal] shortcode renders,
 * locked to one section and with its own tab bar and header left off, because
 * the Labs sidebar is the nav now. See docs/store-pages-api.md in the Labs repo
 * for the filter's contract.
 *
 * Nothing here needs Labs to be installed. Without it the filter simply never
 * runs, and the full portal shortcode carries on working on whatever page it
 * was put on.
 *
 * @package BlueGroupAfriStream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Our sections, in the order they join the Labs nav.
 *
 * 'key' is the Labs view key and what appears in the address (?view=setup);
 * 'section' is portal.js's own name for the section. They differ only where
 * the portal's name would read wrongly next to Labs' own panels — its Account
 * section is the streaming login, and Labs already has an Account.
 *
 * 'icon' must be one Labs ships (assets/blueworx-admin-icons.js in that repo).
 * 'where' puts the two things a customer needs most on the phone's bottom bar;
 * the rest are reachable from the sidebar and the overview's cards.
 *
 * @return array<int,array<string,mixed>>
 */
function afristream_dashboard_sections() {
	return array(
		array(
			'key'     => 'streaming',
			'section' => 'profile',
			'label'   => 'Streaming login',
			'title'   => 'Your streaming login',
			'lede'    => 'The usernames and passwords for your AfriStream apps.',
			'icon'    => 'key',
			'where'   => 'both',
		),
		array(
			'key'     => 'setup',
			'section' => 'setup',
			'label'   => 'Setup',
			'title'   => 'Set up your device',
			'lede'    => 'Step-by-step guides for the device you are holding.',
			'icon'    => 'monitor',
			'where'   => 'both',
		),
		array(
			'key'     => 'watch',
			'section' => 'watch',
			'label'   => 'What to Watch',
			'title'   => 'What to watch',
			'lede'    => 'Films, series and live sport worth your time.',
			'icon'    => 'eye',
			'where'   => 'side',
		),
		array(
			'key'     => 'editor-picks',
			'section' => 'editor',
			'label'   => 'Editor Picks',
			'title'   => 'Editor Picks',
			'lede'    => 'What our team is watching and recommending.',
			'icon'    => 'library',
			'where'   => 'side',
		),
		array(
			'key'     => 'download',
			'section' => 'download',
			'label'   => 'Download',
			'title'   => 'Download the app',
			'lede'    => 'Put the portal on your home screen.',
			'icon'    => 'download',
			'where'   => 'side',
		),
		array(
			'key'             => 'affiliates',
			'section'         => 'affiliate',
			'label'           => 'Affiliates',
			'title'           => 'Affiliates',
			'lede'            => 'Your referral links, and what you could earn.',
			'icon'            => 'link',
			'where'           => 'side',
			'affiliates_only' => true,
		),
	);
}

/**
 * The shortcode tag that draws one section's panel.
 *
 * @param string $key Section key.
 * @return string
 */
function afristream_dashboard_shortcode_tag( $key ) {
	return 'afristream_dashboard_' . str_replace( '-', '_', $key );
}

/**
 * Whether the signed-in customer is an affiliate.
 *
 * Asked server-side, unlike the full portal which asks from the browser: a
 * nav item has to be there or not when the page is drawn. The answer is the
 * same cached one the affiliate endpoint gives, so this costs a SureCart call
 * at most once per customer per cache period.
 *
 * @return bool
 */
function afristream_dashboard_is_affiliate() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$payload = afristream_affiliate_payload();
	return ! empty( $payload['affiliate'] );
}

/**
 * Add our sections to the Labs dashboard, straight after the overview.
 *
 * @param array $views Labs' views, in nav order.
 * @return array
 */
function afristream_dashboard_views( $views ) {
	$views = array_values( (array) $views );

	$ours = array();
	foreach ( afristream_dashboard_sections() as $section ) {
		if ( ! empty( $section['affiliates_only'] ) && ! afristream_dashboard_is_affiliate() ) {
			continue;
		}
		$ours[] = array(
			'key'       => $section['key'],
			'label'     => $section['label'],
			'title'     => $section['title'],
			'lede'      => $section['lede'],
			'icon'      => $section['icon'],
			'where'     => $section['where'],
			'blocks'    => array(),
			'shortcode' => afristream_dashboard_shortcode_tag( $section['key'] ),
		);
	}

	$at = 0;
	foreach ( $views as $index => $view ) {
		if ( 'dashboard' === ( $view['key'] ?? '' ) ) {
			$at = $index + 1;
			break;
		}
	}
	array_splice( $views, $at, 0, $ours );
	return $views;
}
add_filter( 'blueworx_store_views', 'afristream_dashboard_views' );

/**
 * One section's panel: the portal, locked to that section.
 *
 * Only the endpoints that section reads are passed, so the six panels Labs
 * draws up front do not each fetch the catalogue, the logins and the
 * affiliate details. data-section-views maps the portal's section names to
 * Labs view keys, so a button inside a panel that jumps to another section
 * ("See what to watch") opens that panel rather than doing nothing.
 *
 * @param string $key Section key.
 * @return string
 */
function afristream_dashboard_render( $key ) {
	$section = null;
	$map     = array();
	foreach ( afristream_dashboard_sections() as $candidate ) {
		$map[ $candidate['section'] ] = $candidate['key'];
		if ( $candidate['key'] === $key ) {
			$section = $candidate;
		}
	}
	if ( null === $section ) {
		return '';
	}

	$needs = array(
		'profile'   => array( 'data-credentials-endpoint' ),
		'watch'     => array( 'data-endpoint', 'data-detail-endpoint' ),
		'editor'    => array( 'data-editor-endpoint', 'data-detail-endpoint' ),
		'affiliate' => array( 'data-affiliate-endpoint' ),
	);

	return afristream_portal_mount(
		$section['section'],
		'true',
		$needs[ $section['section'] ] ?? array(),
		array(
			'data-single-section' => 'true',
			'data-section-views'  => esc_attr( wp_json_encode( $map ) ),
		),
		'afristream-portal--embedded'
	);
}

/**
 * The callback behind one section's shortcode.
 *
 * @param string $tag Shortcode tag.
 * @return callable|null
 */
function afristream_dashboard_shortcode_callback( $tag ) {
	foreach ( afristream_dashboard_sections() as $section ) {
		if ( afristream_dashboard_shortcode_tag( $section['key'] ) === $tag ) {
			$key = $section['key'];
			return static function () use ( $key ) {
				return afristream_dashboard_render( $key );
			};
		}
	}
	return null;
}

/**
 * Register one shortcode per section. Labs renders a view's shortcode with no
 * attributes, so which section a panel shows has to be in the tag itself.
 */
function afristream_dashboard_register_shortcodes() {
	foreach ( afristream_dashboard_sections() as $section ) {
		$tag = afristream_dashboard_shortcode_tag( $section['key'] );
		add_shortcode( $tag, afristream_dashboard_shortcode_callback( $tag ) );
	}
}
afristream_dashboard_register_shortcodes();

/**
 * The Labs customer dashboard's address, or '' when Labs is not serving one.
 *
 * Only while Labs' store pages feature is on: with it off, SureCart's page
 * shows SureCart's own dashboard, which has none of our sections in it.
 *
 * @return string
 */
function afristream_dashboard_url() {
	if ( ! function_exists( 'blueworx_feature_enabled' ) || ! function_exists( 'blueworx_store_page_url' ) ) {
		return '';
	}
	if ( ! blueworx_feature_enabled( 'store_pages' ) ) {
		return '';
	}
	return (string) blueworx_store_page_url( 'dashboard' );
}
