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
		. afristream_landing_footer()
		. '</div>';
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
