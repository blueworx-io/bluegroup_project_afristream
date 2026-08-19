<?php
if ( ! defined( 'AFRISTREAM_PORTAL_VERSION' ) ) {
	define( 'AFRISTREAM_PORTAL_VERSION', '0.24.0' );
}
require_once __DIR__ . '/../../includes/landing.php';
require_once __DIR__ . '/../../includes/onboarding.php';

/**
 * The modal's root element, parsed.
 *
 * @return DOMElement
 */
function af_ob_root() {
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . afristream_onboarding_modal() );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$nodes = ( new DOMXPath( $dom ) )->query( '//div[@data-testid="onboarding"]' );
	if ( 0 === $nodes->length ) {
		throw new RuntimeException( 'af_ob_root(): no onboarding modal in the rendered output.' );
	}
	return $nodes->item( 0 );
}

af_test( 'the modal carries the configured price, fee and both checkouts', function () {
	update_option( AFRISTREAM_LANDING_PRICE_OPTION, 1799 );
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 499 );
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );
	update_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, 'https://pay.example.test/afristream-setup' );

	$root = af_ob_root();
	af_assert_same( '1799', $root->getAttribute( 'data-price' ), 'data-price' );
	af_assert_same( '499', $root->getAttribute( 'data-setup-fee' ), 'data-setup-fee' );
	af_assert_same( 'https://pay.example.test/afristream?v=' . AFRISTREAM_PORTAL_VERSION, $root->getAttribute( 'data-cta' ), 'data-cta' );
	af_assert_same( 'https://pay.example.test/afristream-setup?v=' . AFRISTREAM_PORTAL_VERSION, $root->getAttribute( 'data-setup-cta' ), 'data-setup-cta' );
} );

af_test( 'an unpriced setup fee renders as zero rather than being left off', function () {
	// The step machine reads this attribute to decide whether the device offer
	// exists at all. A missing attribute and a zero fee must not be the same
	// question for it to answer.
	$root = af_ob_root();
	af_assert_same( '0', $root->getAttribute( 'data-setup-fee' ), 'data-setup-fee with nothing configured' );
} );

af_test( 'an unset setup checkout falls back to the Get Started URL', function () {
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );

	af_assert_same(
		'https://pay.example.test/afristream?v=' . AFRISTREAM_PORTAL_VERSION,
		af_ob_root()->getAttribute( 'data-setup-cta' ),
		'it borrows the Get Started URL rather than rendering an inert button'
	);
} );

af_test( 'the modal starts hidden and is a dialog', function () {
	$root  = af_ob_root();
	$xpath = new DOMXPath( $root->ownerDocument );

	af_assert( $root->hasAttribute( 'hidden' ), 'the modal must not be on screen until a CTA opens it' );
	af_assert_same( 1, $xpath->query( './/*[@role="dialog"][@aria-modal="true"]', $root )->length, 'one modal dialog' );
	af_assert_same( 1, $xpath->query( './/*[@id="as-ob-title"]', $root )->length, 'the element its aria-labelledby points at' );
} );

af_test( 'the modal is not a section, so the page keeps its section order', function () {
	$root = af_ob_root();
	af_assert_same( 0, ( new DOMXPath( $root->ownerDocument ) )->query( './/section', $root )->length, 'no sections inside the modal' );
} );

af_test( 'the device offer quotes the configured fee in both places it names a price', function () {
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 750 );

	$root  = af_ob_root();
	$offer = ( new DOMXPath( $root->ownerDocument ) )->query( './/*[@data-ob-step="offer"]', $root );
	af_assert_same( 1, $offer->length, 'one offer panel' );
	af_assert_same( 2, substr_count( $offer->item( 0 )->textContent, 'R750' ), 'the lede and the yes option both quote the fee' );
} );
