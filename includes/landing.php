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
	wp_register_script(
		'afristream-savings',
		plugins_url( 'assets/savings.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
	wp_register_script(
		'afristream-landing',
		plugins_url( 'assets/landing.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-savings' ),
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
		array( 'afristream-savings' ),
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
 * Every one of them — header, mobile menu, pricing card, calculator, closing
 * section — resolves through afristream_landing_cta_url(), so setting it once
 * moves all five. They used to jump to each other (#signup, #pricing), which
 * meant a visitor could press Get Started twice and still not be buying
 * anything.
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
 * Where the page's Get Started buttons point.
 *
 * Falls back to the pricing section rather than to nothing: an empty setting
 * must not leave the page's main call to action inert.
 */
function afristream_landing_cta_url() {
	$url = trim( (string) get_option( AFRISTREAM_LANDING_CTA_OPTION, '' ) );
	return '' !== $url ? $url : AFRISTREAM_LANDING_CTA_FALLBACK;
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
 * Where the "subscription + setup fee" price point's button points.
 *
 * Falls back to the ordinary Get Started URL for the same reason that one falls
 * back to the pricing section: a configured price point with a dead button is
 * worse than one that sends the customer somewhere they can still buy.
 */
function afristream_landing_setup_cta_url() {
	$url = trim( (string) get_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, '' ) );
	return '' !== $url ? $url : afristream_landing_cta_url();
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
 * The one-off setup fee. Zero means the setup price point is not being offered.
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
	echo '<p class="description">' . esc_html__( 'The checkout covering the subscription and the setup fee together. Used by the landing page, the onboarding pop-up and the affiliate buy links. Left empty, they all fall back to the checkout above.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_landing_price_field() {
	printf(
		'<input type="number" min="0" step="1" class="small-text" name="%1$s" id="%1$s" value="%2$s">',
		esc_attr( AFRISTREAM_LANDING_PRICE_OPTION ),
		esc_attr( (string) afristream_landing_price() )
	);
	echo '<p class="description">' . esc_html__( 'The annual price shown on the pricing card, the savings calculator and the closing call to action.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_landing_setup_fee_field() {
	printf(
		'<input type="number" min="0" step="1" class="small-text" name="%1$s" id="%1$s" value="%2$s">',
		esc_attr( AFRISTREAM_LANDING_SETUP_FEE_OPTION ),
		esc_attr( (string) afristream_landing_setup_fee() )
	);
	echo '<p class="description">' . esc_html__( 'The one-off setup fee. Leave this at 0 and the "subscription + setup" price point is left off the page altogether.', 'bluegroup-project-afristream' ) . '</p>';
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
 * The page body: header, sections, footer.
 */
function afristream_landing_body() {
	return '<div class="as-landing">'
		. afristream_landing_header()
		. afristream_landing_hero()
		. afristream_landing_integrations()
		. afristream_landing_watch_teaser()
		. afristream_landing_features()
		. afristream_landing_calculator()
		. afristream_landing_pricing()
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
		'20 000+ live channels',
		'Films, series & sport in one app',
		'Works on the stick you already own',
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
    <span class="as-eyebrow as-eyebrow-dot">20 000+ feeds, live now</span>
    <h1>Every stream. One app.</h1>
    <p class="as-lede">Save thousands with AfriStream. We collect and display thousands of movies, series and live TV channels from all your favourite streams.</p>
    <div class="as-hero-cta">
      <a class="as-btn as-btn-primary" href="#calculate">Calculate Savings</a>
      <a class="as-btn as-btn-ghost" href="#pricing">View Pricing</a>
    </div>
    <div class="as-proofs">' . $proof_html . '</div>
    <div class="as-stage" aria-hidden="true">
      <div class="as-stage-glow"></div>
      <div class="as-stage-grid"></div>
      <svg class="as-wires" viewBox="0 0 1920 1080" focusable="false">' . $wires_html . '</svg>
      ' . $tiles_html . '
      <span class="as-hub"></span>
      <span class="as-wordmark">AfriStream<small>Every platform. One app.</small></span>
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
<section id="integrations" class="as-integrations" data-testid="landing-ticker">
  <span class="as-eyebrow as-eyebrow-muted as-integrations-label">Integrations</span>
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
		'A glimpse of what is playing this week. Subscribers get the full list, updated daily.',
		afristream_landing_watch_teaser_items()
	);
}

function afristream_landing_picks_teaser() {
	return afristream_landing_teaser_row(
		'picks',
		'Editor Picks',
		'Hand-picked by us',
		'A running list of what we think is worth your evening. Hundreds more inside.',
		afristream_landing_picks_teaser_items()
	);
}

/** Both rows, in page order — used by the tests that check them together. */
function afristream_landing_teasers() {
	return afristream_landing_watch_teaser() . afristream_landing_picks_teaser();
}

/**
 * Sticky header. The nav collapses to a burger below 860px; both the full nav
 * and the menu panel are always in the markup and CSS decides which shows, so
 * a resize never leaves the page without navigation.
 */
function afristream_landing_header() {
	$portal = afristream_landing_portal_url();
	$cta    = afristream_landing_cta_url();
	$links  = array(
		'#features'     => 'Features',
		'#integrations' => 'Integrations',
		'#calculate'    => 'Calculate',
		'#pricing'      => 'Pricing',
		'#faq'          => 'FAQ',
	);

	$nav  = '';
	$menu = '';
	foreach ( $links as $href => $label ) {
		// The badge hangs off the link's corner, so the link it hangs from has to
		// be the positioned one — hence a class on the anchor, not just the span.
		$sale  = '#pricing' === $href ? '<span class="as-sale">SALE</span>' : '';
		$class = '' !== $sale ? ' class="as-nav-sale"' : '';
		$nav  .= '<a' . $class . ' href="' . esc_attr( $href ) . '">' . esc_html( $label ) . $sale . '</a>';
		$menu .= '<a' . $class . ' href="' . esc_attr( $href ) . '">' . esc_html( $label ) . $sale . '</a>';
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
    <a class="as-brand" href="#top">
      <img src="' . esc_url( afristream_landing_asset( 'afristream-icon.svg' ) ) . '" alt="AfriStream" width="26" height="27">AfriStream
    </a>
    <nav class="as-nav-full" data-testid="landing-nav">' . $nav . '</nav>
    <div class="as-head-cta">' . $dashboard . '<a class="as-btn as-btn-primary" data-testid="header-cta" data-onboard href="' . esc_url( $cta ) . '">Get Started</a></div>
    <button class="as-burger" type="button" data-testid="landing-burger" aria-expanded="false" aria-controls="as-menu-panel" aria-label="Menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cd2df5" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
    </button>
  </div>
  <div class="as-menu" id="as-menu-panel" data-testid="landing-menu" hidden>' . $menu . $menu_dash . '<a class="as-btn as-btn-primary" data-onboard href="' . esc_url( $cta ) . '">Get Started</a></div>
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
			'icon'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z"/>',
			'title' => 'Multiple platforms in one',
			'body'  => 'AfriStream collates data from all your favourite providers to bring you a complete collection of live TV, movies and series.',
		),
		array(
			'icon'  => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/>',
			'title' => 'All in one profile',
			'body'  => 'Your subscription gives you unrestricted access to the AfriStream App. No hidden costs, no guessing.',
		),
		array(
			'icon'  => '<path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="9"/>',
			'title' => 'A network of options',
			'body'  => "Today's entertainment needs multiple apps and subscriptions. AfriStream squashes this problem with one app.",
		),
		array(
			'icon'  => '<path d="M4 17l6-6 4 4 6-8"/><path d="M14 7h6v6"/>',
			'title' => 'Save time, money, effort',
			'body'  => 'Find what to watch and where — and save thousands on your streaming subscriptions every year.',
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
			'Features',
			'Powerful features at your fingertips',
			'Stop juggling multiple apps! Bring your content directly to you with AfriStream.'
		) . '
    <div class="as-features">' . $cards . '</div>
  </div>
</section>';
}

const AFRISTREAM_LANDING_PLAN_FEATURES = array(
	'Access to the AfriStream App',
	'Access to the AfriStream Portal',
	'Dedicated support guides',
	'Works with any smart TV or device',
	'Regular price of R1999.99',
);

/** The small print both price points carry. */
const AFRISTREAM_LANDING_PLAN_FINE = 'AfriStream does not guarantee any stream availability or up-time. 14 Day Money Back Guarantee. Fee may vary with exchange rates.';

function afristream_landing_plan_features( array $extra = array() ) {
	$rows = '';
	foreach ( array_merge( AFRISTREAM_LANDING_PLAN_FEATURES, $extra ) as $feature ) {
		$rows .= '<li data-plan-feature>' . esc_html( $feature ) . '</li>';
	}
	return $rows;
}

/**
 * The "subscription + setup fee" price point, or nothing.
 *
 * An unconfigured fee means the option is not being sold: showing the card at
 * R0 would advertise a free setup, and its button would only lead back to the
 * checkout the first card already offers.
 */
function afristream_landing_setup_plan() {
	$fee = afristream_landing_setup_fee();
	if ( 0 === $fee ) {
		return '';
	}

	return '
    <div class="as-plan as-plan-setup-card" data-testid="plan-setup">
      <span class="as-plan-badge">Setup Included</span>
      <span class="as-plan-name">Annual Plan + Setup</span>
      <span class="as-plan-price">R' . (int) afristream_landing_price() . '<small>/ year</small></span>
      <span class="as-plan-setup">+ R' . (int) $fee . ' once-off setup</span>
      <span class="as-plan-tag">We get you up and running, then it is fire and forget.</span>
      <ul class="as-plan-features">' . afristream_landing_plan_features( array( 'Guided setup done for you' ) ) . '</ul>
      <a class="as-btn as-btn-primary as-plan-cta as-plan-cta-setup" data-testid="plan-setup-cta" data-onboard="setup" href="' . esc_url( afristream_landing_setup_cta_url() ) . '">Get Started with Setup</a>
      <span class="as-fine">' . esc_html( AFRISTREAM_LANDING_PLAN_FINE ) . '</span>
    </div>';
}

function afristream_landing_pricing() {
	return '
<section id="pricing" class="as-sec as-sec-pricing" data-testid="landing-pricing">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Pricing',
			'Budget-friendly pricing',
			'One simple plan giving you access to over 20 000 feeds in one platform. AfriStream shows you what to watch, when to watch it and how to watch it — all from a single dashboard.'
		) . '
    <div class="as-plans">
    <div class="as-plan" data-testid="plan">
      <span class="as-plan-badge">Limited Time Offer!</span>
      <span class="as-plan-name">Annual Plan</span>
      <span class="as-plan-price">R' . (int) afristream_landing_price() . '<small>/ year</small></span>
      <span class="as-plan-tag">One simple subscription, fire and forget!</span>
      <ul class="as-plan-features">' . afristream_landing_plan_features() . '</ul>
      <a class="as-btn as-btn-primary as-plan-cta" data-testid="plan-cta" data-onboard href="' . esc_url( afristream_landing_cta_url() ) . '">Get Started</a>
      <span class="as-fine">' . esc_html( AFRISTREAM_LANDING_PLAN_FINE ) . '</span>
    </div>' . afristream_landing_setup_plan() . '
    </div>
  </div>
</section>';
}

/**
 * Annualised Rand prices. The first block is from published South African
 * monthly pricing, mid-2026; the second is international pricing converted at
 * roughly R18 to the dollar.
 */
const AFRISTREAM_LANDING_SUBS = array(
	array( 'Netflix Premium', 2748 ),
	array( 'Live sport bundle', 8388 ),
	array( 'Showmax + Premier League', 1800 ),
	array( 'Amazon Prime', 399 ),
	array( 'Disney Plus Premium', 1908 ),
	array( 'Apple TV Plus', 1500 ),
	array( 'YouTube Premium', 864 ),
	array( 'Crunchyroll Mega Fan', 580 ),
	array( 'Viu Premium', 588 ),
	array( 'Hulu', 2160 ),
	array( 'HBO Max', 4968 ),
	array( 'Paramount Plus', 3024 ),
	array( 'Peacock', 3672 ),
	array( 'BritBox', 1980 ),
	array( 'ESPN Play', 2592 ),
);

/** The annual price an unconfigured install advertises. */
const AFRISTREAM_LANDING_PRICE = 1599;

function afristream_landing_calculator() {
	$options = '';
	foreach ( AFRISTREAM_LANDING_SUBS as $sub ) {
		$options .= '
        <button type="button" class="as-sub" data-sub-price="' . (int) $sub[1] . '" aria-pressed="false">
          <span class="as-sub-box" aria-hidden="true"></span>
          <span class="as-sub-text"><span class="as-sub-name">' . esc_html( $sub[0] ) . '</span> <span class="as-sub-price">R' . (int) $sub[1] . '/yr</span></span>
        </button>';
	}

	return '
<section id="calculate" class="as-sec as-sec-calc" data-testid="landing-calculator" data-price="' . (int) afristream_landing_price() . '">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Calculate',
			'Calculate your annual savings',
			'AfriStream can help save you thousands on your streaming subscriptions. Select your current subscriptions to see how much.'
		) . '
    <div class="as-calc">
      <div class="as-calc-card">
        <span class="as-eyebrow">Your potential saving</span>
        <span class="as-calc-saving" data-testid="calc-saving">R0</span>
        <span class="as-calc-basis">per year, based on <span data-testid="calc-basis">your selection below</span></span>
        <div class="as-calc-rows">
          <div><span>Your subscriptions now</span><span data-testid="calc-total">R0 / year</span></div>
          <div><span>AfriStream</span><span>R' . (int) afristream_landing_price() . ' / year</span></div>
        </div>
        <a class="as-btn as-btn-primary as-calc-cta" data-testid="calc-cta" data-onboard href="' . esc_url( afristream_landing_cta_url() ) . '">Get AfriStream for R' . (int) afristream_landing_price() . '!</a>
        <span class="as-fine">14 Day Money Back Guarantee.</span>
      </div>
      <div class="as-calc-pick">
        <span class="as-calc-q">Do you have any of the following subscriptions?</span>
        <div class="as-subs">' . $options . '</div>
        <div class="as-calc-other">
          <label for="as-calc-other">Any other subscriptions?</label>
          <div class="as-calc-input"><span aria-hidden="true">R</span><input id="as-calc-other" data-testid="calc-other" type="number" min="0" step="1" placeholder="0 annually" inputmode="numeric"></div>
        </div>
      </div>
    </div>
  </div>
</section>';
}

const AFRISTREAM_LANDING_FAQ = array(
	array(
		'q' => 'What does AfriStream actually provide?',
		'a' => 'AfriStream provides you with an IPTV app. This app allows you to access content from multiple locations to watch directly.',
	),
	array(
		'q' => 'How does AfriStream access the content?',
		'a' => 'Most streaming platforms offer packages where you can buy a single license to use across multiple devices. AfriStream purchases bundled subscriptions and shares them between our users, reducing the cost for everyone.',
	),
	array(
		'q' => 'Can I watch Live TV & sport?',
		'a' => 'Yes! You will be able to watch BT, SKY, BBC and more — hundreds of live channels.',
	),
	array(
		'q' => 'Can I watch movies & series?',
		'a' => 'Yes! AfriStream pulls together Netflix, Amazon, Disney, Apple, Hulu and more to bring you over 19 000+ different movies & series.',
	),
	array(
		'q' => 'Do I need other subscriptions?',
		'a' => 'No! Once you have installed the app and are happy with the service, you can cancel your other subscriptions.',
	),
	array(
		'q' => 'Are IPTV streams legal?',
		'a' => 'The legality of IPTV depends on the content provided by the service and your location. Our IPTV services operate legally by acquiring proper licenses for the content we offer.',
	),
	array(
		'q' => 'Do I need a VPN for AfriStream?',
		'a' => "A VPN isn't necessary for using IPTV apps, but it's recommended for privacy, security, and to avoid potential ISP throttling or regional restrictions.",
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
			'Unlock the power of AfriStream today',
			'Stop guessing what to watch! Thousands of movies, series and live TV — all in one platform.'
		) . '
    <div class="as-signup">
      <a class="as-btn as-btn-primary as-signup-cta" data-testid="signup-cta" data-onboard href="' . esc_url( afristream_landing_cta_url() ) . '">Get AfriStream for R' . (int) afristream_landing_price() . '</a>
      <span class="as-fine">14 Day Money Back Guarantee.</span>
    </div>
  </div>
</section>';
}

function afristream_landing_footer() {
	$portal    = afristream_landing_portal_url();
	$dashboard = $portal ? '<a href="' . esc_url( $portal ) . '">Dashboard</a>' : '';

	return '
<footer class="as-foot" data-testid="landing-footer">
  <div class="as-foot-in">
    <div class="as-foot-brand">
      <span class="as-brand"><img src="' . esc_url( afristream_landing_asset( 'afristream-icon.svg' ) ) . '" alt="" width="24" height="25">AfriStream</span>
      <span class="as-muted">Save time, money and effort with AfriStream.</span>
      <span class="as-fine">© ' . esc_html( gmdate( 'Y' ) ) . ' AfriStream</span>
    </div>
    <div class="as-foot-col">
      <span class="as-eyebrow as-eyebrow-muted">Company</span>
      <a href="#features">Features</a><a href="#integrations">Integrations</a><a href="#pricing">Pricing</a>
    </div>
    <div class="as-foot-col">
      <span class="as-eyebrow as-eyebrow-muted">Help</span>
      <a href="#faq">FAQ</a><a href="mailto:support@afristream.io">support@afristream.io</a>' . $dashboard . '
    </div>
  </div>
</footer>';
}

/**
 * URL for a bundled asset. Kept in one place so the preview mirror and the
 * plugin can differ in exactly one spot.
 */
function afristream_landing_asset( $file ) {
	return plugins_url( 'assets/' . $file, dirname( __DIR__ ) . '/bluegroup-project-afristream.php' );
}
