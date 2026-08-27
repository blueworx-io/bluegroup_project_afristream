<?php
if ( ! defined( 'AFRISTREAM_PORTAL_VERSION' ) ) {
	define( 'AFRISTREAM_PORTAL_VERSION', '0.24.0' );
}
require_once __DIR__ . '/../../includes/landing.php';
// The Editor Picks teaser reads afristream_portal_baked_picks(), which lives in
// the main plugin file. Loaded here rather than stubbed: a local stub would be
// declared first and then collide with the real one when another test file
// pulls the plugin in, and stubbing it would test the stub anyway.
require_once __DIR__ . '/../../bluegroup-project-afristream.php';

// Captured at require time: af_reset_store() wipes the actions array before
// every test, taking the registrations the files above made at load with it.
$GLOBALS['af_landing_boot_actions'] = af_registered_actions( 'wp_enqueue_scripts' );

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
	// Poster titles inside a teaser row are catalogue data — they change every
	// time the watchlist is synced or TMDB's trending list moves. Pinning them
	// here would mean editing the mirror after every sync, so the rows' contents
	// are covered structurally instead (see the teaser tests below) and only
	// their static copy — eyebrow, heading, lede — is compared.
	$query = './/text()[not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " as-teaser-row ")])]';
	foreach ( ( new DOMXPath( $root->ownerDocument ) )->query( $query, $root ) as $node ) {
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

/**
 * Give both teaser rows something to render.
 *
 * The rows are omitted entirely when their data is missing — that is the
 * designed behaviour, not a failure — so without this the parity tests would
 * compare a mirror that has them against a render that does not.
 *
 * Only the row's presence and shape are seeded. What is IN the rows is
 * catalogue data and deliberately outside the mirror comparison.
 */
function af_landing_seed_teasers() {
	$item = function ( $title ) {
		return array(
			't'      => $title,
			'poster' => 'https://image.tmdb.org/t/p/w342/' . md5( $title ) . '.jpg',
			'meta'   => '2024',
		);
	};

	set_transient(
		'afristream_portal_tmdb',
		array(
			'movies' => array( $item( 'Film A' ), $item( 'Film B' ), $item( 'Film C' ), $item( 'Film D' ) ),
			'series' => array( $item( 'Series A' ), $item( 'Series B' ), $item( 'Series C' ), $item( 'Series D' ) ),
		),
		HOUR_IN_SECONDS
	);

	// Editor Picks needs no seeding: it reads the baked data/editor-picks.json
	// that ships with the plugin, which is present in the repo and is exactly
	// what production reads.
}

/**
 * What the preview mirror represents: a site with the device offer turned on.
 * The mirror is static, so parity only holds against a configured install.
 */
function af_landing_seed_setup_plan() {
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 999 );
	update_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, 'https://pay.example.test/afristream-setup' );
}

/**
 * A published page per policy, at the slug the preview harness serves it from.
 *
 * The footer's Legal column is built from whichever policy pages exist, so
 * without these the render has an empty column where the mirror has four
 * links — and the mirror is what a payment reviewer would be opening.
 *
 * @return array<string,int> Page ids, keyed by policy.
 */
function af_landing_seed_policy_pages() {
	$slugs = array(
		'terms'        => '/terms/',
		'privacy'      => '/privacy/',
		'refunds'      => '/refund-policy/',
		'cancellation' => '/cancellation-policy/',
	);

	$ids = array();
	$id  = 100;
	foreach ( AFRISTREAM_POLICIES as $key => $policy ) {
		++$id;
		af_seed_post( $id, $policy['title'], 'publish', 'page' );
		update_post_meta( $id, '_wp_page_template', $policy['template'] );
		af_seed_permalink( $id, $slugs[ $key ] );
		$ids[ $key ] = $id;
	}
	return $ids;
}

// -- Mirror parity -------------------------------------------------------

af_test( 'the rendered sections appear in the same order as the preview mirror', function () {
	af_landing_seed_portal_page();
	af_landing_seed_teasers();
	af_landing_seed_setup_plan();
	af_landing_seed_policy_pages();

	af_assert_same(
		af_landing_section_ids( af_landing_mirror_root() ),
		af_landing_section_ids( af_landing_php_root() ),
		'section ids, in document order'
	);
} );

af_test( 'marker counts match the preview mirror', function () {
	af_landing_seed_portal_page();
	af_landing_seed_teasers();
	af_landing_seed_setup_plan();
	af_landing_seed_policy_pages();
	$root = af_landing_php_root();

	af_assert_same( 26, af_landing_xpath_count( $root, './/*[@data-platform]' ), 'ticker platforms (13, doubled for a seamless loop)' );
	af_assert_same( 4, af_landing_xpath_count( $root, './/*[@data-feature]' ), 'feature cards' );
	af_assert_same( 8, af_landing_xpath_count( $root, './/*[@data-setup-tab]' ), 'device guides in the picker' );
	af_assert_same( 8, af_landing_xpath_count( $root, './/*[@data-faq]' ), 'FAQ items' );
	af_assert_same( 7, af_landing_xpath_count( $root, './/*[@data-reveal]' ), 'sections that fade in on scroll' );
	af_assert_same( 16, af_landing_xpath_count( $root, './/*[@data-teaser-card]' ), 'teaser posters (eight per row, two rows)' );
	af_assert_same( 0, af_landing_xpath_count( $root, './/figure' ), 'no testimonials — the section was dropped in 0.24.0' );
	af_assert_same( 1, af_landing_xpath_count( $root, './/h1' ), 'exactly one h1 on the page' );
	af_assert_same( 6, af_landing_xpath_count( $root, './/*[@data-onboard]' ), 'CTAs that open the onboarding flow' );
} );


af_test( 'the visible text matches the preview mirror', function () {
	af_landing_seed_portal_page();
	af_landing_seed_teasers();
	af_landing_seed_setup_plan();
	af_landing_seed_policy_pages();

	af_assert_same(
		af_landing_text_nodes( af_landing_mirror_root() ),
		af_landing_text_nodes( af_landing_php_root() ),
		'visible text nodes, in order'
	);
} );

// -- Enqueue order -----------------------------------------------------------

af_test( 'the template hooks its enqueue rather than calling it, so registration runs first', function () {
	// A landing page shipped once with the stylesheet's enqueue running before
	// anything had registered it, because afristream_landing_template() called
	// afristream_landing_enqueue() directly from template_include — earlier than
	// wp_enqueue_scripts, which is what runs the registration. This asserts the
	// wiring itself, not just the outcome: the direct call is what regressed.
	af_assert(
		in_array( 'afristream_landing_register_assets', $GLOBALS['af_landing_boot_actions'], true ),
		'registration is hooked to wp_enqueue_scripts at load'
	);

	afristream_landing_register_assets();
	afristream_landing_enqueue();

	af_assert( af_wp_style_enqueued( 'afristream-landing' ), 'the stylesheet is enqueued once registration has run' );
	af_assert( af_wp_script_enqueued( 'afristream-landing' ), 'and so is the script' );
} );

af_test( 'the shortcode path enqueues directly, since the_content runs after wp_enqueue_scripts', function () {
	afristream_landing_register_assets();

	$body = afristream_landing_shortcode();

	af_assert( false !== strpos( $body, 'data-testid="signup-cta"' ), 'the shortcode renders the closing CTA' );
	af_assert( af_wp_style_enqueued( 'afristream-landing' ), 'and its stylesheet' );
} );

// -- The Get Started URL -----------------------------------------------------

af_test( 'an unset Get Started URL falls back to the pricing section, never to nothing', function () {
	delete_option( AFRISTREAM_LANDING_CTA_OPTION );

	af_assert_same( '#pricing', afristream_landing_cta_url(), 'the fallback' );
	af_assert(
		false !== strpos( afristream_landing_signup(), 'href="#pricing"' ),
		'the CTA every other Get Started button leads to is never inert'
	);
} );

af_test( 'every Get Started button on the page uses the configured URL, not another CTA', function () {
	// They used to jump to each other, so a visitor could press Get Started
	// twice and still not have started anything.
	af_landing_seed_portal_page();
	af_landing_seed_teasers();
	delete_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION );
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );

	$root  = af_landing_php_root();
	$xpath = new DOMXPath( $root->ownerDocument );
	$hrefs = array();
	foreach ( $xpath->query( './/a[starts-with(normalize-space(text()), "Get Started") or starts-with(normalize-space(text()), "Get AfriStream")]', $root ) as $link ) {
		$hrefs[] = $link->getAttribute( 'href' );
	}

	af_assert_same( 5, count( $hrefs ), 'header, mobile menu, hero, pricing card, closing section' );
	af_assert_same(
		array( 'https://pay.example.test/afristream?v=' . AFRISTREAM_PORTAL_VERSION ),
		array_values( array_unique( $hrefs ) ),
		'all five resolve to the one configured URL'
	);
} );

af_test( 'a configured Get Started URL is what the CTA points at', function () {
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );

	af_assert_same( 'https://pay.example.test/afristream?v=' . AFRISTREAM_PORTAL_VERSION, afristream_landing_cta_url(), 'the configured URL, cache-busted with the plugin version' );
	af_assert(
		false !== strpos( afristream_landing_signup(), 'href="https://pay.example.test/afristream?v=' . AFRISTREAM_PORTAL_VERSION . '"' ),
		'and the section renders it'
	);
} );

af_test( 'mailto and tel links are accepted; javascript: is refused and keeps the old value', function () {
	af_assert_same( 'mailto:sales@example.test', afristream_landing_sanitize_cta_url( 'mailto:sales@example.test' ), 'mailto' );
	af_assert_same( 'tel:+27110000000', afristream_landing_sanitize_cta_url( 'tel:+27110000000' ), 'tel' );

	// A javascript: URL must not read as "the user cleared the field": esc_url()
	// strips it to an empty string, which would silently blank a working link.
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );
	af_assert_same(
		'https://pay.example.test/afristream',
		afristream_landing_sanitize_cta_url( 'javascript:alert(1)' ),
		'a refused value leaves the stored one alone'
	);

	af_assert_same( '', afristream_landing_sanitize_cta_url( '   ' ), 'but clearing it really does clear it' );
} );

// -- The device offer --------------------------------------------------------

af_test( 'no setup fee still renders the page, with the device offer left to the flow', function () {
	delete_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION );

	// The offer lives in the onboarding flow now, not in a price point on the
	// page, so an unpriced fee changes nothing about what the page renders.
	$body = afristream_landing_body();

	af_assert( false === strpos( $body, 'once-off setup' ), 'no fee is advertised on the page' );
	af_assert( false !== strpos( $body, 'data-testid="landing-setup"' ), 'and the setup guides are untouched' );
} );

af_test( 'a setup fee with no checkout of its own falls back, never goes dead', function () {
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );
	delete_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION );

	af_assert_same(
		'https://pay.example.test/afristream?v=' . AFRISTREAM_PORTAL_VERSION,
		afristream_landing_setup_cta_url(),
		'it borrows the Get Started URL'
	);

	// And with neither set, it lands on the same last resort as everything else.
	delete_option( AFRISTREAM_LANDING_CTA_OPTION );
	af_assert_same( '#pricing', afristream_landing_setup_cta_url(), 'never nothing' );
} );

af_test( 'the price is configurable, and an emptied field means the default, not free', function () {
	delete_option( AFRISTREAM_LANDING_PRICE_OPTION );
	af_assert_same( AFRISTREAM_LANDING_PRICE, afristream_landing_price(), 'unset falls back to the default' );

	update_option( AFRISTREAM_LANDING_PRICE_OPTION, 0 );
	af_assert_same( AFRISTREAM_LANDING_PRICE, afristream_landing_price(), 'and so does a zero' );

	update_option( AFRISTREAM_LANDING_PRICE_OPTION, 1799 );
	af_assert_same( 1799, afristream_landing_price(), 'a real value is used' );
	// The price is quoted in the onboarding flow only — the page itself no
	// longer prints one anywhere.
	af_assert( false !== strpos( afristream_onboarding_modal(), 'R1799' ), 'in the onboarding flow' );
	af_assert( false === strpos( afristream_landing_signup(), 'R1799' ), 'and nowhere on the page' );

	delete_option( AFRISTREAM_LANDING_PRICE_OPTION );
} );

// -- Setup guides ------------------------------------------------------------

af_test( 'every device guide renders a tab and a panel, with only the first open', function () {
	$root  = af_landing_root( '<div class="as-landing">' . afristream_landing_setup() . '</div>' );
	$count = count( AFRISTREAM_LANDING_DEVICES );

	af_assert_same( $count, af_landing_xpath_count( $root, './/*[@data-setup-tab]' ), 'one tab per device' );
	af_assert_same( $count, af_landing_xpath_count( $root, './/*[@data-setup-panel]' ), 'one panel per device' );
	af_assert_same( 1, af_landing_xpath_count( $root, './/*[@data-setup-panel][not(@hidden)]' ), 'exactly one panel open' );
	af_assert_same( 1, af_landing_xpath_count( $root, './/*[@aria-selected="true"]' ), 'and exactly one tab selected' );
} );

af_test( 'every tab names the panel it controls, and that panel exists', function () {
	// A tablist whose aria-controls points at nothing is worse than no ARIA at
	// all: a screen reader announces a relationship the page cannot honour.
	$root  = af_landing_root( '<div class="as-landing">' . afristream_landing_setup() . '</div>' );
	$xpath = new DOMXPath( $root->ownerDocument );

	foreach ( $xpath->query( './/*[@data-setup-tab]', $root ) as $tab ) {
		$id = $tab->getAttribute( 'aria-controls' );
		af_assert_same(
			1,
			$xpath->query( './/*[@id="' . $id . '"]', $root )->length,
			'aria-controls="' . $id . '" resolves to one panel'
		);
	}
} );

af_test( 'the sourcing guide says the device is quoted before anything is ordered', function () {
	// The one guide that describes a sale rather than a set of steps, and the
	// one the reviewer will read hardest.
	$html = afristream_landing_setup();

	af_assert( false !== strpos( $html, 'No device yet' ), 'the option is offered' );
	af_assert( false !== strpos( $html, 'Nothing is ordered until you say yes.' ), 'and nothing is bought without agreement' );
	af_assert( false !== strpos( $html, 'separate cost' ), 'the device is priced apart from the subscription' );
} );

// -- What the page must never claim ------------------------------------------

af_test( 'the page never offers content, only setup and search', function () {
	af_landing_seed_portal_page();
	af_landing_seed_teasers();

	$text = implode( ' ', af_landing_text_nodes( af_landing_php_root() ) );

	foreach ( array( 'IPTV', '20 000', '19 000', 'Save thousands', 'cancel your other subscriptions', 'Money Back' ) as $claim ) {
		af_assert(
			false === stripos( $text, $claim ),
			'the page must not say "' . $claim . '"'
		);
	}
} );

af_test( 'the footer carries the standing disclaimer', function () {
	$footer = afristream_landing_footer();

	af_assert( false !== strpos( $footer, 'data-testid="landing-disclaimer"' ), 'the disclaimer is rendered' );
	af_assert( false !== strpos( $footer, 'do not host, stream, supply, share or resell' ), 'and says so plainly' );
	af_assert( false !== strpos( $footer, 'trademarks of their respective owners' ), 'and whose the names are' );
} );

// -- Teasers -------------------------------------------------------------

af_test( 'a cold catalogue cache leaves the What to Watch row out rather than fetching', function () {
	// The whole point of reading the transient instead of calling
	// afristream_portal_tmdb_catalog(): a public page render must never be the
	// thing that goes off to TMDB. No cache, no row.
	delete_transient( 'afristream_portal_tmdb' );

	af_assert_same( array(), afristream_landing_watch_teaser_items(), 'no items from a cold cache' );
	af_assert_same( '', afristream_landing_teaser_row( 'watch', 'What to Watch', 'Trending', 'Lede', array() ), 'and no section markup' );
} );

af_test( 'a cold cache queues exactly one warm, however many visitors arrive', function () {
	delete_transient( 'afristream_portal_tmdb' );
	$GLOBALS['af_store']['scheduled'] = array();

	for ( $i = 0; $i < 5; $i++ ) {
		afristream_landing_watch_teaser_items();
	}

	af_assert_same( 1, count( $GLOBALS['af_store']['scheduled'] ), 'one queued job, not one per view' );
	af_assert_same( AFRISTREAM_LANDING_WARM_HOOK, $GLOBALS['af_store']['scheduled'][0]['hook'], 'the warm hook' );
} );

af_test( 'the What to Watch row interleaves films and series', function () {
	af_landing_seed_teasers();

	$items  = afristream_landing_watch_teaser_items();
	$titles = array_map( function ( $i ) { return $i['t']; }, $items );

	af_assert_same(
		array( 'Film A', 'Series A', 'Film B', 'Series B', 'Film C', 'Series C', 'Film D', 'Series D' ),
		$titles,
		'alternating, capped at the teaser count'
	);
} );

af_test( 'a teaser row skips an item with no poster instead of rendering a hole', function () {
	$html = afristream_landing_teaser_row(
		'picks',
		'Editor Picks',
		'Hand-picked',
		'Lede',
		array(
			array( 't' => 'Has a poster', 'poster' => 'https://example.test/a.jpg', 'meta' => '2024' ),
			array( 't' => 'No poster', 'poster' => '', 'meta' => '2024' ),
		)
	);

	af_assert_same( 1, substr_count( $html, 'data-teaser-card' ), 'only the item with artwork' );
	af_assert( false === strpos( $html, 'No poster' ), 'the posterless item is absent entirely' );
} );

af_test( 'teaser posters carry their title as alt text and are not links', function () {
	af_landing_seed_teasers();
	// af_landing_root() looks for the page wrapper, which a bare section pair
	// does not carry.
	$root = af_landing_root( '<div class="as-landing">' . afristream_landing_teasers() . '</div>' );

	af_assert_same( 16, af_landing_xpath_count( $root, './/*[@data-teaser-card]' ), 'two full rows' );
	af_assert_same( 0, af_landing_xpath_count( $root, './/a' ), 'nothing in a teaser is clickable' );
	af_assert_same( 0, af_landing_xpath_count( $root, './/img[not(@alt) or @alt=""]' ), 'every poster names its title' );
	af_assert_same( 16, af_landing_xpath_count( $root, './/img[@loading="lazy"]' ), 'posters load lazily' );
} );
