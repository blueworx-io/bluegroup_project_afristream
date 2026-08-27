<?php
/**
 * The four policy pages.
 *
 * These exist because a payment provider asked to see them, so the tests are
 * written against what such a reviewer actually checks: that each document is
 * there, says the thing it is named after, is reachable from every page, and
 * does not contradict the price the checkout charges.
 */

if ( ! defined( 'AFRISTREAM_PORTAL_VERSION' ) ) {
	define( 'AFRISTREAM_PORTAL_VERSION', '0.24.0' );
}
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/landing.php';
require_once __DIR__ . '/../../includes/policies.php';

/**
 * One rendered document, parsed.
 *
 * @param string $key A key of AFRISTREAM_POLICIES.
 * @return DOMElement
 */
function af_policy_root( $key ) {
	return af_landing_root( afristream_policy_body( $key ) );
}

/**
 * The readable text of a rendered document, as one string.
 *
 * @param string $key A key of AFRISTREAM_POLICIES.
 * @return string
 */
function af_policy_text( $key ) {
	return implode( ' ', af_landing_text_nodes( af_policy_root( $key ) ) );
}

// -- The set of documents ----------------------------------------------------

af_test( 'the site carries the four documents the payment provider asked for', function () {
	af_assert_same(
		array( 'terms', 'privacy', 'refunds', 'cancellation' ),
		array_keys( AFRISTREAM_POLICIES ),
		'the documents, in the order the footer lists them'
	);
} );

af_test( 'every document has a title, a lede, a date and at least one section', function () {
	foreach ( AFRISTREAM_POLICIES as $key => $policy ) {
		af_assert( '' !== $policy['title'], $key . ' has a title' );
		af_assert( '' !== $policy['lede'], $key . ' has a lede' );
		af_assert( count( $policy['sections'] ) > 0, $key . ' has sections' );

		$root = af_policy_root( $key );
		af_assert_same( 1, af_landing_xpath_count( $root, './/h1' ), $key . ' has exactly one h1' );
		af_assert_same(
			count( $policy['sections'] ),
			af_landing_xpath_count( $root, './/*[@data-doc-section]' ),
			$key . ' renders every section it defines'
		);
		af_assert(
			false !== strpos( af_policy_text( $key ), AFRISTREAM_POLICIES_UPDATED ),
			$key . ' says when it was last updated'
		);
	}
} );

af_test( 'each document carries the site header and footer, not a bare wall of text', function () {
	af_landing_seed_portal_page();
	af_landing_seed_policy_pages();

	foreach ( array_keys( AFRISTREAM_POLICIES ) as $key ) {
		$root = af_policy_root( $key );
		af_assert_same( 1, af_landing_xpath_count( $root, './/header[@data-testid="landing-header"]' ), $key . ' has the header' );
		af_assert_same( 1, af_landing_xpath_count( $root, './/footer[@data-testid="landing-footer"]' ), $key . ' has the footer' );
	}
} );

// -- What they say -----------------------------------------------------------

af_test( 'the refund policy states the window and the one thing that closes it', function () {
	$text = af_policy_text( 'refunds' );

	af_assert( false !== strpos( $text, '14 days' ), 'the window is stated' );
	af_assert( false !== strpos( $text, 'setup session has not taken place' ), 'and the condition on it' );
	af_assert( false !== strpos( $text, 'device we have already ordered' ), 'and what is excluded' );
	af_assert( false !== strpos( $text, AFRISTREAM_CONTACT ), 'and how to ask for one' );
} );

af_test( 'the cancellation policy says access runs to the end of the paid term', function () {
	$text = af_policy_text( 'cancellation' );

	af_assert( false !== strpos( $text, 'end of the year you have already paid for' ), 'the term is honoured' );
	af_assert( false !== strpos( $text, 'does not renew' ), 'and it does not renew' );
	af_assert( false !== strpos( $text, AFRISTREAM_CONTACT ), 'and how to cancel' );
} );

af_test( 'the privacy policy declares no tracking, and who does touch the data', function () {
	$text = af_policy_text( 'privacy' );

	af_assert( false !== strpos( $text, 'no analytics' ), 'no analytics is stated, not implied' );
	af_assert( false !== strpos( $text, 'SureCart' ), 'the payment processor is named' );
	af_assert( false !== strpos( $text, 'card number never reaches AfriStream' ), 'and what it means for card details' );
} );

af_test( 'the terms repeat the disclaimer the rest of the site is built on', function () {
	$text = af_policy_text( 'terms' );

	af_assert(
		false !== strpos( $text, 'does not host, stream, supply, share or resell' ),
		'the terms say what the footer and the onboarding flow say'
	);
	af_assert( false !== strpos( $text, 'billed in South African Rand' ), 'and what the customer is actually charged in' );
} );

// -- Agreement with the rest of the plugin -----------------------------------

af_test( 'the price in the terms is the price the checkout charges', function () {
	update_option( AFRISTREAM_LANDING_PRICE_OPTION, 1899 );
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 1250 );

	$text = af_policy_text( 'terms' );

	// The whole point of not writing the number out as copy: an admin moving
	// the price in Settings must not leave the terms quoting last year's.
	af_assert( false !== strpos( $text, 'R1899' ), 'the subscription price follows the setting' );
	af_assert( false !== strpos( $text, 'R1250' ), 'and so does the setup fee' );
	af_assert( false === strpos( $text, 'R1599' ), 'the default is not left behind in the copy' );
} );

af_test( 'the price in the terms converts with the switcher like every other price', function () {
	$root  = af_policy_root( 'terms' );
	$xpath = new DOMXPath( $root->ownerDocument );

	af_assert(
		$xpath->query( './/*[@data-money]', $root )->length >= 2,
		'both figures are marked up for the currency switcher'
	);
	af_assert_same(
		1,
		af_landing_xpath_count( $root, './/*[@data-testid="currency-header"]' ),
		'and the switcher itself is on the page'
	);
} );

af_test( 'the delivery timeline is the same list on the pricing section and in the terms', function () {
	$pricing = afristream_landing_pricing();
	$terms   = af_policy_text( 'terms' );

	foreach ( AFRISTREAM_DELIVERY as $step ) {
		af_assert( false !== strpos( $pricing, esc_html( $step ) ), 'the pricing section prints: ' . $step );
		af_assert( false !== strpos( $terms, $step ), 'and so do the terms: ' . $step );
	}
} );

// -- Reaching them -----------------------------------------------------------

af_test( 'the footer links every published policy, from every page', function () {
	af_landing_seed_portal_page();
	af_landing_seed_teasers();
	af_landing_seed_setup_plan();
	af_landing_seed_policy_pages();

	foreach ( array( afristream_landing_body(), afristream_policy_body( 'refunds' ) ) as $html ) {
		$legal = ( new DOMXPath( af_landing_root( $html )->ownerDocument ) )
			->query( '//div[@data-testid="footer-legal"]//a' );

		af_assert_same( 4, $legal->length, 'all four documents are linked' );
		af_assert_same( '/terms/', $legal->item( 0 )->getAttribute( 'href' ), 'and at the pages that carry them' );
	}
} );

af_test( 'a policy with no page published is left out rather than linked at nothing', function () {
	af_landing_seed_portal_page();

	$ids = af_landing_seed_policy_pages();
	// Take the refund policy's page back out from under the footer.
	$GLOBALS['af_store']['posts'][ $ids['refunds'] ]['post_status'] = 'draft';
	unset( $GLOBALS['afristream_policy_pages'] );

	$legal = ( new DOMXPath( af_landing_root( afristream_landing_body() )->ownerDocument ) )
		->query( '//div[@data-testid="footer-legal"]//a' );

	af_assert_same( 3, $legal->length, 'the unpublished one is omitted' );
	af_assert_same( '', afristream_policy_url( 'refunds' ), 'and resolves to nothing rather than a dead link' );
} );

af_test( 'a policy page points its nav back at the landing page, not at anchors it does not have', function () {
	af_landing_seed_portal_page();
	af_landing_seed_policy_pages();

	af_seed_post( 50, 'Home', 'publish', 'page' );
	update_post_meta( 50, '_wp_page_template', AFRISTREAM_LANDING_TEMPLATE );
	af_seed_permalink( 50, '/landing' );

	$root  = af_policy_root( 'terms' );
	$xpath = new DOMXPath( $root->ownerDocument );
	$hrefs = array();
	foreach ( $xpath->query( './/nav[@data-testid="landing-nav"]/a', $root ) as $link ) {
		$hrefs[] = $link->getAttribute( 'href' );
	}

	af_assert_same(
		array( '/landing/#features', '/landing/#pricing', '/landing/#setup', '/landing/#services', '/landing/#faq' ),
		$hrefs,
		'every nav link leaves for the page that has those sections'
	);
} );

af_test( 'the landing page keeps its plain fragment links', function () {
	af_landing_seed_portal_page();
	af_landing_seed_teasers();
	af_landing_seed_setup_plan();

	$root  = af_landing_root( afristream_landing_body() );
	$xpath = new DOMXPath( $root->ownerDocument );
	$first = $xpath->query( './/nav[@data-testid="landing-nav"]/a', $root )->item( 0 );

	af_assert_same( '#features', $first->getAttribute( 'href' ), 'no needless absolute URL on its own page' );
} );

af_test( 'each document links the other three', function () {
	af_landing_seed_policy_pages();

	foreach ( array_keys( AFRISTREAM_POLICIES ) as $key ) {
		$links = ( new DOMXPath( af_policy_root( $key )->ownerDocument ) )
			->query( '//p[contains(concat(" ", normalize-space(@class), " "), " as-doc-foot ")]//a' );

		af_assert_same( 3, $links->length, $key . ' links the other three, and not itself' );
	}
} );

// -- Wiring ------------------------------------------------------------------

af_test( 'all four templates are offered in the page editor', function () {
	$templates = afristream_policy_register_templates( array() );

	af_assert_same( 4, count( $templates ), 'one per document' );
	foreach ( AFRISTREAM_POLICIES as $policy ) {
		af_assert( isset( $templates[ $policy['template'] ] ), $policy['label'] . ' is offered' );
	}
} );

af_test( 'the shortcode renders a document, and refuses one that does not exist', function () {
	af_assert(
		false !== strpos( afristream_policy_shortcode( array( 'doc' => 'refunds' ) ), 'data-testid="policy-refunds"' ),
		'a named document renders'
	);
	af_assert_same( '', afristream_policy_shortcode( array( 'doc' => 'nonsense' ) ), 'an unknown one renders nothing' );
} );
