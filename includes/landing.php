<?php
/**
 * The public landing page.
 *
 * Rendered as a page template rather than a shortcode by default: the design
 * brings its own sticky header and footer, so letting a theme wrap it would
 * mean two headers on one page. The template prints the whole document and
 * the theme's header.php/footer.php never run.
 *
 * [afristream_landing] stays available for anyone who wants the sections
 * inside an existing page instead.
 *
 * @package bluegroup-project-afristream
 */

defined( 'ABSPATH' ) || exit;

const AFRISTREAM_LANDING_TEMPLATE = 'afristream-landing.php';

/**
 * Register (but don't enqueue) the landing assets. They load only on the
 * pages that actually use the template or the shortcode.
 */
function afristream_landing_register_assets() {
	wp_register_style(
		'afristream-landing-fonts',
		'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Space+Mono&display=swap',
		array(),
		null
	);
	wp_register_style(
		'afristream-landing',
		plugins_url( 'assets/landing.css', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-landing-fonts' ),
		AFRISTREAM_PORTAL_VERSION
	);
	// The switcher owns the money formatter both of the other scripts print
	// through, so it is a dependency rather than a fourth thing to remember to
	// enqueue in the right order.
	wp_register_script(
		'afristream-currency',
		plugins_url( 'assets/currency.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
	wp_register_script(
		'afristream-landing',
		plugins_url( 'assets/landing.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-currency' ),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
	wp_register_style(
		'afristream-onboarding',
		plugins_url( 'assets/onboarding.css', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-landing' ),
		AFRISTREAM_PORTAL_VERSION
	);
	wp_register_script(
		'afristream-onboarding',
		plugins_url( 'assets/onboarding.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-currency' ),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'afristream_landing_register_assets' );

function afristream_landing_enqueue() {
	wp_enqueue_style( 'afristream-landing' );
	wp_enqueue_script( 'afristream-landing' );
	wp_enqueue_style( 'afristream-onboarding' );
	wp_enqueue_script( 'afristream-onboarding' );
}

/**
 * The customer portal's permalink, for the header and footer "Dashboard" links.
 *
 * Prefers the page chosen in Settings → AfriStream Portal. With none chosen it
 * looks for the first published page containing the portal shortcode, and
 * caches the answer for a day. Returns '' when there is nothing to link to —
 * the header then omits the link rather than pointing at the home page and
 * calling it a dashboard.
 */
function afristream_landing_portal_url() {
	$chosen = (int) get_option( 'afristream_portal_page_id', 0 );
	if ( $chosen > 0 && 'publish' === get_post_status( $chosen ) ) {
		return (string) get_permalink( $chosen );
	}

	$found = get_transient( 'afristream_landing_portal_page' );
	if ( false === $found ) {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				's'              => 'afristream_portal',
				'fields'         => 'ids',
			)
		);
		$found = 0;
		foreach ( $pages as $page_id ) {
			if ( has_shortcode( (string) get_post_field( 'post_content', $page_id ), 'afristream_portal' ) ) {
				$found = $page_id;
				break;
			}
		}
		set_transient( 'afristream_landing_portal_page', $found, DAY_IN_SECONDS );
	}

	return $found > 0 ? (string) get_permalink( $found ) : '';
}

/**
 * Offer the template on the page editor's Template dropdown.
 */
function afristream_landing_register_template( $templates ) {
	$templates[ AFRISTREAM_LANDING_TEMPLATE ] = __( 'AfriStream Landing', 'bluegroup-project-afristream' );
	return $templates;
}
add_filter( 'theme_page_templates', 'afristream_landing_register_template' );

/**
 * Render the whole document ourselves when the template is the chosen one.
 */
function afristream_landing_template( $template ) {
	if ( ! is_page() ) {
		return $template;
	}
	if ( AFRISTREAM_LANDING_TEMPLATE !== get_page_template_slug( get_queried_object_id() ) ) {
		return $template;
	}

	// Hooked, not called directly. wp_head(), printed below, is what fires
	// wp_enqueue_scripts — which is also what runs
	// afristream_landing_register_assets() (hooked to the same action, at the
	// default priority, added at file load so it runs first). Calling
	// afristream_landing_enqueue() here would run it before any of those
	// handles were registered. Today that would only lose the stylesheet;
	// it previously also silently dropped an inline script attached to an
	// unregistered handle, which is the failure that put this comment here.
	add_action( 'wp_enqueue_scripts', 'afristream_landing_enqueue' );

	// Printed here rather than returned: this IS the document.
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'afristream-landing-page' ); ?>>
<?php wp_body_open(); ?>
<?php
	echo afristream_landing_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	wp_footer();
?>
</body>
</html>
	<?php
	// Nothing further should render.
	exit;
}
add_filter( 'template_include', 'afristream_landing_template' );

/**
 * [afristream_landing] — the same sections, inside an existing page.
 */
function afristream_landing_shortcode() {
	afristream_landing_enqueue();
	return afristream_landing_body();
}
add_shortcode( 'afristream_landing', 'afristream_landing_shortcode' );

/**
 * Settings → AfriStream Portal → Landing page → Portal page.
 */
function afristream_landing_register_settings() {
	register_setting(
		'afristream_portal',
		'afristream_portal_page_id',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'afristream_landing_sanitize_page_id',
			'default'           => 0,
		)
	);

	add_settings_section(
		'afristream_landing',
		__( 'Landing page', 'bluegroup-project-afristream' ),
		'afristream_landing_settings_intro',
		'bluegroup-project-afristream'
	);

	register_setting(
		'afristream_portal',
		AFRISTREAM_LANDING_CTA_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'afristream_landing_sanitize_cta_url',
			'default'           => '',
		)
	);

	add_settings_field(
		'afristream_portal_page_id',
		__( 'Portal page', 'bluegroup-project-afristream' ),
		'afristream_landing_page_field',
		'bluegroup-project-afristream',
		'afristream_landing',
		array( 'label_for' => 'afristream_portal_page_id' )
	);

	register_setting(
		'afristream_portal',
		AFRISTREAM_LANDING_SETUP_CTA_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'afristream_landing_sanitize_cta_url',
			'default'           => '',
		)
	);

	register_setting(
		'afristream_portal',
		AFRISTREAM_LANDING_PRICE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => AFRISTREAM_LANDING_PRICE,
		)
	);

	register_setting(
		'afristream_portal',
		AFRISTREAM_LANDING_SETUP_FEE_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		AFRISTREAM_LANDING_CTA_OPTION,
		__( 'Annual subscription checkout URL (no setup)', 'bluegroup-project-afristream' ),
		'afristream_landing_cta_field',
		'bluegroup-project-afristream',
		'afristream_landing',
		array( 'label_for' => AFRISTREAM_LANDING_CTA_OPTION )
	);

	add_settings_field(
		AFRISTREAM_LANDING_PRICE_OPTION,
		__( 'Annual price (R)', 'bluegroup-project-afristream' ),
		'afristream_landing_price_field',
		'bluegroup-project-afristream',
		'afristream_landing',
		array( 'label_for' => AFRISTREAM_LANDING_PRICE_OPTION )
	);

	add_settings_field(
		AFRISTREAM_LANDING_SETUP_FEE_OPTION,
		__( 'Setup fee (R)', 'bluegroup-project-afristream' ),
		'afristream_landing_setup_fee_field',
		'bluegroup-project-afristream',
		'afristream_landing',
		array( 'label_for' => AFRISTREAM_LANDING_SETUP_FEE_OPTION )
	);

	add_settings_field(
		AFRISTREAM_LANDING_SETUP_CTA_OPTION,
		__( 'Annual subscription checkout URL (with setup)', 'bluegroup-project-afristream' ),
		'afristream_landing_setup_cta_field',
		'bluegroup-project-afristream',
		'afristream_landing',
		array( 'label_for' => AFRISTREAM_LANDING_SETUP_CTA_OPTION )
	);
}
add_action( 'admin_init', 'afristream_landing_register_settings' );

/** Where the Get Started destination is stored. */
const AFRISTREAM_LANDING_CTA_OPTION = 'afristream_landing_cta_url';

/** Where the "subscription + setup fee" checkout destination is stored. */
const AFRISTREAM_LANDING_SETUP_CTA_OPTION = 'afristream_landing_setup_cta_url';

/** Where the advertised annual price is stored. */
const AFRISTREAM_LANDING_PRICE_OPTION = 'afristream_landing_price';

/** Where the one-off setup fee is stored. */
const AFRISTREAM_LANDING_SETUP_FEE_OPTION = 'afristream_landing_setup_fee';

/**
 * Where every Get Started button points until that setting is filled in.
 *
 * Every one of them — header, mobile menu, hero, closing section — resolves
 * through afristream_landing_cta_url(), so setting it once moves all four.
 * They used to jump to each other, which meant a visitor could press Get
 * Started twice and still not have started anything.
 *
 * With nothing configured they fall back to the pricing section — the page's
 * own answer to what pressing Get Started is about to cost, and never a link
 * to the button you just pressed.
 */
const AFRISTREAM_LANDING_CTA_FALLBACK = '#pricing';

/**
 * Reject anything that is not an http(s), mailto or tel link.
 *
 * esc_url() alone would let through a javascript: URL by stripping it to an
 * empty string, which reads as "cleared the setting" rather than "refused it",
 * so the protocol list is explicit and a rejected value keeps the old one.
 */
function afristream_landing_sanitize_cta_url( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	$clean = esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
	if ( '' === $clean ) {
		add_settings_error(
			AFRISTREAM_LANDING_CTA_OPTION,
			'afristream_landing_cta_url',
			__( 'The checkout URL was not saved: it must be a http, https, mailto or tel link.', 'bluegroup-project-afristream' )
		);
		return (string) get_option( AFRISTREAM_LANDING_CTA_OPTION, '' );
	}

	return $clean;
}

/**
 * Stamp the plugin version onto a checkout link.
 *
 * Checkout pages get cached — by the store, by a CDN, by the browser — and a
 * customer following a stale one can be shown last month's price point. The
 * version changes with every release, so the link changes with it, while
 * staying identical between page loads: an affiliate can still copy it, share
 * it and have it keep working.
 *
 * Anything that is not an http(s) link is returned untouched — there is no
 * cache to bust on "#pricing", a mailto: or a tel:.
 */
function afristream_landing_bust( $url ) {
	$url = (string) $url;
	if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
		return $url;
	}
	return add_query_arg( 'v', AFRISTREAM_PORTAL_VERSION, $url );
}

/**
 * Where the page's Get Started buttons point.
 *
 * Falls back to the pricing section rather than to nothing: an empty setting
 * must not leave the page's main call to action inert.
 */
function afristream_landing_cta_url() {
	$url = trim( (string) get_option( AFRISTREAM_LANDING_CTA_OPTION, '' ) );
	return '' !== $url ? afristream_landing_bust( $url ) : AFRISTREAM_LANDING_CTA_FALLBACK;
}

function afristream_landing_cta_field() {
	printf(
		'<input type="url" class="regular-text" name="%1$s" id="%1$s" value="%2$s" placeholder="https://">',
		esc_attr( AFRISTREAM_LANDING_CTA_OPTION ),
		esc_attr( (string) get_option( AFRISTREAM_LANDING_CTA_OPTION, '' ) )
	);
	echo '<p class="description">' . esc_html__( 'The checkout for the subscription on its own. Used by the landing page buttons, the onboarding pop-up and the affiliate buy links. Left empty, the landing page buttons scroll to the pricing section instead.', 'bluegroup-project-afristream' ) . '</p>';
}

/**
 * Where a customer who takes the device offer is sent.
 *
 * Falls back to the ordinary Get Started URL for the same reason that one falls
 * back to the pricing section: a priced offer with a dead button is worse than one
 * that sends the customer somewhere they can still buy.
 */
function afristream_landing_setup_cta_url() {
	$url = trim( (string) get_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, '' ) );
	return '' !== $url ? afristream_landing_bust( $url ) : afristream_landing_cta_url();
}

/**
 * The saved checkout URL for a price point, with no page-anchor fallback.
 *
 * The Get Started helpers fall back to "#pricing" so a landing page button is
 * never inert, but an affiliate's buy link has no page to scroll — it is
 * shared as a URL. So this returns an empty string when nothing is saved, and
 * the caller decides what to do about it.
 *
 * @param bool $with_setup Whether to return the subscription + setup checkout.
 */
function afristream_landing_checkout_url( $with_setup = false ) {
	$plain = trim( (string) get_option( AFRISTREAM_LANDING_CTA_OPTION, '' ) );
	if ( ! $with_setup ) {
		return $plain;
	}

	$setup = trim( (string) get_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, '' ) );
	return '' !== $setup ? $setup : $plain;
}

/**
 * The advertised annual price.
 *
 * A stored 0 falls back to the default rather than printing "R0": an emptied
 * field means "use the usual price", never "give it away".
 */
function afristream_landing_price() {
	$price = absint( get_option( AFRISTREAM_LANDING_PRICE_OPTION, AFRISTREAM_LANDING_PRICE ) );
	return $price > 0 ? $price : AFRISTREAM_LANDING_PRICE;
}

/**
 * The one-off device setup fee. Zero means the device offer is not being made.
 */
function afristream_landing_setup_fee() {
	return absint( get_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 0 ) );
}

function afristream_landing_setup_cta_field() {
	printf(
		'<input type="url" class="regular-text" name="%1$s" id="%1$s" value="%2$s" placeholder="https://">',
		esc_attr( AFRISTREAM_LANDING_SETUP_CTA_OPTION ),
		esc_attr( (string) get_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, '' ) )
	);
	echo '<p class="description">' . esc_html__( 'The checkout covering the subscription and the device setup fee together. Used by the onboarding pop-up and the affiliate buy links. Left empty, they both fall back to the checkout above.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_landing_price_field() {
	printf(
		'<input type="number" min="0" step="1" class="small-text" name="%1$s" id="%1$s" value="%2$s">',
		esc_attr( AFRISTREAM_LANDING_PRICE_OPTION ),
		esc_attr( (string) afristream_landing_price() )
	);
	echo '<p class="description">' . esc_html__( 'The annual price shown in the onboarding pop-up.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_landing_setup_fee_field() {
	printf(
		'<input type="number" min="0" step="1" class="small-text" name="%1$s" id="%1$s" value="%2$s">',
		esc_attr( AFRISTREAM_LANDING_SETUP_FEE_OPTION ),
		esc_attr( (string) afristream_landing_setup_fee() )
	);
	echo '<p class="description">' . esc_html__( 'The one-off fee for sourcing and setting up a device. Leave this at 0 and the onboarding pop-up never offers one.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_landing_sanitize_page_id( $value ) {
	// A changed choice should take effect at once, not wait out the cache.
	delete_transient( 'afristream_landing_portal_page' );
	return absint( $value );
}

function afristream_landing_settings_intro() {
	echo '<p>' . esc_html__( 'The landing page links to the customer portal from its header and footer. Leave this on "Detect automatically" and the plugin finds the first published page containing the [afristream_portal] shortcode.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_landing_page_field() {
	wp_dropdown_pages(
		array(
			'name'              => 'afristream_portal_page_id',
			'id'                => 'afristream_portal_page_id',
			'selected'          => (int) get_option( 'afristream_portal_page_id', 0 ),
			'show_option_none'  => __( 'Detect automatically', 'bluegroup-project-afristream' ),
			'option_none_value' => '0',
		)
	);
	echo '<p class="description">' . esc_html__( 'The page holding the customer portal. Without one, the landing page leaves its Dashboard links out rather than pointing them somewhere wrong.', 'bluegroup-project-afristream' ) . '</p>';
}

/**
 * The wrapper every page the plugin renders opens with — the landing page and
 * each of the four policy pages.
 *
 * It carries the exchange rates, so the header's currency switcher works
 * wherever the header does. Prices are quoted in the terms as well as on the
 * pricing card, and a switcher that silently stopped working on the page
 * quoting them would be the one place it mattered most.
 */
function afristream_landing_open() {
	return '<div class="as-landing" data-fx-rates="' . esc_attr( (string) wp_json_encode( afristream_fx_rates() ) ) . '">';
}

/**
 * The page body: header, sections, footer.
 */
function afristream_landing_body() {
	return afristream_landing_open()
		. afristream_landing_header()
		. afristream_landing_hero()
		. afristream_landing_integrations()
		. afristream_landing_watch_teaser()
		. afristream_landing_features()
		. afristream_landing_pricing()
		. afristream_landing_setup()
		. afristream_landing_picks_teaser()
		. afristream_landing_faq()
		. afristream_landing_signup()
		. afristream_landing_footer()
		. afristream_onboarding_modal()
		. '</div>';
}

const AFRISTREAM_LANDING_PLATFORMS = array(
	'Netflix',
	'Showmax',
	'Amazon Prime Video',
	'Disney+',
	'Apple TV+',
	'Hulu',
	'HBO Max',
	'Paramount+',
	'YouTube Premium',
	'SuperSport',
	'Sky Sports',
	'BBC',
	'BT Sport',
);

/**
 * The hero. The handoff's animated constellation is a lot of markup for one
 * decorative panel, so it is drawn in CSS from a short list of tiles rather
 * than hand-written per tile.
 *
 * The geometry and timing come from the "AfriStream hub animation" design,
 * which runs three scenes on an 8s loop: Arrive (2.4s, tiles drop in),
 * Connect (2.2s, the hub appears and the wires draw), Converge (3.4s, a pulse
 * runs the wires, the tiles dim and pull inward, the hub rises and the
 * wordmark lands). Every percentage in the keyframes is that timeline: a
 * scene-relative progress p maps to (scene start + p * scene duration) / 8s.
 *
 * The wire paths are the design's own connector geometry, in its 1920x1080
 * coordinate space, so the SVG carries that viewBox and the tiles are placed
 * as the same fractions of it. An earlier revision left the wires out and
 * relied on tile motion alone to carry the "assembling into one hub" idea;
 * that read as a different animation, so they are drawn now.
 *
 * One simplification against the spec remains: it calls for a dimmed EPG grid
 * backdrop behind two gradient scrims, and this ships one linear gradient
 * (.as-hero background), one radial glow (.as-stage-glow) and the design's
 * dot grid (.as-stage-grid) rather than a full EPG texture.
 */
function afristream_landing_hero() {
	$proof = array(
		'Setup on the device you already own',
		'Search once, see who has it',
		'Your own accounts, the providers’ own apps',
	);
	$tiles = array( 'Netflix', 'Showmax', 'SuperSport', 'Prime Video', 'Disney+', 'Apple TV+' );

	// The design's connectorPath() output, tile by tile, in its 1920x1080 space.
	// Order matches the tiles above: left column top-to-bottom, then right.
	// The middle pair run straight into the hub edge; the corners run
	// horizontally, turn through a quadratic elbow, then drop or rise into it.
	$wires = array(
		'M 596 351 H 850 Q 914 351 914 395 V 470',
		'M 436 566 H 862',
		'M 596 781 H 850 Q 914 781 914 737 V 662',
		'M 1324 351 H 1070 Q 1006 351 1006 395 V 470',
		'M 1484 566 H 1058',
		'M 1324 781 H 1070 Q 1006 781 1006 737 V 662',
	);

	$proof_html = '';
	foreach ( $proof as $item ) {
		$proof_html .= '<span class="as-proof" data-proof>' . esc_html( $item ) . '</span>';
	}

	$tiles_html = '';
	foreach ( $tiles as $i => $tile ) {
		$tiles_html .= '<span class="as-tile as-tile-' . (int) ( $i + 1 ) . '">' . esc_html( $tile ) . '</span>';
	}

	// Two paths per wire: the line that draws itself, and the short dash that
	// runs along it once the hub is up.
	$wires_html = '';
	foreach ( $wires as $i => $d ) {
		$n           = (int) ( $i + 1 );
		$wires_html .= '<path class="as-wire as-wire-' . $n . '" d="' . esc_attr( $d ) . '" pathLength="1"></path>';
		$wires_html .= '<path class="as-pulse as-pulse-' . $n . '" d="' . esc_attr( $d ) . '" pathLength="1"></path>';
	}

	return '
<section id="top" class="as-hero" data-testid="landing-hero">
  <div class="as-hero-in">
    <span class="as-eyebrow as-eyebrow-dot">Device setup &amp; content discovery</span>
    <h1>Set it up. Then find it.</h1>
    <p class="as-lede">AfriStream gets your streaming device set up properly, then helps you find which of the services you already pay for has the thing you want to watch.</p>
    <div class="as-hero-cta">
      <a class="as-btn as-btn-primary" data-testid="hero-cta" data-onboard href="' . esc_url( afristream_landing_cta_url() ) . '">Get Started</a>
      <a class="as-btn as-btn-ghost" href="#setup">Setup guides</a>
    </div>
    <div class="as-proofs">' . $proof_html . '</div>
    <div class="as-stage" aria-hidden="true">
      <div class="as-stage-glow"></div>
      <div class="as-stage-grid"></div>
      <svg class="as-wires" viewBox="0 0 1920 1080" focusable="false">' . $wires_html . '</svg>
      ' . $tiles_html . '
      <span class="as-hub"></span>
      <span class="as-wordmark">AfriStream<small>Set up once. Search once.</small></span>
    </div>
  </div>
</section>';
}

function afristream_landing_integrations() {
	$run = '';
	// Listed twice: the marquee translates by half its width, so the second
	// copy is what makes the restart invisible.
	foreach ( array_merge( AFRISTREAM_LANDING_PLATFORMS, AFRISTREAM_LANDING_PLATFORMS ) as $platform ) {
		$run .= '<span data-platform>' . esc_html( $platform ) . '</span>';
	}

	return '
<section id="services" class="as-integrations" data-testid="landing-ticker">
  <span class="as-eyebrow as-eyebrow-muted as-integrations-label">Services we cover</span>
  <div class="as-marquee"><div class="as-marquee-run">' . $run . '</div></div>
</section>';
}

/** How many posters a teaser row shows. A sample, not a catalogue. */
const AFRISTREAM_LANDING_TEASER_COUNT = 8;

/**
 * Editor Picks for the teaser, straight from the file baked at deploy time.
 *
 * Deliberately afristream_portal_baked_picks() and not
 * afristream_portal_editor_picks(): the latter falls through to resolving every
 * title against TMDB when the baked file is absent, which is work no public
 * page render should ever start.
 *
 * @return array<int,array<string,mixed>>
 */
function afristream_landing_picks_teaser_items() {
	$baked = function_exists( 'afristream_portal_baked_picks' ) ? afristream_portal_baked_picks() : null;
	$picks = ( is_array( $baked ) && ! empty( $baked['picks'] ) ) ? $baked['picks'] : array();

	return array_slice( $picks, 0, AFRISTREAM_LANDING_TEASER_COUNT );
}

/**
 * What to Watch for the teaser — read from the warm TMDB cache, never fetched.
 *
 * afristream_portal_tmdb_catalog() populates that cache, but on a miss it makes
 * a dozen-odd TMDB calls before returning. The portal can afford that behind a
 * spinner; a marketing page cannot, and the visitor who paid for it would be
 * whoever happened to arrive first after the 12-hour transient expired. So this
 * reads the transient directly and gives up when it is cold, leaving the row out
 * rather than holding up the page.
 *
 * A cold cache also queues a one-off warm, so the next visitor gets the row
 * without anyone having opened the portal.
 *
 * @return array<int,array<string,mixed>>
 */
function afristream_landing_watch_teaser_items() {
	$catalog = get_transient( 'afristream_portal_tmdb' );

	if ( ! is_array( $catalog ) ) {
		afristream_landing_queue_catalog_warm();
		return array();
	}

	// Trending films and series interleaved, so the row reads as a mix rather
	// than four films followed by four programmes.
	$movies = isset( $catalog['movies'] ) && is_array( $catalog['movies'] ) ? $catalog['movies'] : array();
	$series = isset( $catalog['series'] ) && is_array( $catalog['series'] ) ? $catalog['series'] : array();

	$items = array();
	for ( $i = 0; count( $items ) < AFRISTREAM_LANDING_TEASER_COUNT; $i++ ) {
		if ( ! isset( $movies[ $i ] ) && ! isset( $series[ $i ] ) ) {
			break;
		}
		if ( isset( $movies[ $i ] ) ) {
			$items[] = $movies[ $i ];
		}
		if ( count( $items ) < AFRISTREAM_LANDING_TEASER_COUNT && isset( $series[ $i ] ) ) {
			$items[] = $series[ $i ];
		}
	}

	return $items;
}

/** Marker for the queued warm, so a cold cache queues one job and not one per view. */
const AFRISTREAM_LANDING_WARM_HOOK = 'afristream_landing_warm_catalog';

/**
 * Ask for the TMDB catalogue to be built out of band.
 *
 * wp_next_scheduled() is what stops a burst of traffic on a cold cache queueing
 * an event per request.
 */
function afristream_landing_queue_catalog_warm() {
	if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( AFRISTREAM_LANDING_WARM_HOOK ) ) {
		return;
	}
	wp_schedule_single_event( time() + 30, AFRISTREAM_LANDING_WARM_HOOK );
}

function afristream_landing_warm_catalog() {
	if ( function_exists( 'afristream_portal_tmdb_catalog' ) ) {
		afristream_portal_tmdb_catalog();
	}
}
add_action( AFRISTREAM_LANDING_WARM_HOOK, 'afristream_landing_warm_catalog' );

/**
 * One teaser row: an eyebrow, a heading and a strip of posters.
 *
 * The posters carry their titles as alt text and nothing is clickable — the
 * row exists to show the catalogue is real, and the page's only action is to
 * sign up. An item with no poster is skipped rather than rendered as a hole.
 *
 * @param string                            $id      Section id, for the testid.
 * @param string                            $eyebrow Small label above the heading.
 * @param string                            $heading The row's heading.
 * @param string                            $lede    One line under the heading.
 * @param array<int,array<string,mixed>>    $items   Poster-bearing rows.
 * @return string Empty when there is nothing to show.
 */
function afristream_landing_teaser_row( $id, $eyebrow, $heading, $lede, $items ) {
	$cards = '';
	foreach ( $items as $item ) {
		$poster = isset( $item['poster'] ) ? (string) $item['poster'] : '';
		if ( '' === $poster ) {
			continue;
		}
		$title = isset( $item['t'] ) ? (string) $item['t'] : '';
		$meta  = isset( $item['meta'] ) ? (string) $item['meta'] : '';

		$cards .= '<li class="as-teaser-card" data-teaser-card>'
			. '<img src="' . esc_url( $poster ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async" width="342" height="513">'
			. '<span class="as-teaser-title">' . esc_html( $title ) . '</span>'
			. ( '' !== $meta ? '<span class="as-teaser-meta">' . esc_html( $meta ) . '</span>' : '' )
			. '</li>';
	}

	if ( '' === $cards ) {
		return '';
	}

	return '
<section id="' . esc_attr( $id ) . '" class="as-teaser" data-testid="landing-teaser-' . esc_attr( $id ) . '" data-reveal>
  <span class="as-eyebrow as-eyebrow-muted">' . esc_html( $eyebrow ) . '</span>
  <h2>' . esc_html( $heading ) . '</h2>
  <p class="as-teaser-lede">' . esc_html( $lede ) . '</p>
  <ul class="as-teaser-row">' . $cards . '</ul>
</section>';
}

/**
 * The two teaser rows sit apart on the page — What to Watch straight after the
 * ticker, Editor Picks much further down, after the testimonials — so they are
 * separate functions rather than one block. Either can come back empty: Editor
 * Picks when the baked file is missing, What to Watch when the catalogue cache
 * is cold. The page reads correctly with one row, or with none.
 */
function afristream_landing_watch_teaser() {
	return afristream_landing_teaser_row(
		'watch',
		'What to Watch',
		'Trending right now',
		'What people are watching this week. Sign in and we will tell you which of your services is carrying each one.',
		afristream_landing_watch_teaser_items()
	);
}

function afristream_landing_picks_teaser() {
	return afristream_landing_teaser_row(
		'picks',
		'Editor Picks',
		'Hand-picked by us',
		'A running list of what we think is worth your evening, with a note of where to find each one. Hundreds more inside.',
		afristream_landing_picks_teaser_items()
	);
}

/** Both rows, in page order — used by the tests that check them together. */
function afristream_landing_teasers() {
	return afristream_landing_watch_teaser() . afristream_landing_picks_teaser();
}

/**
 * Point an in-page link at the landing page when it is being printed somewhere
 * else.
 *
 * The header and footer are shared with the four policy pages, and their links
 * are fragments — "#pricing", "#faq". On the landing page that is exactly
 * right. On a policy page it is a link that appears to do nothing, which is
 * how a customer reading the refund terms gets stranded.
 *
 * Only fragments are rewritten. A configured checkout URL is already absolute
 * and must be left alone.
 *
 * @param string $href The link as the landing page would write it.
 * @param string $base The landing page's URL, or '' when we are on it.
 * @return string
 */
function afristream_landing_anchor( $href, $base ) {
	if ( '' === $base || '#' !== substr( (string) $href, 0, 1 ) ) {
		return $href;
	}
	return rtrim( $base, '/' ) . '/' . $href;
}

/**
 * The published page using the landing template, for the policy pages to link
 * their headers and footers back at.
 *
 * Falls back to the site's home page: a header that cannot find the landing
 * page must still take somebody somewhere, and on most installs the landing
 * page is the home page anyway.
 */
function afristream_landing_url() {
	if ( isset( $GLOBALS['afristream_landing_url'] ) ) {
		return $GLOBALS['afristream_landing_url'];
	}

	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_wp_page_template',
					'value' => AFRISTREAM_LANDING_TEMPLATE,
				),
			),
		)
	);

	$GLOBALS['afristream_landing_url'] = $pages ? (string) get_permalink( $pages[0] ) : home_url( '/' );
	return $GLOBALS['afristream_landing_url'];
}

/**
 * Sticky header. The nav collapses to a burger below 860px; both the full nav
 * and the menu panel are always in the markup and CSS decides which shows, so
 * a resize never leaves the page without navigation.
 */
function afristream_landing_header( $base = '' ) {
	$portal = afristream_landing_portal_url();
	$cta    = afristream_landing_anchor( afristream_landing_cta_url(), $base );
	$links  = array(
		'#features' => 'What we do',
		'#pricing'  => 'Pricing',
		'#setup'    => 'Setup guides',
		'#services' => 'Services we cover',
		'#faq'      => 'FAQ',
	);

	$nav  = '';
	$menu = '';
	foreach ( $links as $href => $label ) {
		$to    = esc_attr( afristream_landing_anchor( $href, $base ) );
		$nav  .= '<a href="' . $to . '">' . esc_html( $label ) . '</a>';
		$menu .= '<a href="' . $to . '">' . esc_html( $label ) . '</a>';
	}

	$dashboard = $portal
		? '<a class="as-dash" href="' . esc_url( $portal ) . '">Dashboard</a>'
		: '';
	$menu_dash = $portal
		? '<a href="' . esc_url( $portal ) . '">Dashboard</a>'
		: '';

	return '
<header class="as-head" data-testid="landing-header">
  <div class="as-head-in">
    <a class="as-brand" href="' . esc_attr( afristream_landing_anchor( '#top', $base ) ) . '">
      <img src="' . esc_url( afristream_landing_asset( 'afristream-icon.svg' ) ) . '" alt="AfriStream" width="26" height="27">AfriStream
    </a>
    <nav class="as-nav-full" data-testid="landing-nav">' . $nav . '</nav>
    <div class="as-head-cta">' . afristream_currency_switcher( 'header' ) . $dashboard . '<a class="as-btn as-btn-primary" data-testid="header-cta" data-onboard href="' . esc_url( $cta ) . '">Get Started</a></div>
    <button class="as-burger" type="button" data-testid="landing-burger" aria-expanded="false" aria-controls="as-menu-panel" aria-label="Menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cd2df5" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
    </button>
  </div>
  <div class="as-menu" id="as-menu-panel" data-testid="landing-menu" hidden>' . $menu . $menu_dash . afristream_currency_switcher( 'menu' ) . '<a class="as-btn as-btn-primary" data-onboard href="' . esc_url( $cta ) . '">Get Started</a></div>
</header>';
}

/**
 * Every section below the hero opens the same way.
 */
function afristream_landing_section_header( $eyebrow, $title, $sub ) {
	return '
  <div class="as-sec-head">
    <span class="as-eyebrow">' . esc_html( $eyebrow ) . '</span>
    <h2>' . esc_html( $title ) . '</h2>
    <p>' . esc_html( $sub ) . '</p>
  </div>';
}

function afristream_landing_features() {
	$features = array(
		array(
			'icon'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 19h8"/><path d="M12 16v3"/>',
			'title' => 'Guided device setup',
			'body'  => 'We walk you through getting your TV, stick or box working properly, and signing in to the apps you already subscribe to.',
		),
		array(
			'icon'  => '<circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/>',
			'title' => 'Search once, not six times',
			'body'  => 'Look a film or series up once and we tell you which of the services you pay for is listing it, so you stop hunting app by app.',
		),
		array(
			'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
			'title' => 'No content, ever',
			'body'  => 'We do not host, stream, supply or resell any video, channel or subscription. Everything you watch plays in the provider’s own app, on your own account.',
		),
		array(
			'icon'  => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/>',
			'title' => 'Real people to ask',
			'body'  => 'Stuck on a remote, a login or a picture that will not play? Message us and a person answers.',
		),
	);

	$cards = '';
	foreach ( $features as $feature ) {
		$cards .= '
      <div class="as-feature" data-feature>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#cd2df5" stroke-width="1.5" aria-hidden="true">' . $feature['icon'] . '</svg>
        <h3>' . esc_html( $feature['title'] ) . '</h3>
        <p>' . esc_html( $feature['body'] ) . '</p>
      </div>';
	}

	return '
<section id="features" class="as-sec as-sec-features" data-testid="landing-features">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'What we do',
			'Two jobs, done properly',
			'We set your device up, and we help you find what to watch on the subscriptions you already have. That is the whole service.'
		) . '
    <div class="as-features">' . $cards . '</div>
  </div>
</section>';
}

/**
 * What the subscription buys, as a list.
 *
 * Read from the onboarding flow's list rather than kept as a second copy: the
 * pricing card and the first screen of the flow it opens are the same promise,
 * and two lists drift the moment one of them is edited.
 */
function afristream_landing_plan_features() {
	$items = '';
	foreach ( AFRISTREAM_ONBOARDING_INCLUDES as $line ) {
		$items .= '<li>' . esc_html( $line ) . '</li>';
	}
	return $items;
}

/**
 * Whether the device offer is being sold at all.
 *
 * The same test the onboarding flow makes: a priced fee is not enough on its
 * own, because a fee billed through the plain subscription checkout is a fee
 * that checkout cannot charge. Both have to be configured or neither card nor
 * question appears.
 */
function afristream_landing_setup_offered() {
	return afristream_landing_setup_fee() > 0
		&& afristream_landing_setup_cta_url() !== afristream_landing_cta_url();
}

/** Said under both cards. */
const AFRISTREAM_LANDING_PLAN_FINE = 'Billed once a year. Nothing here is a video, a channel or a subscription — those stay yours, on your own accounts, paid directly to the providers.';

/**
 * The optional second card: a device, sourced and set up.
 *
 * Priced, not sold — the device itself is quoted and agreed before anything is
 * ordered, and this is the setup work around it.
 */
function afristream_landing_setup_plan() {
	if ( ! afristream_landing_setup_offered() ) {
		return '';
	}

	return '
    <div class="as-plan" data-testid="plan-setup">
      <span class="as-plan-badge">Optional</span>
      <span class="as-plan-name">Device, sourced and set up</span>
      <span class="as-plan-price">' . afristream_price( afristream_landing_setup_fee() ) . '<small>once off</small></span>
      ' . afristream_fx_note() . '
      <span class="as-plan-tag">Added to your first order, on top of the subscription.</span>
      <ul class="as-plan-features">
        <li>We recommend a device that suits your TV, your internet and the services you pay for</li>
        <li>The device is quoted and agreed in writing before anything is ordered</li>
        <li>Set up and updated before it reaches you, so it works when you plug it in</li>
        <li>Signed in to your own subscription apps on a call</li>
      </ul>
      <a class="as-btn as-btn-ghost as-plan-cta" data-testid="plan-setup-cta" data-onboard href="' . esc_url( afristream_landing_setup_cta_url() ) . '">Get Started</a>
      <span class="as-fine">No content comes with the device. The apps and the subscriptions on it are yours.</span>
    </div>';
}

/**
 * Pricing: one annual plan, and the device offer beside it when there is one.
 *
 * Every price in here is printed in Rand and carries that figure on a
 * data-money attribute for the header switcher to convert. What the customer
 * is billed does not move with it — see includes/currency.php.
 */
function afristream_landing_pricing() {
	return '
<section id="pricing" class="as-sec as-sec-pricing" data-testid="landing-pricing">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Pricing',
			'One price, and you know what it is',
			'A single annual subscription for the setup, the guides, the search and the support. No tiers, no add-ons you find out about later.'
		) . '
    <div class="as-plans">
    <div class="as-plan" data-testid="plan">
      <span class="as-plan-badge">Annual</span>
      <span class="as-plan-name">AfriStream subscription</span>
      <span class="as-plan-price">' . afristream_price( afristream_landing_price() ) . '<small>/ year</small></span>
      ' . afristream_fx_note() . '
      <span class="as-plan-tag">Everything we do, for a year, for one payment.</span>
      <ul class="as-plan-features">' . afristream_landing_plan_features() . '</ul>
      <a class="as-btn as-btn-primary as-plan-cta" data-testid="plan-cta" data-onboard href="' . esc_url( afristream_landing_cta_url() ) . '">Get Started</a>
      <span class="as-fine">' . esc_html( AFRISTREAM_LANDING_PLAN_FINE ) . '</span>
    </div>' . afristream_landing_setup_plan() . '
    </div>' . afristream_landing_delivery() . '
  </div>
</section>';
}

/**
 * When the customer gets what they just paid for, under the price they are
 * about to pay.
 *
 * The same list the terms print, and deliberately on the page rather than only
 * behind a legal link: "how soon" is a question somebody asks with their card
 * in their hand, and an answer they have to go and find is an answer that cost
 * us the sale.
 */
function afristream_landing_delivery() {
	$steps = '';
	foreach ( AFRISTREAM_DELIVERY as $step ) {
		$steps .= '<li>' . esc_html( $step ) . '</li>';
	}

	return '
    <div class="as-delivery" data-testid="delivery">
      <span class="as-eyebrow as-eyebrow-muted">When you get it</span>
      <ul class="as-delivery-list">' . $steps . '</ul>
    </div>';
}

/**
 * The setup guides.
 *
 * One panel per device, with the steps written out rather than hidden behind a
 * support ticket: the page's claim is that setup is the service, so the page
 * has to show what setup actually is. The picker is a tablist — a single panel
 * shows at a time, and the whole set is in the markup so a visitor with no
 * JavaScript sees every guide stacked rather than none of them.
 *
 * "No device yet" is a guide too, not a product page: it says what we would
 * ask, that we confirm the price before ordering, and that the device is a
 * separate cost.
 */
const AFRISTREAM_LANDING_DEVICES = array(
	array(
		'key'   => 'firetv',
		'label' => 'Fire TV Stick',
		'lede'  => 'Amazon’s stick, into any TV with a spare HDMI port. About fifteen minutes from box to first play.',
		'steps' => array(
			'Plug the stick into a spare HDMI port and its power lead into the mains — the TV’s own USB port rarely gives it enough power.',
			'Switch the TV to that HDMI input, then pair the remote when it asks.',
			'Join your Wi-Fi and sign in with an Amazon account. A free one is enough; you only need Prime if you subscribe to Prime Video.',
			'Open the Appstore and install the apps for the services you actually pay for.',
			'Sign in to each app with your own account details for that service.',
			'Sign in to AfriStream on your phone or laptop, and use it to look up what to watch.',
		),
		'note'  => 'We can do all of this with you on a call, or set the stick up before it reaches you.',
	),
	array(
		'key'   => 'appletv',
		'label' => 'Apple TV',
		'lede'  => 'Apple’s box. The setup does most of the work for you if you already have an iPhone.',
		'steps' => array(
			'Plug the box into HDMI and the mains, and switch the TV to that input.',
			'Hold an unlocked iPhone near it to copy your Wi-Fi and Apple Account across, or type both in by hand.',
			'Finish the on-screen setup and let it install any waiting update.',
			'Install the apps for the services you subscribe to from the App Store, and sign in to each with your own details.',
			'Sign in to AfriStream on your phone or laptop to search.',
		),
		'note'  => 'Apple keeps some app sign-ins behind the Apple Account rather than the app itself, which catches people out. Worth doing the first one with us.',
	),
	array(
		'key'   => 'androidtv',
		'label' => 'Android / Google TV',
		'lede'  => 'A Chromecast with Google TV, an Android box, or a set with Google TV built in.',
		'steps' => array(
			'Plug it in, switch the TV to that HDMI input and pair the remote.',
			'Join Wi-Fi and sign in with a Google account, or finish setup from the Google Home app on your phone.',
			'Install your subscription apps from the Play Store and sign in to each one.',
			'Reorder the apps row so the ones you use sit first — recommendations are above your own apps by default.',
			'Sign in to AfriStream on your phone or laptop to search.',
		),
		'note'  => 'Cheap unbranded Android boxes often ship with old firmware and apps that will never update. We will tell you if yours is one of them.',
	),
	array(
		'key'   => 'samsung',
		'label' => 'Samsung TV',
		'lede'  => 'Samsung sets run Tizen, with their own store built in. No extra device needed.',
		'steps' => array(
			'Finish the on-screen setup and connect the set to your Wi-Fi.',
			'Sign in with a Samsung account — several apps will not install without one.',
			'Open the Apps tile and install the services you subscribe to.',
			'Sign in to each app. Where it shows a pairing code, type that on your phone rather than fighting the remote.',
			'Run a software update from Settings, then restart the set.',
			'Sign in to AfriStream on your phone or laptop to search.',
		),
		'note'  => 'Sets older than roughly 2017 stop getting new versions of some apps. We will check yours before you spend anything.',
	),
	array(
		'key'   => 'lg',
		'label' => 'LG TV',
		'lede'  => 'LG sets run webOS, with the LG Content Store built in.',
		'steps' => array(
			'Finish setup, connect to Wi-Fi and accept the terms — the store stays locked until you do.',
			'Create or sign in to an LG account.',
			'Open the LG Content Store and install the apps for your subscriptions.',
			'Sign in to each with your own account.',
			'Check for a firmware update in Settings, then restart the set.',
			'Sign in to AfriStream on your phone or laptop to search.',
		),
		'note'  => 'Getting comfortable with the Magic Remote pointer trips people up more than any of the sign-ins. We will walk you through it.',
	),
	array(
		'key'   => 'laptop',
		'label' => 'Laptop',
		'lede'  => 'Nothing to install. Every service and AfriStream itself run in a browser.',
		'steps' => array(
			'Use an up-to-date Chrome, Edge, Firefox or Safari — older browsers get blocked or dropped to a low resolution.',
			'Sign in to each service’s own website with your own account.',
			'To watch on the TV, run an HDMI cable across or cast the browser tab where the service allows it.',
			'Sign in to AfriStream in the same browser and search from there.',
		),
		'note'  => 'Some services cap browser playback below the quality their app gives you. That is their limit, not your setup.',
	),
	array(
		'key'   => 'mobile',
		'label' => 'Phone or tablet',
		'lede'  => 'Useful on its own, and the easiest way to type sign-in codes for everything else.',
		'steps' => array(
			'Install the apps for the services you subscribe to from the App Store or Play Store.',
			'Sign in to each with your own account.',
			'Set downloads to Wi-Fi only if your data is metered.',
			'Cast or AirPlay to the TV where the app allows it.',
			'Sign in to AfriStream in your phone’s browser to search.',
		),
		'note'  => 'A few apps block casting for licensing reasons. Where that happens, installing the app on the TV itself is the way round it.',
	),
	array(
		'key'   => 'none',
		'label' => 'No device yet',
		'lede'  => 'Tell us what you have and we will source the right device, set it up, and confirm the price before anything is ordered.',
		'steps' => array(
			'Tell us the make and rough age of your TV, your internet speed, and which services you pay for.',
			'We recommend a device that suits all three, and confirm the price with you in writing.',
			'Nothing is ordered until you say yes.',
			'We set the device up and update it before it is delivered, so it works when you plug it in.',
			'We sign you in to your own subscription apps on a call, and give you your AfriStream login at the same time.',
		),
		'note'  => 'The device is a separate cost, quoted and agreed up front. We do not supply content with it — the apps are yours, on your own accounts.',
	),
);

/**
 * One device guide: a tab and the panel it controls.
 *
 * @param array<string,mixed> $device One AFRISTREAM_LANDING_DEVICES entry.
 * @param bool                $open   Whether this is the panel shown first.
 * @return string
 */
function afristream_landing_setup_panel( array $device, $open ) {
	$key   = (string) $device['key'];
	$steps = '';
	foreach ( $device['steps'] as $step ) {
		$steps .= '<li>' . esc_html( $step ) . '</li>';
	}

	return '
      <div class="as-setup-panel" role="tabpanel" id="as-setup-' . esc_attr( $key ) . '" aria-labelledby="as-setup-tab-' . esc_attr( $key ) . '" data-setup-panel="' . esc_attr( $key ) . '"' . ( $open ? '' : ' hidden' ) . '>
        <h3>' . esc_html( $device['label'] ) . '</h3>
        <p class="as-setup-lede">' . esc_html( $device['lede'] ) . '</p>
        <ol class="as-setup-steps">' . $steps . '</ol>
        <p class="as-setup-note">' . esc_html( $device['note'] ) . '</p>
      </div>';
}

function afristream_landing_setup() {
	$tabs   = '';
	$panels = '';
	foreach ( AFRISTREAM_LANDING_DEVICES as $i => $device ) {
		$open    = 0 === $i;
		$key     = (string) $device['key'];
		$tabs   .= '
        <button type="button" class="as-setup-tab" role="tab" id="as-setup-tab-' . esc_attr( $key ) . '" aria-controls="as-setup-' . esc_attr( $key ) . '" aria-selected="' . ( $open ? 'true' : 'false' ) . '" tabindex="' . ( $open ? '0' : '-1' ) . '" data-setup-tab="' . esc_attr( $key ) . '">' . esc_html( $device['label'] ) . '</button>';
		$panels .= afristream_landing_setup_panel( $device, $open );
	}

	return '
<section id="setup" class="as-sec as-sec-setup" data-testid="landing-setup">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Setup guides',
			'Pick your device, follow the steps',
			'The real steps for the devices people actually use. Work through them yourself, or book a call and we will do it with you.'
		) . '
    <div class="as-setup">
      <div class="as-setup-picker" role="tablist" aria-label="Choose your device">' . $tabs . '
      </div>
      <div class="as-setup-panels">' . $panels . '
      </div>
    </div>
  </div>
</section>';
}

/** The annual price an unconfigured install advertises. */
const AFRISTREAM_LANDING_PRICE = 1599;

const AFRISTREAM_LANDING_FAQ = array(
	array(
		'q' => 'What does AfriStream actually provide?',
		'a' => 'Two things: we set your streaming device up properly, and we help you find which service is carrying the film or programme you want. That is the whole service — an information and setup service, nothing more.',
	),
	array(
		'q' => 'Do you supply any content, channels or subscriptions?',
		'a' => 'No. We do not host, supply, stream or resell any content — you watch on your own accounts, in the providers’ own apps.',
	),
	array(
		'q' => 'Do I still need my own subscriptions?',
		'a' => 'Yes. You keep and pay for whatever services you choose, directly with them. AfriStream does not replace a subscription and cannot get you one cheaper.',
	),
	array(
		'q' => 'What does the setup actually cover?',
		'a' => 'Getting the device connected and updated, installing the apps for the services you already pay for, signing you in to each one with your own details, and checking that playback and the remote work. We can do it with you on a call or, where you buy a device through us, before it is delivered.',
	),
	array(
		'q' => 'Which devices do you support?',
		'a' => 'Fire TV Stick, Apple TV, Android and Google TV devices, Samsung and LG smart TVs, laptops, phones and tablets. If you do not have a device yet, tell us what your TV is and we will source and set one up — at a price agreed with you before anything is ordered.',
	),
	array(
		'q' => 'How does the search work?',
		'a' => 'We read the public catalogue listings the services themselves publish, so you can look a title up once and see who is carrying it rather than opening six apps in turn. We point you at the provider’s own page; the watching happens there.',
	),
	array(
		'q' => 'Are you affiliated with Netflix, Disney+, Showmax or anyone else?',
		'a' => 'No. We have no affiliation, partnership or endorsement from any streaming service or broadcaster. All names and logos are the trademarks of their owners, and are used only to say which services we can help you set up and search.',
	),
	array(
		'q' => 'Can you help me get round regional restrictions?',
		'a' => 'No. We do not set up VPNs, proxies or anything else meant to bypass a provider’s regional restrictions, and we will not advise on it. What is available where you live is the provider’s decision.',
	),
);

/**
 * The FAQ. Items open and close independently — a single-open accordion would
 * shut an answer someone is still reading to show the one they just clicked.
 * The first opens by default so the section never reads as an empty list.
 */
function afristream_landing_faq() {
	$items = '';
	foreach ( AFRISTREAM_LANDING_FAQ as $i => $entry ) {
		$open   = 0 === $i;
		$id     = 'as-faq-' . (int) $i;
		$items .= '
      <div class="as-faq-item" data-faq>
        <button type="button" aria-expanded="' . ( $open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $id ) . '">
          <span>' . esc_html( $entry['q'] ) . '</span>
          <span class="as-faq-mark" aria-hidden="true"></span>
        </button>
        <div class="as-faq-answer" id="' . esc_attr( $id ) . '" data-faq-answer' . ( $open ? '' : ' hidden' ) . '>' . esc_html( $entry['a'] ) . '</div>
      </div>';
	}

	return '
<section id="faq" class="as-sec as-sec-faq" data-testid="landing-faq">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'FAQ',
			'Learn more about us',
			'Do you have questions? Read below to find out more.'
		) . '
    <div class="as-faq">' . $items . '</div>
  </div>
</section>';
}

/**
 * The closing call to action, and the page's last section.
 *
 * This held a newsletter embed until 0.23.0. It asks for the sale directly now,
 * through the same Get Started URL every other call to action on the page uses.
 */
function afristream_landing_signup() {
	return '
<section id="signup" class="as-sec as-sec-signup" data-testid="landing-signup">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Get Started',
			'Let us get you set up',
			'Tell us what you watch on and what you already subscribe to. We will take it from there.'
		) . '
    <div class="as-signup">
      <a class="as-btn as-btn-primary as-signup-cta" data-testid="signup-cta" data-onboard href="' . esc_url( afristream_landing_cta_url() ) . '">Get Started</a>
      <span class="as-fine">Setup and search only. You keep your own subscriptions, with the providers.</span>
    </div>
  </div>
</section>';
}

/**
 * The footer's Legal column.
 *
 * A policy with no page published for it is left out rather than linked at
 * nothing: a dead legal link is worse than a missing one, both to a customer
 * and to whoever is reviewing the business.
 */
function afristream_landing_policy_links() {
	$links = '';
	foreach ( AFRISTREAM_POLICIES as $key => $policy ) {
		$url = afristream_policy_url( $key );
		if ( $url ) {
			$links .= '<a href="' . esc_url( $url ) . '">' . esc_html( $policy['title'] ) . '</a>';
		}
	}
	return $links;
}

function afristream_landing_footer( $base = '' ) {
	$portal    = afristream_landing_portal_url();
	$dashboard = $portal ? '<a href="' . esc_url( $portal ) . '">Dashboard</a>' : '';
	$to        = function ( $hash ) use ( $base ) {
		return esc_attr( afristream_landing_anchor( $hash, $base ) );
	};

	return '
<footer class="as-foot" data-testid="landing-footer">
  <div class="as-foot-in">
    <div class="as-foot-brand">
      <span class="as-brand"><img src="' . esc_url( afristream_landing_asset( 'afristream-icon.svg' ) ) . '" alt="" width="24" height="25">AfriStream</span>
      <span class="as-muted">Device setup and content discovery.</span>
      <span class="as-fine">© ' . esc_html( gmdate( 'Y' ) ) . ' AfriStream</span>
    </div>
    <div class="as-foot-col">
      <span class="as-eyebrow as-eyebrow-muted">Company</span>
      <a href="' . $to( '#features' ) . '">What we do</a><a href="' . $to( '#pricing' ) . '">Pricing</a><a href="' . $to( '#setup' ) . '">Setup guides</a><a href="' . $to( '#services' ) . '">Services we cover</a>
    </div>
    <div class="as-foot-col">
      <span class="as-eyebrow as-eyebrow-muted">Help</span>
      <a href="' . $to( '#faq' ) . '">FAQ</a><a href="mailto:support@afristream.io">support@afristream.io</a>' . $dashboard . '
    </div>
    <div class="as-foot-col" data-testid="footer-legal">
      <span class="as-eyebrow as-eyebrow-muted">Legal</span>' . afristream_landing_policy_links() . '
    </div>
  </div>
  <p class="as-foot-note" data-testid="landing-disclaimer">AfriStream is an independent device-setup and content-discovery service. We do not host, stream, supply, share or resell any video, channel or subscription, and we are not affiliated with, endorsed by or acting for any streaming service or broadcaster. You watch on your own accounts, in the providers’ own apps. All service names and logos are the trademarks of their respective owners.</p>
</footer>';
}

/**
 * URL for a bundled asset. Kept in one place so the preview mirror and the
 * plugin can differ in exactly one spot.
 */
function afristream_landing_asset( $file ) {
	return plugins_url( 'assets/' . $file, dirname( __DIR__ ) . '/bluegroup-project-afristream.php' );
}
