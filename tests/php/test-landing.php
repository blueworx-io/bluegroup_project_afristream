<?php
if ( ! defined( 'AFRISTREAM_PORTAL_VERSION' ) ) {
	define( 'AFRISTREAM_PORTAL_VERSION', '0.22.0' );
}
require_once __DIR__ . '/../../includes/landing.php';

/**
 * The rest of the suite for this page — tests/landing.spec.js — runs against
 * preview/landing.html, a hand-maintained mirror, never against the PHP that
 * actually ships. That is why the newsletter form silently stopped rendering
 * on the real page template while every Playwright test kept passing: the
 * bug was in how afristream_landing_enqueue() got called, not in any markup
 * the mirror could disagree with.
 *
 * This file is what runs the real PHP renderer and enqueue path, and checks
 * it against the mirror on the invariants that matter — not a byte-for-byte
 * diff, which would fail on whitespace and get deleted within a month.
 */

// -- Mirror comparison helpers -----------------------------------------------

/**
 * Parses an HTML string and returns the .as-landing wrapper as a DOMElement,
 * so the PHP output and the mirror's full document are comparable through
 * the same accessor functions below.
 *
 * libxml's HTML parser is HTML4-ish and complains about the mirror's <head>
 * (unescaped "&" in font URLs, HTML5 elements it doesn't recognise) even
 * though it parses the tree correctly regardless — those warnings are
 * expected noise and are discarded rather than surfaced as failures.
 *
 * @param string $html Full document or a fragment containing .as-landing.
 * @return DOMElement
 */
function af_landing_root( $html ) {
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$nodes = ( new DOMXPath( $dom ) )->query( '//div[contains(concat(" ", normalize-space(@class), " "), " as-landing ")]' );
	if ( 0 === $nodes->length ) {
		throw new RuntimeException( 'af_landing_root(): no .as-landing wrapper in the given HTML.' );
	}
	return $nodes->item( 0 );
}

/**
 * afristream_landing_body(), parsed the same way as the mirror.
 *
 * @return DOMElement
 */
function af_landing_php_root() {
	return af_landing_root( afristream_landing_body() );
}

/**
 * preview/landing.html, parsed.
 *
 * @return DOMElement
 */
function af_landing_mirror_root() {
	return af_landing_root( (string) file_get_contents( __DIR__ . '/../../preview/landing.html' ) );
}

function af_landing_xpath_count( DOMElement $root, $expr ) {
	return ( new DOMXPath( $root->ownerDocument ) )->query( $expr, $root )->length;
}

/**
 * The <section id="…"> values in document order.
 *
 * @return string[]
 */
function af_landing_section_ids( DOMElement $root ) {
	$ids = array();
	foreach ( ( new DOMXPath( $root->ownerDocument ) )->query( './/section[@id]', $root ) as $section ) {
		$ids[] = $section->getAttribute( 'id' );
	}
	return $ids;
}

/**
 * The calculator's data-sub-price values, in document order.
 *
 * @return string[]
 */
function af_landing_sub_prices( DOMElement $root ) {
	$prices = array();
	foreach ( ( new DOMXPath( $root->ownerDocument ) )->query( './/*[@data-sub-price]', $root ) as $node ) {
		$prices[] = $node->getAttribute( 'data-sub-price' );
	}
	return $prices;
}

/**
 * Every non-blank text node under $root, whitespace-collapsed, in document
 * order — i.e. what a visitor actually reads, independent of the markup
 * carrying it.
 *
 * DOMDocument decodes HTML entities (including &#039;) while parsing, so an
 * apostrophe produced by esc_html()'s ENT_QUOTES encoding and a literal one
 * typed into the mirror already read identically by the time nodeValue is
 * read here. The str_replace() is belt-and-braces documentation of that
 * normalisation, in case this ever stops going through the DOM parser.
 *
 * @return string[]
 */
function af_landing_text_nodes( DOMElement $root ) {
	$out = array();
	foreach ( ( new DOMXPath( $root->ownerDocument ) )->query( './/text()', $root ) as $node ) {
		$value = str_replace( '&#039;', "'", trim( preg_replace( '/\s+/', ' ', $node->nodeValue ) ) );
		if ( '' !== $value ) {
			$out[] = $value;
		}
	}
	return $out;
}

/**
 * The mirror hardcodes a /portal/ Dashboard link in three places (the
 * header, the mobile menu and the footer). Seeding a published page at this
 * ID, chosen explicitly via the settings option, makes
 * afristream_landing_portal_url() resolve the same way.
 */
function af_landing_seed_portal_page() {
	af_seed_post( 42, 'Portal', 'publish', 'page' );
	update_option( 'afristream_portal_page_id', 42 );
	af_seed_permalink( 42, '/portal/' );
}

// -- Mirror parity -------------------------------------------------------

af_test( 'the rendered sections appear in the same order as the preview mirror', function () {
	af_landing_seed_portal_page();

	af_assert_same(
		af_landing_section_ids( af_landing_mirror_root() ),
		af_landing_section_ids( af_landing_php_root() ),
		'section ids, in document order'
	);
} );

af_test( 'marker counts match the preview mirror', function () {
	af_landing_seed_portal_page();
	$root = af_landing_php_root();

	af_assert_same( 26, af_landing_xpath_count( $root, './/*[@data-platform]' ), 'ticker platforms (13, doubled for a seamless loop)' );
	af_assert_same( 4, af_landing_xpath_count( $root, './/*[@data-feature]' ), 'feature cards' );
	af_assert_same( 5, af_landing_xpath_count( $root, './/*[@data-plan-feature]' ), 'plan feature lines' );
	af_assert_same( 7, af_landing_xpath_count( $root, './/*[@data-faq]' ), 'FAQ items' );
	af_assert_same( 6, af_landing_xpath_count( $root, './/*[@data-reveal]' ), 'sections that fade in on scroll' );
	af_assert_same( 3, af_landing_xpath_count( $root, './/figure' ), 'testimonials' );
	af_assert_same( 16, af_landing_xpath_count( $root, './/*[@data-sub-price]' ), 'calculator subscription options' );
	af_assert_same( 1, af_landing_xpath_count( $root, './/h1' ), 'exactly one h1 on the page' );
} );

af_test( 'the sixteen calculator subscription prices match the preview mirror in order', function () {
	af_landing_seed_portal_page();

	af_assert_same(
		af_landing_sub_prices( af_landing_mirror_root() ),
		af_landing_sub_prices( af_landing_php_root() ),
		'data-sub-price values, in order'
	);
} );

af_test( 'the visible text matches the preview mirror', function () {
	af_landing_seed_portal_page();

	af_assert_same(
		af_landing_text_nodes( af_landing_mirror_root() ),
		af_landing_text_nodes( af_landing_php_root() ),
		'visible text nodes, in order'
	);
} );

// -- The enqueue-order regression (Critical 1) -------------------------------

af_test( 'enqueuing before the assets are registered attaches nothing — the bug that shipped', function () {
	// This reproduces exactly what afristream_landing_template() used to do:
	// call afristream_landing_enqueue() directly, before wp_enqueue_scripts —
	// and therefore afristream_landing_register_assets(), hooked onto it — had
	// ever run. wp_add_inline_script() silently drops a call against a handle
	// nobody has registered yet, so the newsletter embed never rendered and
	// nothing anywhere raised an error about it.
	afristream_landing_enqueue();

	af_assert_same( array(), af_wp_inline_scripts( 'surecontact-forms' ), 'no inline script attaches without registration first' );
} );

af_test( 'registering first attaches the newsletter render call to the right form and container', function () {
	// The fixed order: afristream_landing_register_assets() is hooked to
	// wp_enqueue_scripts at file load, and afristream_landing_template() now
	// hooks afristream_landing_enqueue() onto the same action instead of
	// calling it directly — so registration has always run by the time this
	// runs, on both the template path and the shortcode path.
	afristream_landing_register_assets();
	afristream_landing_enqueue();

	$calls = af_wp_inline_scripts( 'surecontact-forms' );
	af_assert_same( 1, count( $calls ), 'exactly one inline script attached to the embed' );
	af_assert( false !== strpos( $calls[0]['data'], "formId:'6e6876be-416c-4cd4-b109-3a603af6be79'" ), 'wired to the newsletter form id' );
	af_assert( false !== strpos( $calls[0]['data'], "container:'#surecontact-form-afristream-newsletter-sign-up'" ), 'wired to the container the signup section renders' );
} );

af_test( 'the shortcode path enqueues and registers directly, unaffected by the template fix', function () {
	// afristream_landing_shortcode() calls afristream_landing_enqueue()
	// straight from the_content, which runs after wp_enqueue_scripts has
	// already fired — this path was never broken, and stays a direct call.
	afristream_landing_register_assets();

	$body = afristream_landing_shortcode();

	af_assert( false !== strpos( $body, 'id="surecontact-form-afristream-newsletter-sign-up"' ), 'the shortcode still renders the signup container' );
	af_assert_same( 1, count( af_wp_inline_scripts( 'surecontact-forms' ) ), 'and the render call still attaches' );
} );
