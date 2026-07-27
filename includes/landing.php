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
		'afristream-landing',
		plugins_url( 'assets/landing.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
	// The newsletter embed. Registered here so the inline render call below can
	// be attached to it, which guarantees the call runs after the library.
	wp_register_script(
		'surecontact-forms',
		'https://app.surecontact.com/embed/forms.js',
		array(),
		null,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'afristream_landing_register_assets' );

function afristream_landing_enqueue() {
	wp_enqueue_style( 'afristream-landing' );
	wp_enqueue_script( 'afristream-landing' );
	wp_enqueue_script( 'surecontact-forms' );
	wp_add_inline_script(
		'surecontact-forms',
		"SureContactForms.render({formId:'6e6876be-416c-4cd4-b109-3a603af6be79',container:'#surecontact-form-afristream-newsletter-sign-up'});"
	);
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

	afristream_landing_enqueue();

	// Printed here rather than returned: this IS the document.
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'afristream-landing-page' ); ?>>
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

	add_settings_field(
		'afristream_portal_page_id',
		__( 'Portal page', 'bluegroup-project-afristream' ),
		'afristream_landing_page_field',
		'bluegroup-project-afristream',
		'afristream_landing',
		array( 'label_for' => 'afristream_portal_page_id' )
	);
}
add_action( 'admin_init', 'afristream_landing_register_settings' );

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
		. afristream_landing_features()
		. afristream_landing_calculator()
		. afristream_landing_pricing()
		. afristream_landing_testimonials()
		. afristream_landing_footer()
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
 */
function afristream_landing_hero() {
	$proof = array(
		'20 000+ live channels',
		'Films, series & sport in one app',
		'Works on the stick you already own',
	);
	$tiles = array( 'Netflix', 'Showmax', 'SuperSport', 'Prime Video', 'Disney+', 'Apple TV+' );

	$proof_html = '';
	foreach ( $proof as $item ) {
		$proof_html .= '<span class="as-proof" data-proof>' . esc_html( $item ) . '</span>';
	}

	$tiles_html = '';
	foreach ( $tiles as $i => $tile ) {
		$tiles_html .= '<span class="as-tile as-tile-' . (int) ( $i + 1 ) . '">' . esc_html( $tile ) . '</span>';
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

/**
 * Sticky header. The nav collapses to a burger below 860px; both the full nav
 * and the menu panel are always in the markup and CSS decides which shows, so
 * a resize never leaves the page without navigation.
 */
function afristream_landing_header() {
	$portal = afristream_landing_portal_url();
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
		$sale  = '#pricing' === $href ? '<span class="as-sale">SALE</span>' : '';
		$nav  .= '<a href="' . esc_attr( $href ) . '">' . esc_html( $label ) . $sale . '</a>';
		$menu .= '<a href="' . esc_attr( $href ) . '">' . esc_html( $label ) . $sale . '</a>';
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
    <div class="as-head-cta">' . $dashboard . '<a class="as-btn as-btn-primary" href="#signup">Get Started</a></div>
    <button class="as-burger" type="button" data-testid="landing-burger" aria-expanded="false" aria-label="Menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cd2df5" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
    </button>
  </div>
  <div class="as-menu" data-testid="landing-menu" hidden>' . $menu . $menu_dash . '<a class="as-btn as-btn-primary" href="#signup">Get Started</a></div>
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

function afristream_landing_pricing() {
	$features = array(
		'Access to the AfriStream App',
		'Access to the AfriStream Portal',
		'Dedicated support guides',
		'Works with any smart TV or device',
		'Regular price of R1999.99',
	);

	$rows = '';
	foreach ( $features as $feature ) {
		$rows .= '<li data-plan-feature>' . esc_html( $feature ) . '</li>';
	}

	return '
<section id="pricing" class="as-sec as-sec-pricing" data-testid="landing-pricing">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Pricing',
			'Budget-friendly pricing',
			'One simple plan giving you access to over 20 000 feeds in one platform. AfriStream shows you what to watch, when to watch it and how to watch it — all from a single dashboard.'
		) . '
    <div class="as-plan">
      <span class="as-plan-badge">Limited Time Offer!</span>
      <span class="as-plan-name">Annual Plan</span>
      <span class="as-plan-price">R1599<small>/ year</small></span>
      <span class="as-plan-tag">One simple subscription, fire and forget!</span>
      <ul class="as-plan-features">' . $rows . '</ul>
      <a class="as-btn as-btn-primary as-plan-cta" href="#signup">Get Started</a>
      <span class="as-fine">AfriStream does not guarantee any stream availability or up-time. 14 Day Money Back Guarantee. Fee may vary with exchange rates.</span>
    </div>
  </div>
</section>';
}

/**
 * Testimonials.
 *
 * REPLACE BEFORE THESE GO ANYWHERE PUBLIC-FACING BEYOND THIS PAGE'S OWNER:
 * these three quotes came from the design handoff as placeholder copy, not
 * from real customers. They ship at the client's explicit instruction. Swap
 * this array for real quotes, or delete the section's call in
 * afristream_landing_body(), the moment real ones exist.
 */
const AFRISTREAM_LANDING_QUOTES = array(
	array(
		'text' => '“I cancelled four subscriptions the week after installing. Everything we watch is in one place now.”',
		'name' => 'Thandi M.',
		'meta' => 'Johannesburg',
	),
	array(
		'text' => '“Live EPL, F1 and all the movies my kids want. One bill a year and I stopped thinking about it.”',
		'name' => 'Pieter v.d. W.',
		'meta' => 'Cape Town',
	),
	array(
		'text' => '“The savings calculator said R6 000 a year. It was right.”',
		'name' => 'Naledi K.',
		'meta' => 'Durban',
	),
);

function afristream_landing_testimonials() {
	$figures = '';
	foreach ( AFRISTREAM_LANDING_QUOTES as $quote ) {
		$figures .= '
      <figure>
        <blockquote>' . esc_html( $quote['text'] ) . '</blockquote>
        <figcaption><span class="as-quote-name">' . esc_html( $quote['name'] ) . '</span><span class="as-quote-meta">' . esc_html( $quote['meta'] ) . '</span></figcaption>
      </figure>';
	}

	return '
<section id="testimonials" class="as-sec as-sec-testimonials" data-testid="landing-testimonials">
  <div class="as-sec-in" data-reveal>'
		. afristream_landing_section_header(
			'Testimonials',
			'Watched everywhere, paid once',
			'What viewers say after switching to one subscription.'
		) . '
    <div class="as-quotes">' . $figures . '</div>
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
	array( 'MUBI', 1668 ),
	array( 'Crunchyroll Mega Fan', 580 ),
	array( 'Viu Premium', 588 ),
	array( 'Hulu', 2160 ),
	array( 'HBO Max', 4968 ),
	array( 'Paramount Plus', 3024 ),
	array( 'Peacock', 3672 ),
	array( 'BritBox', 1980 ),
	array( 'ESPN Play', 2592 ),
);

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
<section id="calculate" class="as-sec as-sec-calc" data-testid="landing-calculator">
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
          <div><span>AfriStream</span><span>R' . (int) AFRISTREAM_LANDING_PRICE . ' / year</span></div>
        </div>
        <a class="as-btn as-btn-primary as-calc-cta" href="#pricing">Get AfriStream for R' . (int) AFRISTREAM_LANDING_PRICE . '!</a>
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
