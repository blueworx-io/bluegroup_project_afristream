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
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 999 );
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );
	update_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, 'https://pay.example.test/afristream-setup' );

	$root = af_ob_root();
	af_assert_same( '1799', $root->getAttribute( 'data-price' ), 'data-price' );
	af_assert_same( '999', $root->getAttribute( 'data-setup-fee' ), 'data-setup-fee' );
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

af_test( 'the device question quotes the configured fee in both places it names a price', function () {
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 750 );

	$root   = af_ob_root();
	$device = ( new DOMXPath( $root->ownerDocument ) )->query( './/*[@data-ob-step="device"]', $root );
	af_assert_same( 1, $device->length, 'one device panel' );
	af_assert_same( 2, substr_count( $device->item( 0 )->textContent, 'R750' ), 'the lede and the yes option both quote the fee' );
} );

af_test( 'there is one device question, not two, and no subscription picker', function () {
	// It used to ask whether the customer owned a device and then, separately,
	// whether they wanted one — the same question twice, and a step longer than
	// the flow needed to be.
	$root  = af_ob_root();
	$xpath = new DOMXPath( $root->ownerDocument );

	af_assert_same( 0, $xpath->query( './/*[@data-ob-step="offer"]', $root )->length, 'the old offer step is gone' );
	af_assert_same( 0, $xpath->query( './/input[@name="as-ob-offer"]', $root )->length, 'and so is its radio group' );
	af_assert_same( 2, $xpath->query( './/input[@name="as-ob-device"]', $root )->length, 'one question, two answers' );
	af_assert_same( 0, $xpath->query( './/*[@data-sub-price]', $root )->length, 'and no subscription chips to add up' );
	af_assert_same( 3, $xpath->query( './/*[@data-ob-step]', $root )->length, 'intro, device, breakdown' );
} );

af_test( 'the flow says what AfriStream does not supply, on the first screen and at the checkout', function () {
	$root  = af_ob_root();
	$xpath = new DOMXPath( $root->ownerDocument );

	af_assert_same( 2, $xpath->query( './/p[@class="as-ob-fine"]', $root )->length, 'both places carry it' );
	af_assert(
		false !== strpos( $root->textContent, 'does not host, stream, supply or resell' ),
		'and it says so plainly'
	);
} );
