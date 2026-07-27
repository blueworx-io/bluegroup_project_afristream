# Landing Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the public AfriStream marketing page into the plugin as a page template, so Elementor can be dropped from the site.

**Architecture:** A new `includes/landing.php` registers a WordPress page template that renders the entire document itself — no theme header or footer loads — plus `[afristream_landing]` as a shortcode fallback. All eight sections are server-rendered PHP; a single `assets/landing.js` handles the only four interactive parts (mobile menu, scroll reveal, savings calculator, FAQ accordion). Styling lives in `assets/landing.css`. No framework, no build step, matching how the portal already works.

**Tech Stack:** PHP 7.4+ (WordPress plugin API), vanilla ES2015+ JavaScript, plain CSS, Playwright for tests.

Source of truth: `docs/superpowers/specs/2026-07-27-landing-page-and-setup-wizard-design.md`, the **"PR 2 — Landing page"** section. The handoff it was written from is `AfriStream Landing.dc.html` in `Afristream hero redesign directions`.

## Global Constraints

- **No new dependencies.** Nothing is added to `approved-deps.json`. No build step, no bundler, no framework.
- **Escape everything.** All dynamic values go through `esc_html()`, `esc_attr()` or `esc_url()`. In JS, build text with `textContent` rather than `innerHTML` wherever a value could vary.
- **Copy is verbatim from this plan.** Headlines, section copy, feature blocks, pricing features, FAQ pairs and testimonials are quoted exactly. Do not paraphrase, re-punctuate or "improve" them.
- **Currency is Rand throughout:** AfriStream is `R1599` / year. Subscription prices are in Rand. Totals format with `en-ZA` grouping (`R7 716`, a non-breaking-space group separator).
- **Palette (the handoff's own dark token set — NOT the portal's light one):** page `#0b0510`, deep canvas `#0d0713`, card surface `#120a1a`, brand royal `#65009F`, accent `#cd2df5`, primary text `#fcfcfc`, secondary text `#c4bece`, tertiary text `#8b8595`, hairline `#2c2138`.
- **Fonts:** Geist (400/500/600) and Space Mono, from Google Fonts, registered as their own stylesheet handle.
- **Motion:** every animation is wrapped so that `@media (prefers-reduced-motion: reduce)` pins it to its resting state.
- **Accessibility:** real `<button>` elements for every toggle, `aria-expanded` on the burger and FAQ items, a labelled calculator input, alt text on the logo, exactly one `<h1>`, and visible focus rings that survive the dark background.
- **Mobile breakpoint is 860px** — the nav collapses to a burger below it, and the calculator becomes one column.
- **SureContact form ID:** `6e6876be-416c-4cd4-b109-3a603af6be79`, container `#surecontact-form-afristream-newsletter-sign-up`, script `https://app.surecontact.com/embed/forms.js`.
- **Lint once, at the end.** Never loop lint → autofix → lint. Findings are reported to the human, not actioned.
- **Branch:** `landing-page`, cut from `simplified-setup-wizard` (PR #17, still open). Do not merge to `main`.
- **Version:** `0.21.0` → `0.22.0` (minor). Three places must agree: the `AFRISTREAM_PORTAL_VERSION` constant and the `Version:` docblock line in `bluegroup-project-afristream.php`, and `"version"` in `package.json`.

---

## File Structure

| File | Responsibility | Change |
| --- | --- | --- |
| `includes/landing.php` | Template registration, shortcode, settings field, portal-page resolution, every section renderer | Create |
| `assets/landing.css` | All landing styling, including the animation keyframes and the reduced-motion block | Create |
| `assets/landing.js` | Mobile menu, scroll reveal, savings calculator, FAQ accordion | Create |
| `assets/afristream-icon.svg` | The logo mark used in the header and footer | Already added to the repo — do not re-create |
| `bluegroup-project-afristream.php` | `require_once` the new include; register the landing assets; version constant | Modify |
| `preview/landing.html` | Static mirror of the PHP output, for developing and testing without WordPress | Create |
| `scripts/preview-server.mjs` | Serve the mirror at `/landing` | Modify |
| `tests/landing.spec.js` | Playwright coverage for the landing page | Create |
| `CHANGELOG.md`, `package.json`, `README.md` | Release chores | Modify (Task 7) |

**`scripts/build-plugin.mjs` needs no change** — its `INCLUDE` list already copies the whole `assets` and `includes` folders, so new files there ship automatically. (The spec listed it as a file to touch; that was wrong.)

### Why `preview/landing.html` is a hand-written mirror, not generated

The portal's preview harness works the same way: a static HTML page that loads the exact assets the plugin enqueues. There is no PHP runtime locally, so the mirror is how the CSS and JS get exercised. It must be kept in step with the PHP by hand — Task 7 has an explicit check for drift.

## Existing patterns to follow

Read these before starting:

- `bluegroup-project-afristream.php:38-59` — `afristream_portal_register_assets()`, the register-then-enqueue pattern. Landing assets follow it exactly.
- `bluegroup-project-afristream.php:68-99` — `afristream_portal_shortcode()`, how a shortcode enqueues its handles and returns markup.
- `bluegroup-project-afristream.php:987-1033` — `afristream_portal_register_settings()`, how a setting is registered with a sanitiser and a field.
- `preview/index.html` — the shape of a harness page.
- `tests/portal.spec.js` — house test style: real rendered behaviour, `data-testid` hooks, a comment above any test whose point is not obvious from its name.

## How to run things

```bash
npm install               # once
npm run preview           # http://localhost:4173 — /landing serves the mirror
npx playwright test tests/landing.spec.js
npm test                  # full suite (PHP assertions + all Playwright), before committing
```

`playwright.config.js` starts the preview server itself, so `npm test` needs nothing running. If you start one by hand and see `EADDRINUSE`, a server is already up — use it rather than starting a second.

---

### Task 1: Page template, shortcode, assets and the portal-page setting

The plumbing, with a deliberately minimal page body. Later tasks fill the sections in. This task ends with a real page you can load.

**Files:**
- Create: `includes/landing.php`, `assets/landing.css`, `assets/landing.js`, `preview/landing.html`, `tests/landing.spec.js`
- Modify: `bluegroup-project-afristream.php`, `scripts/preview-server.mjs`

**Interfaces:**
- Produces:
  - `afristream_landing_register_assets()` — hooked to `wp_enqueue_scripts`, registers handles `afristream-landing-fonts`, `afristream-landing` (CSS), `afristream-landing` (JS) and `surecontact-forms`.
  - `afristream_landing_enqueue()` — enqueues the four handles and attaches the SureContact inline render call. Called by both the template and the shortcode.
  - `afristream_landing_portal_url()` — returns the portal page's permalink as a string, or `''` when none can be resolved.
  - `afristream_landing_body()` — returns the page's inner markup (header, sections, footer) as a string. Tasks 2–5 grow this.
  - `afristream_landing_template( $template )` — `template_include` filter callback.
  - `[afristream_landing]` shortcode → `afristream_landing_body()` with assets enqueued.
  - Option `afristream_portal_page_id` (integer, `absint`, default `0`).
  - Test IDs `landing-header`, `landing-footer`, `landing-nav`, `landing-burger`, `landing-menu`.

- [ ] **Step 1: Write the failing tests**

Create `tests/landing.spec.js`:

```js
import { test, expect } from '@playwright/test';

// The landing page is a plugin page template: it renders the whole document,
// so there is no theme header or footer around it. These run against the
// preview mirror at /landing, which loads the same CSS and JS the plugin
// enqueues.

test.beforeEach(async ({ page }) => {
  await page.goto('/landing');
});

test('the page renders one h1 and its own header and footer', async ({ page }) => {
  await expect(page.getByTestId('landing-header')).toBeVisible();
  await expect(page.getByTestId('landing-footer')).toBeVisible();
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('h1')).toContainText('Every stream. One app.');
});

test('the header links to the portal and to the pricing section', async ({ page }) => {
  const nav = page.getByTestId('landing-nav');
  await expect(nav.getByRole('link', { name: 'Features' })).toHaveAttribute('href', '#features');
  await expect(nav.getByRole('link', { name: 'FAQ' })).toHaveAttribute('href', '#faq');
  await expect(page.getByTestId('landing-header').getByRole('link', { name: 'Dashboard' }))
    .toHaveAttribute('href', '/portal/');
});

test('below 860px the nav collapses into a burger menu that opens and closes', async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 900 });
  await page.goto('/landing');

  const burger = page.getByTestId('landing-burger');
  await expect(burger).toBeVisible();
  await expect(burger).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByTestId('landing-menu')).toBeHidden();

  await burger.click();
  await expect(burger).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByTestId('landing-menu')).toBeVisible();

  // Choosing a destination closes it, rather than leaving it covering the page.
  await page.getByTestId('landing-menu').getByRole('link', { name: 'Pricing' }).click();
  await expect(page.getByTestId('landing-menu')).toBeHidden();
  await expect(burger).toHaveAttribute('aria-expanded', 'false');
});

test('the desktop nav is not rendered as a burger', async ({ page }) => {
  await expect(page.getByTestId('landing-nav')).toBeVisible();
  await expect(page.getByTestId('landing-burger')).toBeHidden();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test tests/landing.spec.js`
Expected: FAIL — `/landing` 404s, so every test errors on navigation.

- [ ] **Step 3: Create `includes/landing.php`**

```php
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
		plugins_url( 'assets/landing.css', dirname( __FILE__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-landing-fonts' ),
		AFRISTREAM_PORTAL_VERSION
	);
	wp_register_script(
		'afristream-landing',
		plugins_url( 'assets/landing.js', dirname( __FILE__ ) . '/bluegroup-project-afristream.php' ),
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
	return plugins_url( 'assets/' . $file, dirname( __FILE__ ) . '/bluegroup-project-afristream.php' );
}
```

- [ ] **Step 4: Require it from the main plugin file**

In `bluegroup-project-afristream.php`, alongside the other `require_once` lines near the top (they sit around line 30):

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/landing.php';
```

- [ ] **Step 5: Create `assets/landing.css`**

```css
/* AfriStream landing page. Dark, self-contained: the page template renders the
   whole document, so nothing here has to survive a theme's stylesheet. */

.as-landing {
  --page: #0b0510;
  --canvas: #0d0713;
  --card: #120a1a;
  --royal: #65009F;
  --accent: #cd2df5;
  --text: #fcfcfc;
  --text-2: #c4bece;
  --text-3: #8b8595;
  --hairline: #2c2138;
  --mono: 'Space Mono', ui-monospace, Menlo, monospace;

  background: var(--page);
  color: var(--text);
  font-family: 'Geist', system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
}

body.afristream-landing-page {
  margin: 0;
  background: #0b0510;
}

.as-landing a {
  color: var(--accent);
  text-decoration: none;
}

.as-landing a:hover {
  text-decoration: underline;
}

.as-landing :focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 3px;
  border-radius: 4px;
}

/* ---------------------------------------------------------------- header */

.as-landing .as-head {
  position: sticky;
  top: 0;
  z-index: 20;
  background: rgba(4, 2, 7, 0.85);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--hairline);
}

.as-landing .as-head-in {
  max-width: 1120px;
  margin: 0 auto;
  padding: 8px clamp(16px, 4vw, 40px);
  display: flex;
  align-items: center;
  gap: 12px 24px;
  min-height: 56px;
}

.as-landing .as-brand {
  display: flex;
  align-items: center;
  gap: 9px;
  font-weight: 600;
  font-size: 16px;
  letter-spacing: -0.3px;
  color: var(--text);
  flex: none;
}

.as-landing .as-brand:hover {
  text-decoration: none;
}

.as-landing .as-nav-full {
  display: flex;
  gap: 12px 20px;
  flex-wrap: wrap;
  justify-content: center;
  flex: 1;
}

.as-landing .as-nav-full a,
.as-landing .as-head-cta .as-dash {
  color: var(--text);
  font-size: 14px;
}

.as-landing .as-sale {
  font-family: var(--mono);
  font-size: 10px;
  letter-spacing: 0.6px;
  color: var(--accent);
  margin-left: 6px;
}

.as-landing .as-head-cta {
  display: flex;
  gap: 16px;
  align-items: center;
  flex: none;
}

.as-landing .as-burger {
  display: none;
  margin-left: auto;
  width: 44px;
  height: 44px;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--hairline);
  border-radius: 100px;
  background: rgba(205, 45, 245, 0.06);
  cursor: pointer;
  flex: none;
}

.as-landing .as-menu {
  border-top: 1px solid var(--hairline);
  background: rgba(4, 2, 7, 0.96);
  padding: 12px clamp(16px, 4vw, 40px) 20px;
  flex-direction: column;
  gap: 2px;
}

.as-landing .as-menu[hidden] {
  display: none;
}

.as-landing .as-menu a {
  color: var(--text);
  font-size: 16px;
  padding: 13px 0;
}

@media (max-width: 859px) {
  .as-landing .as-nav-full,
  .as-landing .as-head-cta {
    display: none;
  }
  .as-landing .as-burger {
    display: flex;
  }
  .as-landing .as-menu:not([hidden]) {
    display: flex;
  }
}

@media (min-width: 860px) {
  /* The panel is markup-present at every width so a resize cannot strand it
     open; above the breakpoint the full nav is the only navigation shown. */
  .as-landing .as-menu {
    display: none;
  }
}

/* ---------------------------------------------------------------- buttons */

.as-landing .as-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid transparent;
  border-radius: 100px;
  padding: 12px 20px;
  font-family: inherit;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
}

.as-landing .as-btn:hover {
  text-decoration: none;
}

.as-landing .as-btn-primary {
  background: var(--accent);
  color: #0d0713;
  font-weight: 600;
}

.as-landing .as-btn-ghost {
  background: transparent;
  border-color: var(--hairline);
  color: var(--text);
}

/* ---------------------------------------------------------------- footer */

.as-landing .as-foot {
  background: #060309;
  border-top: 1px solid var(--hairline);
}

.as-landing .as-foot-in {
  max-width: 1120px;
  margin: 0 auto;
  padding: 56px clamp(20px, 5vw, 40px) 40px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr));
  gap: 32px;
}

.as-landing .as-foot-brand,
.as-landing .as-foot-col {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.as-landing .as-foot a {
  color: var(--text-2);
  font-size: 14px;
}

.as-landing .as-muted {
  font-size: 14px;
  color: var(--text-3);
}

.as-landing .as-fine {
  font-size: 12px;
  color: var(--text-3);
  margin-top: 16px;
}

.as-landing .as-eyebrow {
  font-family: var(--mono);
  font-size: 12px;
  letter-spacing: 0.72px;
  text-transform: uppercase;
  color: var(--accent);
}

.as-landing .as-eyebrow-muted {
  color: var(--text-3);
}
```

- [ ] **Step 6: Create `assets/landing.js`**

```js
/* AfriStream landing page. Four jobs: the mobile menu, the scroll reveal, the
   savings calculator and the FAQ accordion. Everything else is static HTML
   rendered by PHP. No framework, no build step — the same approach as
   portal.js. */
(function () {
  'use strict';

  var root = document.querySelector('.as-landing');
  if (!root) return;

  // -------------------------------------------------------------- menu

  var burger = root.querySelector('[data-testid="landing-burger"]');
  var menu = root.querySelector('[data-testid="landing-menu"]');

  function setMenu(open) {
    if (!burger || !menu) return;
    menu.hidden = !open;
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    var path = burger.querySelector('path');
    if (path) path.setAttribute('d', open ? 'M6 6l12 12M18 6L6 18' : 'M4 7h16M4 12h16M4 17h16');
  }

  if (burger) {
    burger.addEventListener('click', function () {
      setMenu(menu.hidden);
    });
  }

  if (menu) {
    // Choosing a destination closes the panel rather than leaving it over the
    // section just jumped to.
    menu.addEventListener('click', function (e) {
      if (e.target.closest('a')) setMenu(false);
    });
  }
})();
```

- [ ] **Step 7: Create `preview/landing.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AfriStream — Local Preview</title>
<!-- Local stand-in for the plugin's page template. Mirrors what
     afristream_landing_body() prints, and loads the same assets the plugin
     enqueues. Keep the two in step by hand. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Space+Mono&display=swap" rel="stylesheet">
<link rel="icon" href="data:,">
<link rel="stylesheet" href="/assets/landing.css">
</head>
<body class="afristream-landing-page">
<div class="as-landing">

<header class="as-head" data-testid="landing-header">
  <div class="as-head-in">
    <a class="as-brand" href="#top"><img src="/assets/afristream-icon.svg" alt="AfriStream" width="26" height="27">AfriStream</a>
    <nav class="as-nav-full" data-testid="landing-nav">
      <a href="#features">Features</a>
      <a href="#integrations">Integrations</a>
      <a href="#calculate">Calculate</a>
      <a href="#pricing">Pricing<span class="as-sale">SALE</span></a>
      <a href="#faq">FAQ</a>
    </nav>
    <div class="as-head-cta">
      <a class="as-dash" href="/portal/">Dashboard</a>
      <a class="as-btn as-btn-primary" href="#signup">Get Started</a>
    </div>
    <button class="as-burger" type="button" data-testid="landing-burger" aria-expanded="false" aria-label="Menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cd2df5" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
    </button>
  </div>
  <div class="as-menu" data-testid="landing-menu" hidden>
    <a href="#features">Features</a>
    <a href="#integrations">Integrations</a>
    <a href="#calculate">Calculate</a>
    <a href="#pricing">Pricing<span class="as-sale">SALE</span></a>
    <a href="#faq">FAQ</a>
    <a href="/portal/">Dashboard</a>
    <a class="as-btn as-btn-primary" href="#signup">Get Started</a>
  </div>
</header>

<h1 style="max-width:1120px;margin:0 auto;padding:40px clamp(20px,5vw,40px)">Every stream. One app.</h1>

<footer class="as-foot" data-testid="landing-footer">
  <div class="as-foot-in">
    <div class="as-foot-brand">
      <span class="as-brand"><img src="/assets/afristream-icon.svg" alt="" width="24" height="25">AfriStream</span>
      <span class="as-muted">Save time, money and effort with AfriStream.</span>
      <span class="as-fine">© 2026 AfriStream</span>
    </div>
    <div class="as-foot-col">
      <span class="as-eyebrow as-eyebrow-muted">Company</span>
      <a href="#features">Features</a><a href="#integrations">Integrations</a><a href="#pricing">Pricing</a>
    </div>
    <div class="as-foot-col">
      <span class="as-eyebrow as-eyebrow-muted">Help</span>
      <a href="#faq">FAQ</a><a href="mailto:support@afristream.io">support@afristream.io</a><a href="/portal/">Dashboard</a>
    </div>
  </div>
</footer>

</div>
<script src="/assets/landing.js"></script>
</body>
</html>
```

The `<h1>` here is a stand-in so Task 1's tests have something to assert; Task 2 replaces it with the real hero.

- [ ] **Step 8: Serve the mirror at `/landing`**

In `scripts/preview-server.mjs`, immediately before the `if (path === '/' || path === '/index.html')` block:

```js
    if (path === '/landing' || path === '/landing/') path = '/preview/landing.html';
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `npx playwright test tests/landing.spec.js`
Expected: all four PASS.

- [ ] **Step 10: Commit**

```bash
git add includes/landing.php assets/landing.css assets/landing.js assets/afristream-icon.svg preview/landing.html scripts/preview-server.mjs tests/landing.spec.js bluegroup-project-afristream.php
git commit -m "$(cat <<'EOF'
Serve the landing page from the plugin, not Elementor

A page template rather than a shortcode: the design brings its own sticky
header and footer, so letting a theme wrap it would put two headers on one
page. The template prints the document and the theme's header.php never runs.

[afristream_landing] stays for anyone who wants the sections inside an
existing page.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Hero and integrations ticker

**Files:**
- Modify: `includes/landing.php`, `assets/landing.css`, `preview/landing.html`
- Test: `tests/landing.spec.js`

**Interfaces:**
- Consumes: `afristream_landing_body()`, the CSS custom properties on `.as-landing`.
- Produces: `afristream_landing_hero()`, `afristream_landing_integrations()`; test IDs `landing-hero`, `landing-ticker`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/landing.spec.js`:

```js
test('the hero leads with the headline, two CTAs and three proof points', async ({ page }) => {
  const hero = page.getByTestId('landing-hero');
  await expect(hero.getByText('20 000+ feeds, live now')).toBeVisible();
  await expect(hero.locator('h1')).toContainText('Every stream. One app.');
  await expect(hero).toContainText('Save thousands with AfriStream');

  await expect(hero.getByRole('link', { name: 'Calculate Savings' })).toHaveAttribute('href', '#calculate');
  await expect(hero.getByRole('link', { name: 'View Pricing' })).toHaveAttribute('href', '#pricing');
  await expect(hero.locator('[data-proof]')).toHaveCount(3);
});

test('the integrations ticker lists the platforms twice, for a seamless loop', async ({ page }) => {
  const ticker = page.getByTestId('landing-ticker');
  await expect(ticker).toContainText('Netflix');
  await expect(ticker).toContainText('SuperSport');
  // Thirteen platforms, doubled: the second copy is what lets the marquee
  // restart without a visible jump.
  await expect(ticker.locator('[data-platform]')).toHaveCount(26);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test tests/landing.spec.js -g "hero leads"`
Expected: FAIL — no `landing-hero` element.

- [ ] **Step 3: Add the two section renderers**

In `includes/landing.php`, add before `afristream_landing_footer()`:

```php
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
```

Then change `afristream_landing_body()` to:

```php
function afristream_landing_body() {
	return '<div class="as-landing">'
		. afristream_landing_header()
		. afristream_landing_hero()
		. afristream_landing_integrations()
		. afristream_landing_footer()
		. '</div>';
}
```

- [ ] **Step 4: Add the hero and ticker CSS**

Append to `assets/landing.css`:

```css
/* ------------------------------------------------------------------ hero */

.as-landing .as-hero {
  position: relative;
  overflow: hidden;
  background: linear-gradient(195deg, #14041f 0%, #0b0510 55%, #0b0510 100%);
}

.as-landing .as-hero-in {
  position: relative;
  z-index: 1;
  max-width: 1120px;
  margin: 0 auto;
  padding: clamp(48px, 9vw, 80px) clamp(20px, 5vw, 40px) 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 24px;
}

.as-landing .as-eyebrow-dot::before {
  content: '';
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 12px var(--accent);
  margin-right: 10px;
  vertical-align: middle;
}

.as-landing h1 {
  margin: 0;
  font-weight: 400;
  font-size: clamp(44px, 7.5vw, 80px);
  line-height: 1.05;
  letter-spacing: -0.045em;
  max-width: 900px;
}

.as-landing .as-lede {
  margin: 0;
  font-size: clamp(17px, 2.2vw, 20px);
  line-height: 1.5;
  color: var(--text-2);
  max-width: 560px;
}

.as-landing .as-hero-cta {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  justify-content: center;
}

.as-landing .as-proofs {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: center;
  margin-top: 20px;
}

.as-landing .as-proof {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--hairline);
  border-radius: 100px;
  background: rgba(4, 2, 7, 0.85);
  padding: 9px 16px;
  font-size: 12px;
  color: var(--text-2);
}

.as-landing .as-proof::before {
  content: '';
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--accent);
  flex: none;
}

/* The constellation: six platform tiles orbiting a hub, with the wordmark
   rising underneath. Decorative — aria-hidden in the markup. */

.as-landing .as-stage {
  position: relative;
  width: 100%;
  max-width: 960px;
  aspect-ratio: 16 / 9;
  margin-top: 24px;
  border: 1px solid var(--hairline);
  border-bottom: none;
  border-radius: 14px 14px 0 0;
  background: var(--canvas);
  box-shadow: inset 0 0 0 1px var(--royal), 0 0 60px rgba(101, 0, 159, 0.25);
  overflow: hidden;
}

.as-landing .as-stage-glow {
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 50% 120%, #3d0a5e 0%, rgba(13, 7, 19, 0) 70%);
}

.as-landing .as-tile {
  position: absolute;
  width: 9.2%;
  aspect-ratio: 1;
  margin: -4.6% 0 0 -4.6%;
  border-radius: 21%;
  background: var(--card);
  box-shadow: inset 0 0 0 1px rgba(101, 0, 159, 0.9), 0 0 34px rgba(101, 0, 159, 0.22);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 4%;
  box-sizing: border-box;
  text-align: center;
  font-family: var(--mono);
  font-size: clamp(7px, 1vw, 13px);
  line-height: 1.25;
  color: var(--text-2);
  animation: as-tile 9s ease-in-out infinite;
}

.as-landing .as-tile-1 { left: 27.08%; top: 32.5%; animation-delay: -4.50s; }
.as-landing .as-tile-2 { left: 18.75%; top: 52.41%; animation-delay: -4.41s; }
.as-landing .as-tile-3 { left: 27.08%; top: 72.31%; animation-delay: -4.32s; }
.as-landing .as-tile-4 { left: 72.92%; top: 32.5%; animation-delay: -4.23s; }
.as-landing .as-tile-5 { left: 81.25%; top: 52.41%; animation-delay: -4.14s; }
.as-landing .as-tile-6 { left: 72.92%; top: 72.31%; animation-delay: -4.05s; }

.as-landing .as-hub {
  position: absolute;
  left: 50%;
  top: 52.41%;
  width: 9.17%;
  aspect-ratio: 1;
  margin: -4.58% 0 0 -4.58%;
  border-radius: 22%;
  background: var(--card);
  box-shadow: inset 0 0 0 1.5px var(--accent), 0 0 70px rgba(205, 45, 245, 0.35);
  animation: as-hub 9s ease-in-out infinite;
  animation-delay: -4.5s;
}

.as-landing .as-hub::after {
  content: '';
  position: absolute;
  left: 34%;
  top: 24%;
  border-style: solid;
  border-width: 13% 0 13% 22%;
  border-color: transparent transparent transparent var(--accent);
}

.as-landing .as-wordmark {
  position: absolute;
  left: 0;
  right: 0;
  top: 68%;
  text-align: center;
  font-weight: 600;
  font-size: clamp(24px, 4.6vw, 88px);
  line-height: 1;
  letter-spacing: -0.036em;
  color: var(--text);
  animation: as-word 9s ease-in-out infinite;
  animation-delay: -4.5s;
}

.as-landing .as-wordmark small {
  display: block;
  margin-top: 2%;
  font-size: clamp(11px, 1.6vw, 30px);
  font-weight: 400;
  line-height: 1.3;
  letter-spacing: -0.4px;
  color: var(--text-2);
}

@keyframes as-tile {
  0% { opacity: 0; transform: translateY(26%) scale(0.72); }
  9% { opacity: 1; transform: translateY(0) scale(1); }
  64% { opacity: 1; transform: translateY(0) scale(1); }
  74% { opacity: 0.16; transform: translateY(0) scale(0.84); }
  96% { opacity: 0.16; transform: translateY(0) scale(0.84); }
  100% { opacity: 0; transform: translateY(26%) scale(0.72); }
}

@keyframes as-hub {
  0%, 17% { opacity: 0; transform: translateY(0) scale(0.4); }
  27% { opacity: 1; transform: translateY(0) scale(1); }
  66% { opacity: 1; transform: translateY(0) scale(1); }
  80% { opacity: 1; transform: translateY(-34%) scale(2.05); }
  96% { opacity: 1; transform: translateY(-34%) scale(2.05); }
  100% { opacity: 0; transform: translateY(0) scale(0.4); }
}

@keyframes as-word {
  0%, 68% { opacity: 0; transform: translateY(22px); }
  82% { opacity: 1; transform: translateY(0); }
  96% { opacity: 1; transform: translateY(0); }
  100% { opacity: 0; transform: translateY(22px); }
}

/* ---------------------------------------------------------- integrations */

.as-landing .as-integrations {
  display: flex;
  align-items: center;
  border-top: 1px solid var(--hairline);
  border-bottom: 1px solid var(--hairline);
  background: rgba(4, 2, 7, 0.4);
}

.as-landing .as-integrations-label {
  flex: none;
  padding: 16px clamp(14px, 4vw, 24px) 16px clamp(20px, 5vw, 40px);
  border-right: 1px solid var(--hairline);
}

.as-landing .as-marquee {
  flex: 1;
  overflow: hidden;
  padding: 16px 0;
  mask-image: linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent);
  -webkit-mask-image: linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent);
}

.as-landing .as-marquee-run {
  display: flex;
  gap: 48px;
  width: max-content;
  animation: as-marquee 32s linear infinite;
}

.as-landing .as-marquee-run span {
  font-family: var(--mono);
  font-size: 12px;
  letter-spacing: 0.72px;
  text-transform: uppercase;
  color: var(--text-3);
  white-space: nowrap;
}

@keyframes as-marquee {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

/* Everything that moves, stopped at rest. */
@media (prefers-reduced-motion: reduce) {
  .as-landing .as-marquee-run,
  .as-landing .as-tile,
  .as-landing .as-hub,
  .as-landing .as-wordmark {
    animation: none;
  }
  .as-landing .as-tile,
  .as-landing .as-hub,
  .as-landing .as-wordmark {
    opacity: 1;
    transform: none;
  }
}
```

- [ ] **Step 5: Mirror both sections in `preview/landing.html`**

Replace the stand-in `<h1>` line with the hero and integrations markup exactly as the PHP produces it — six tiles numbered `as-tile-1` through `as-tile-6` carrying Netflix, Showmax, SuperSport, Prime Video, Disney+, Apple TV+; three `data-proof` pills; and 26 `data-platform` spans (the thirteen platforms, listed twice, in the order given in `AFRISTREAM_LANDING_PLATFORMS`).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx playwright test tests/landing.spec.js`
Expected: all six PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/landing.php assets/landing.css preview/landing.html tests/landing.spec.js
git commit -m "$(cat <<'EOF'
Open the landing page with the hero and the platform ticker

The handoff's constellation is drawn from a six-item tile list in CSS rather
than hand-written per tile, and every animation stops at its resting frame
under prefers-reduced-motion.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Features, pricing and testimonials

Three static sections, grouped because none carries behaviour and each is small.

**Files:**
- Modify: `includes/landing.php`, `assets/landing.css`, `preview/landing.html`
- Test: `tests/landing.spec.js`

**Interfaces:**
- Produces: `afristream_landing_features()`, `afristream_landing_pricing()`, `afristream_landing_testimonials()`, and the shared `afristream_landing_section_header( $eyebrow, $title, $sub )`; test IDs `landing-features`, `landing-pricing`, `landing-testimonials`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/landing.spec.js`:

```js
test('the features section lists four blocks', async ({ page }) => {
  const features = page.getByTestId('landing-features');
  await expect(features.locator('[data-feature]')).toHaveCount(4);
  await expect(features).toContainText('Multiple platforms in one');
  await expect(features).toContainText('Save time, money, effort');
});

test('pricing shows one annual plan at R1599 with its five features', async ({ page }) => {
  const pricing = page.getByTestId('landing-pricing');
  await expect(pricing).toContainText('Limited Time Offer!');
  await expect(pricing).toContainText('R1599');
  await expect(pricing).toContainText('/ year');
  await expect(pricing.locator('[data-plan-feature]')).toHaveCount(5);
  await expect(pricing.getByRole('link', { name: 'Get Started' })).toHaveAttribute('href', '#signup');
  await expect(pricing).toContainText('14 Day Money Back Guarantee');
});

test('three testimonials render with their attributions', async ({ page }) => {
  const quotes = page.getByTestId('landing-testimonials').locator('figure');
  await expect(quotes).toHaveCount(3);
  await expect(quotes.first()).toContainText('cancelled four subscriptions');
  await expect(quotes.first().locator('figcaption')).toContainText('Thandi M.');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test tests/landing.spec.js -g "features section"`
Expected: FAIL — no `landing-features` element.

- [ ] **Step 3: Add the shared section header and the three sections**

In `includes/landing.php`, before `afristream_landing_footer()`:

```php
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
```

Update `afristream_landing_body()` to call, in order: header, hero, integrations, features, pricing, testimonials, footer.

- [ ] **Step 4: Add the CSS**

Append to `assets/landing.css`:

```css
/* -------------------------------------------------------------- sections */

.as-landing .as-sec-in {
  max-width: 1120px;
  margin: 0 auto;
  padding: clamp(48px, 9vw, 64px) clamp(20px, 5vw, 40px);
  display: flex;
  flex-direction: column;
  gap: 48px;
}

.as-landing .as-sec-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 14px;
}

.as-landing .as-sec-head h2 {
  margin: 0;
  font-weight: 400;
  font-size: clamp(30px, 4.4vw, 44px);
  line-height: 1.1;
  letter-spacing: -0.035em;
  max-width: 760px;
}

.as-landing .as-sec-head p {
  margin: 0;
  font-size: clamp(15px, 1.8vw, 17px);
  line-height: 1.6;
  color: var(--text-3);
  max-width: 640px;
}

.as-landing .as-sec-features { background: radial-gradient(ellipse 120% 90% at 8% 0%, rgba(101, 0, 159, 0.3) 0%, rgba(13, 7, 19, 0) 68%); }
.as-landing .as-sec-pricing { background: radial-gradient(ellipse 120% 90% at 12% 60%, rgba(101, 0, 159, 0.28) 0%, rgba(13, 7, 19, 0) 68%); }
.as-landing .as-sec-testimonials { background: radial-gradient(ellipse 120% 90% at 88% 10%, rgba(101, 0, 159, 0.22) 0%, rgba(13, 7, 19, 0) 68%); }

/* -------------------------------------------------------------- features */

.as-landing .as-features {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
  gap: 32px 24px;
}

.as-landing .as-feature {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.as-landing .as-feature h3 {
  margin: 0;
  font-size: 18px;
  font-weight: 500;
  letter-spacing: -0.01em;
}

.as-landing .as-feature p {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.6;
  color: var(--text-3);
}

/* --------------------------------------------------------------- pricing */

.as-landing .as-sec-pricing .as-sec-in { align-items: center; }

.as-landing .as-plan {
  width: min(420px, 100%);
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 14px;
  border-radius: 24px;
  padding: clamp(24px, 4vw, 34px);
  background: var(--card);
  box-shadow: inset 0 0 0 1px var(--royal), 0 0 60px rgba(101, 0, 159, 0.25);
}

.as-landing .as-plan-badge {
  align-self: flex-start;
  font-family: var(--mono);
  font-size: 11px;
  letter-spacing: 0.6px;
  text-transform: uppercase;
  color: var(--accent);
  border: 1px solid rgba(205, 45, 245, 0.35);
  border-radius: 100px;
  padding: 5px 12px;
}

.as-landing .as-plan-name { font-size: 15px; color: var(--text-2); }

.as-landing .as-plan-price {
  font-size: clamp(40px, 6vw, 56px);
  line-height: 1;
  letter-spacing: -0.04em;
}

.as-landing .as-plan-price small {
  font-size: 15px;
  letter-spacing: 0;
  color: var(--text-3);
  margin-left: 8px;
}

.as-landing .as-plan-tag { font-size: 14.5px; color: var(--text-2); }

.as-landing .as-plan-features {
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 10px;
  border-top: 1px solid var(--hairline);
  padding-top: 18px;
}

.as-landing .as-plan-features li {
  display: flex;
  gap: 10px;
  font-size: 14.5px;
  line-height: 1.5;
  color: var(--text-2);
}

.as-landing .as-plan-features li::before {
  content: '✓';
  color: var(--accent);
  flex: none;
}

.as-landing .as-plan-cta { width: 100%; margin-top: 6px; }

.as-landing .as-plan .as-fine { margin-top: 0; line-height: 1.5; }

/* ---------------------------------------------------------- testimonials */

.as-landing .as-quotes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr));
  gap: 48px 32px;
}

.as-landing .as-quotes figure {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding-top: 24px;
  border-top: 1px solid var(--hairline);
}

.as-landing .as-quotes blockquote {
  margin: 0;
  font-size: 18px;
  line-height: 1.5;
}

.as-landing .as-quotes figcaption {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.as-landing .as-quote-name { font-size: 14px; font-weight: 500; }
.as-landing .as-quote-meta { font-size: 12px; color: var(--text-3); }
```

- [ ] **Step 5: Mirror the three sections in `preview/landing.html`**

Insert them between the integrations section and the footer, matching the PHP output exactly: four `data-feature` blocks, five `data-plan-feature` list items, three `<figure>` quotes.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx playwright test tests/landing.spec.js`
Expected: all nine PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/landing.php assets/landing.css preview/landing.html tests/landing.spec.js
git commit -m "$(cat <<'EOF'
Add the features, pricing and testimonial sections

The three quotes are the handoff's placeholder copy, shipping at the client's
explicit instruction. They sit in one constant with a comment saying so, so
replacing them or dropping the section is a one-line change.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: The savings calculator

The one genuinely interactive section.

**Files:**
- Modify: `includes/landing.php`, `assets/landing.css`, `assets/landing.js`, `preview/landing.html`
- Test: `tests/landing.spec.js`

**Interfaces:**
- Consumes: `afristream_landing_section_header()`.
- Produces: `afristream_landing_calculator()`; constant `AFRISTREAM_LANDING_SUBS`; test IDs `landing-calculator`, `calc-saving`, `calc-total`, `calc-basis`, `calc-other`; each subscription toggle is a `<button data-sub-price="NNNN" aria-pressed="…">`.

**The arithmetic, stated once:** saving = max(0, sum of selected subscription prices + the "other" figure − 1599). Both `saving` and `total` render as `R` followed by the number grouped `en-ZA` — which uses a non-breaking space as the thousands separator, so `7716` renders `R7 716` with U+00A0, not a plain space. Tests must match on the digits rather than the separator.

- [ ] **Step 1: Write the failing tests**

Append to `tests/landing.spec.js`:

```js
// Prices are annualised Rand. The maths under test: saving is what is left
// after AfriStream's own R1599, and never negative.
const pick = (page, name) => page.getByTestId('landing-calculator').getByRole('button', { name });

// en-ZA groups thousands with a non-breaking space, so assert on digits.
const digits = async (locator) => (await locator.innerText()).replace(/[^\d]/g, '');

test('the calculator starts at zero and prompts for a selection', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await expect(calc.getByTestId('calc-saving')).toHaveText(/R\s*0$/);
  await expect(calc.getByTestId('calc-basis')).toContainText('your selection below');
  await expect(calc).toContainText('R1599 / year');
});

test('selecting subscriptions adds up and subtracts the AfriStream price', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');

  await pick(page, /Netflix Premium/).click();
  await pick(page, /HBO Max/).click();

  // 2748 + 4968 = 7716, less AfriStream's 1599 = 6117.
  expect(await digits(calc.getByTestId('calc-total'))).toBe('7716');
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('6117');
  await expect(calc.getByTestId('calc-basis')).toContainText('2 subscriptions selected');

  // A chosen subscription reads as pressed, for assistive tech.
  await expect(pick(page, /Netflix Premium/)).toHaveAttribute('aria-pressed', 'true');
});

test('the other-subscriptions figure joins the total', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await pick(page, /Netflix Premium/).click();
  await pick(page, /HBO Max/).click();
  await calc.getByTestId('calc-other').fill('1000');

  expect(await digits(calc.getByTestId('calc-saving'))).toBe('7117');
});

test('deselecting returns the saving to zero', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await pick(page, /Netflix Premium/).click();
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('1149');

  await pick(page, /Netflix Premium/).click();
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('0');
  await expect(pick(page, /Netflix Premium/)).toHaveAttribute('aria-pressed', 'false');
});

test('a selection worth less than AfriStream shows no saving, not a negative one', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  // Amazon Prime at R399 is well under AfriStream's R1599.
  await pick(page, /Amazon Prime/).click();
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('0');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test tests/landing.spec.js -g "calculator starts"`
Expected: FAIL — no `landing-calculator` element.

- [ ] **Step 3: Add the section renderer**

In `includes/landing.php`, before `afristream_landing_footer()`:

```php
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
          <span class="as-sub-text"><span class="as-sub-name">' . esc_html( $sub[0] ) . '</span><span class="as-sub-price">R' . (int) $sub[1] . '/yr</span></span>
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
```

Add `afristream_landing_calculator()` to `afristream_landing_body()`, between integrations and features — the section order is hero, integrations, features, calculate, pricing, testimonials.

- [ ] **Step 4: Add the calculator behaviour to `assets/landing.js`**

Inside the existing IIFE, after the menu block:

```js
  // -------------------------------------------------------- calculator

  var calc = root.querySelector('[data-testid="landing-calculator"]');

  if (calc) {
    var AFRISTREAM_PRICE = 1599;
    var subs = Array.prototype.slice.call(calc.querySelectorAll('[data-sub-price]'));
    var other = calc.querySelector('[data-testid="calc-other"]');
    var savingEl = calc.querySelector('[data-testid="calc-saving"]');
    var totalEl = calc.querySelector('[data-testid="calc-total"]');
    var basisEl = calc.querySelector('[data-testid="calc-basis"]');

    // en-ZA groups thousands the way the rest of the page's prices read.
    function money(n) {
      return 'R' + n.toLocaleString('en-ZA');
    }

    function recalc() {
      var total = 0;
      var chosen = 0;
      subs.forEach(function (btn) {
        if (btn.getAttribute('aria-pressed') === 'true') {
          total += Number(btn.getAttribute('data-sub-price')) || 0;
          chosen += 1;
        }
      });
      total += Number(other && other.value) || 0;

      savingEl.textContent = money(Math.max(0, total - AFRISTREAM_PRICE));
      totalEl.textContent = money(total) + ' / year';
      basisEl.textContent = chosen === 0
        ? 'your selection below'
        : chosen + ' subscription' + (chosen === 1 ? '' : 's') + ' selected';
    }

    subs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.setAttribute('aria-pressed', btn.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        recalc();
      });
    });

    if (other) other.addEventListener('input', recalc);

    recalc();
  }
```

- [ ] **Step 5: Add the calculator CSS**

Append to `assets/landing.css`:

```css
/* ------------------------------------------------------------ calculator */

.as-landing .as-sec-calc { background: radial-gradient(ellipse 120% 90% at 92% 40%, rgba(101, 0, 159, 0.26) 0%, rgba(13, 7, 19, 0) 68%); }

.as-landing .as-calc {
  display: grid;
  grid-template-columns: minmax(320px, 400px) minmax(320px, 1fr);
  gap: clamp(24px, 4vw, 40px);
  align-items: start;
}

@media (max-width: 859px) {
  .as-landing .as-calc { grid-template-columns: 1fr; }
  .as-landing .as-calc-card { position: static; }
}

.as-landing .as-calc-card {
  position: sticky;
  top: 96px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 14px;
  border-radius: 24px;
  padding: clamp(20px, 4vw, 32px);
  background: var(--card);
  box-shadow: inset 0 0 0 1px var(--royal), 0 0 60px rgba(101, 0, 159, 0.25);
}

.as-landing .as-calc-saving {
  font-size: clamp(40px, 6vw, 64px);
  line-height: 1;
  letter-spacing: -0.04em;
}

.as-landing .as-calc-basis { font-size: 13px; color: var(--text-3); }

.as-landing .as-calc-rows {
  display: flex;
  flex-direction: column;
  gap: 10px;
  border-top: 1px solid var(--hairline);
  padding-top: 16px;
  font-size: 14px;
}

.as-landing .as-calc-rows div { display: flex; justify-content: space-between; gap: 12px; }
.as-landing .as-calc-rows span:first-child { color: var(--text-3); }
.as-landing .as-calc-rows span:last-child { color: var(--text-2); white-space: nowrap; }

.as-landing .as-calc-cta { width: 100%; }

.as-landing .as-calc-pick { display: flex; flex-direction: column; gap: 12px; }

.as-landing .as-calc-q { font-size: 14px; font-weight: 500; }

.as-landing .as-subs {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(180px, 100%), 1fr));
  gap: 8px;
}

.as-landing .as-sub {
  display: flex;
  align-items: center;
  gap: 10px;
  text-align: left;
  padding: 10px 14px;
  border-radius: 10px;
  border: 1px solid var(--hairline);
  background: transparent;
  color: var(--text-2);
  font-family: inherit;
  font-size: 14px;
  cursor: pointer;
}

.as-landing .as-sub[aria-pressed='true'] {
  border-color: var(--accent);
  background: rgba(205, 45, 245, 0.06);
  color: var(--text);
}

.as-landing .as-sub-box {
  width: 18px;
  height: 18px;
  flex: none;
  border-radius: 5px;
  box-sizing: border-box;
  border: 1.5px solid var(--text-3);
  display: flex;
  align-items: center;
  justify-content: center;
}

.as-landing .as-sub[aria-pressed='true'] .as-sub-box {
  border-color: var(--accent);
  background: var(--accent);
}

.as-landing .as-sub[aria-pressed='true'] .as-sub-box::after {
  content: '✓';
  font-size: 12px;
  font-weight: 600;
  color: #0d0713;
}

.as-landing .as-sub-text { display: flex; flex-direction: column; min-width: 0; }
.as-landing .as-sub-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.as-landing .as-sub-price { font-size: 11px; color: var(--text-3); }

.as-landing .as-calc-other { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
.as-landing .as-calc-other label { font-size: 14px; font-weight: 500; }

.as-landing .as-calc-input {
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  padding: 0 14px;
  background: rgba(4, 2, 7, 0.6);
  color: var(--text-3);
}

.as-landing .as-calc-input input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  color: var(--text);
  font-family: inherit;
  font-size: 15px;
  padding: 13px 0;
}

.as-landing .as-calc-input input:focus { outline: none; }
.as-landing .as-calc-input:focus-within { border-color: var(--accent); }
```

- [ ] **Step 6: Mirror the calculator in `preview/landing.html`**

Between the integrations section and the features section, matching the PHP output: sixteen `.as-sub` buttons carrying the prices from `AFRISTREAM_LANDING_SUBS` in order, and the result card with its three test IDs.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npx playwright test tests/landing.spec.js`
Expected: all fourteen PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/landing.php assets/landing.css assets/landing.js preview/landing.html tests/landing.spec.js
git commit -m "$(cat <<'EOF'
Add the savings calculator

Sixteen annualised subscription prices against AfriStream's R1599. The
saving is clamped at zero rather than going negative when someone picks less
than they would pay us.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: FAQ accordion and the SureContact signup

**Files:**
- Modify: `includes/landing.php`, `assets/landing.css`, `assets/landing.js`, `preview/landing.html`
- Test: `tests/landing.spec.js`

**Interfaces:**
- Produces: `afristream_landing_faq()`, `afristream_landing_signup()`; test IDs `landing-faq`, `landing-signup`, `surecontact-container`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/landing.spec.js`:

```js
test('the FAQ opens with the first answer showing and toggles the rest', async ({ page }) => {
  const faq = page.getByTestId('landing-faq');
  const items = faq.locator('[data-faq]');
  await expect(items).toHaveCount(7);

  const first = items.first();
  await expect(first.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  await expect(first.locator('[data-faq-answer]')).toBeVisible();

  const third = items.nth(2);
  await expect(third.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  await expect(third.locator('[data-faq-answer]')).toBeHidden();

  await third.getByRole('button').click();
  await expect(third.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  await expect(third.locator('[data-faq-answer]')).toBeVisible();
  await expect(third).toContainText('BT, SKY, BBC');

  // Opening one does not close another — these are independent, not a
  // single-open accordion.
  await expect(first.getByRole('button')).toHaveAttribute('aria-expanded', 'true');

  await third.getByRole('button').click();
  await expect(third.locator('[data-faq-answer]')).toBeHidden();
});

test('the signup section hosts the newsletter embed', async ({ page }) => {
  const signup = page.getByTestId('landing-signup');
  await expect(signup).toContainText('Unlock the power of AfriStream today');
  await expect(signup.locator('#surecontact-form-afristream-newsletter-sign-up')).toHaveCount(1);
  await expect(signup).toContainText('14 Day Money Back Guarantee');
});

test('every call to action on the page resolves to a section that exists', async ({ page }) => {
  const hrefs = await page.locator('.as-landing a[href^="#"]').evaluateAll(
    (links) => links.map((a) => a.getAttribute('href'))
  );
  expect(hrefs.length).toBeGreaterThan(0);
  for (const href of [...new Set(hrefs)]) {
    await expect(page.locator(href), `${href} has no target`).toHaveCount(1);
  }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test tests/landing.spec.js -g "FAQ opens"`
Expected: FAIL — no `landing-faq` element.

- [ ] **Step 3: Add the two section renderers**

In `includes/landing.php`, before `afristream_landing_footer()`:

```php
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
 * Signup. The form itself is SureContact's embed — the container is ours, the
 * markup inside it is theirs, so the CSS reaches only as far as the wrapper.
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
      <div id="surecontact-form-afristream-newsletter-sign-up" data-testid="surecontact-container"></div>
      <span class="as-fine">14 Day Money Back Guarantee. No spam, ever.</span>
    </div>
  </div>
</section>';
}
```

Final section order in `afristream_landing_body()`: header, hero, integrations, features, calculate, pricing, testimonials, faq, signup, footer.

- [ ] **Step 4: Add the FAQ behaviour to `assets/landing.js`**

Inside the IIFE, after the calculator block:

```js
  // --------------------------------------------------------------- faq

  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-faq] button');
    if (!btn || !root.contains(btn)) return;
    var answer = btn.parentNode.querySelector('[data-faq-answer]');
    if (!answer) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    answer.hidden = open;
  });
```

- [ ] **Step 5: Add the FAQ and signup CSS**

Append to `assets/landing.css`:

```css
/* ------------------------------------------------------------------- faq */

.as-landing .as-sec-faq { background: radial-gradient(ellipse 120% 90% at 50% 110%, rgba(101, 0, 159, 0.26) 0%, rgba(13, 7, 19, 0) 68%); }

.as-landing .as-faq { display: flex; flex-direction: column; }

.as-landing .as-faq-item { border-top: 1px solid var(--hairline); }
.as-landing .as-faq-item:last-child { border-bottom: 1px solid var(--hairline); }

.as-landing .as-faq-item button {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  text-align: left;
  background: transparent;
  border: none;
  padding: 22px 0;
  font-family: inherit;
  font-size: clamp(16px, 2vw, 18px);
  color: var(--text);
  cursor: pointer;
}

.as-landing .as-faq-mark {
  flex: none;
  width: 14px;
  height: 14px;
  position: relative;
}

.as-landing .as-faq-mark::before,
.as-landing .as-faq-mark::after {
  content: '';
  position: absolute;
  left: 0;
  top: 6px;
  width: 14px;
  height: 2px;
  border-radius: 2px;
  background: var(--accent);
}

.as-landing .as-faq-mark::after { transform: rotate(90deg); }

.as-landing .as-faq-item button[aria-expanded='true'] .as-faq-mark::after { transform: rotate(0deg); }

.as-landing .as-faq-answer {
  padding: 0 0 22px;
  max-width: 780px;
  font-size: 15.5px;
  line-height: 1.65;
  color: var(--text-2);
}

.as-landing .as-faq-answer[hidden] { display: none; }

/* ---------------------------------------------------------------- signup */

.as-landing .as-sec-signup { background: radial-gradient(ellipse 120% 90% at 50% 120%, rgba(101, 0, 159, 0.42) 0%, rgba(13, 7, 19, 0) 68%); }

.as-landing .as-sec-signup .as-sec-in { align-items: center; gap: 28px; }

.as-landing .as-signup {
  width: min(520px, 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
}

/* The embed owns its own markup; this reaches the wrapper and the plain
   controls inside it, and stops there. */
.as-landing .as-signup > div { width: 100%; }

.as-landing .as-signup input[type='email'],
.as-landing .as-signup input[type='text'] {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--hairline);
  border-radius: 100px;
  background: rgba(4, 2, 7, 0.6);
  color: var(--text);
  font-family: inherit;
  font-size: 15px;
  padding: 13px 20px;
}

.as-landing .as-signup button {
  border: none;
  border-radius: 100px;
  background: var(--accent);
  color: #0d0713;
  font-family: inherit;
  font-size: 15px;
  font-weight: 600;
  padding: 13px 26px;
  cursor: pointer;
}
```

- [ ] **Step 6: Mirror both sections in `preview/landing.html`**

Seven `data-faq` items with the questions and answers above, the first with `aria-expanded="true"` and its answer not `hidden`; then the signup section with the SureContact container div. **Do not** load `forms.js` in the mirror — the tests assert the container exists, not that a third-party script ran, and a network call would make the suite flaky offline. Add an HTML comment in the mirror saying so.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npx playwright test tests/landing.spec.js`
Expected: all seventeen PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/landing.php assets/landing.css assets/landing.js preview/landing.html tests/landing.spec.js
git commit -m "$(cat <<'EOF'
Add the FAQ and the newsletter signup

FAQ items open independently rather than closing each other — a single-open
accordion shuts an answer someone is still reading.

The signup section hosts SureContact's embed. The inline render call is
attached to the vendor script's handle, so it cannot run before the library
it calls into.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Scroll reveal

Last behaviour, separated because it touches every section and is the one piece that must not hide content when it goes wrong.

**Files:**
- Modify: `assets/landing.js`
- Test: `tests/landing.spec.js`

**Interfaces:**
- Consumes: the `data-reveal` attribute already on every `.as-sec-in`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/landing.spec.js`:

```js
test('sections below the fold rise into view as you scroll, and none is left hidden', async ({ page }) => {
  const faq = page.getByTestId('landing-faq').locator('[data-reveal]');

  // Off-screen at load, so it starts hidden and animates in.
  await expect(faq).toHaveCSS('opacity', '0');

  await page.getByTestId('landing-faq').scrollIntoViewIfNeeded();
  await expect(faq).toHaveCSS('opacity', '1');

  // Whatever happens, nothing is left invisible at the bottom of the page.
  await page.keyboard.press('End');
  await expect(page.getByTestId('landing-signup').locator('[data-reveal]')).toHaveCSS('opacity', '1');
});

test('with reduced motion preferred, nothing is hidden waiting to be revealed', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/landing');

  // Every section is visible immediately — the reveal never runs.
  for (const id of ['landing-features', 'landing-calculator', 'landing-faq', 'landing-signup']) {
    await expect(page.getByTestId(id).locator('[data-reveal]')).toHaveCSS('opacity', '1');
  }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test tests/landing.spec.js -g "rise into view"`
Expected: FAIL — opacity is already `1`, because nothing hides it yet.

- [ ] **Step 3: Add the reveal to `assets/landing.js`**

Inside the IIFE, after the FAQ block:

```js
  // ------------------------------------------------------------ reveal

  // Sections fade and rise as they come into view. Anything already on screen
  // at load is never hidden — a reveal that hides the hero and then fails to
  // fire is worse than no reveal at all. Skipped entirely when the visitor
  // prefers reduced motion.
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (!reduced) {
    var pending = [];

    Array.prototype.slice.call(root.querySelectorAll('[data-reveal]')).forEach(function (el) {
      if (el.getBoundingClientRect().top < window.innerHeight) return;
      el.style.opacity = '0';
      el.style.transform = 'translateY(16px)';
      el.style.transition = 'opacity .25s ease-out, transform .25s ease-out';
      pending.push(el);
    });

    var check = function () {
      pending = pending.filter(function (el) {
        if (el.getBoundingClientRect().top >= window.innerHeight * 0.92) return true;
        el.style.opacity = '1';
        el.style.transform = 'none';
        return false;
      });
      if (!pending.length) {
        window.removeEventListener('scroll', check, true);
        window.removeEventListener('resize', check);
      }
    };

    // Capture, so a scroll on any container counts.
    window.addEventListener('scroll', check, true);
    window.addEventListener('resize', check);
    check();
  }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx playwright test tests/landing.spec.js`
Expected: all nineteen PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/landing.js tests/landing.spec.js
git commit -m "$(cat <<'EOF'
Reveal sections as they come into view

Anything already on screen at load is never hidden: a reveal that hides the
hero and then fails to fire is worse than no reveal. Skipped outright when
the visitor prefers reduced motion.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Verify, document, and ship

**Files:**
- Modify: `bluegroup-project-afristream.php`, `package.json`, `CHANGELOG.md`, `README.md`

- [ ] **Step 1: Check the mirror has not drifted from the PHP**

The preview mirror is maintained by hand, so it can silently fall out of step with `includes/landing.php` — which would mean the tests pass against markup the plugin never produces.

Compare them section by section and confirm each of these matches in both:

- the five nav links and their `href`s, in the same order
- the three `data-proof` pills
- 26 `data-platform` spans
- four `data-feature` blocks
- sixteen `.as-sub` buttons, with the same `data-sub-price` values in the same order
- five `data-plan-feature` items
- three testimonial `<figure>`s
- seven `data-faq` items, first one open
- the SureContact container id

Report any drift you find and fix the mirror to match the PHP.

- [ ] **Step 2: Confirm the plugin's own PHP is syntactically sound and self-consistent**

Run: `npm run test:php`
Expected: the existing PHP assertions pass.

Then confirm by reading that every function `includes/landing.php` calls is defined in it or in WordPress core — the file is never loaded by the Playwright tests, so a typo'd function name would not surface until deploy.

- [ ] **Step 3: Run the full suite**

Run: `npm test`
Expected: PASS, no failures and no skips. If a portal test fails, it is a real regression — fix it rather than adjusting the test.

- [ ] **Step 4: Check it by eye**

`npm run preview`, then http://localhost:4173/landing. Walk the page top to bottom at 1440px and at 390px. Confirm: no horizontal scrollbar at either width, the ticker loops without a visible jump, the calculator's figures update, the FAQ opens and closes, and the footer links resolve.

Take a screenshot at each width and describe what you see in your report.

- [ ] **Step 5: Bump the version**

`0.21.0` → `0.22.0`, in all three places: the `Version:` docblock line and the `AFRISTREAM_PORTAL_VERSION` constant in `bluegroup-project-afristream.php`, and `"version"` in `package.json`.

- [ ] **Step 6: Add the changelog entry**

At the top of `CHANGELOG.md`, under `## [0.22.0] - 2026-07-27`:

```markdown
### Added

- The public landing page now ships with the plugin, as a page template
  ("AfriStream Landing" on a page's Template dropdown). It renders the whole
  document itself, so no theme header or footer wraps it and Elementor is no
  longer needed to build the page. `[afristream_landing]` renders the same
  sections inside an existing page for anyone who wants that instead.
- Eight sections: hero, the platform ticker, features, a savings calculator,
  pricing, testimonials, the FAQ, and the newsletter signup.
- The savings calculator totals sixteen annualised subscription prices
  against AfriStream's R1599 and shows the difference, clamped at zero.
- The newsletter signup hosts the SureContact embed.
- Settings → AfriStream Portal gains a Portal page setting, which the landing
  page's Dashboard links point at. Left on "Detect automatically" it finds the
  first published page containing `[afristream_portal]`.
```

- [ ] **Step 7: Update the README**

Add a section after "Using the plugin" describing the landing page: assign the "AfriStream Landing" template to a page, set the Portal page under Settings → AfriStream Portal, and note that the newsletter form is SureContact's embed.

- [ ] **Step 8: Lint once**

Run: `npm run lint`

Record the findings in your report. **Do not fix them** — the human decides.

- [ ] **Step 9: Commit**

```bash
git add bluegroup-project-afristream.php package.json CHANGELOG.md README.md preview/landing.html
git commit -m "$(cat <<'EOF'
Release 0.22.0

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

Do not push and do not open a pull request — that is the controller's call, after the whole-branch review.

---

## Self-review

**Spec coverage.** Delivery as a page template with a shortcode fallback → Task 1; the portal-page setting with auto-detection → Task 1; header and footer → Task 1; hero with the constellation → Task 2; integrations ticker → Task 2; features → Task 3; pricing → Task 3; testimonials → Task 3; calculator with the stated arithmetic and the mobile single-column → Task 4; FAQ → Task 5; SureContact signup and the CTA-to-`#signup` rule → Task 5; scroll reveal → Task 6; `prefers-reduced-motion` → Tasks 2 and 6; accessibility requirements → spread across the tasks that create each control, with the one-`h1` rule tested in Task 1; the preview harness → Tasks 1–5 with a drift check in Task 7; version, changelog and README → Task 7.

**Two corrections to the spec, made deliberately.** The spec lists `scripts/build-plugin.mjs` as a file to touch; it is not needed, because that script already copies the whole `assets` and `includes` directories. And the spec's test list says "the page renders all eight section landmarks"; Task 1 tests one `<h1>` and the header/footer, with each section's own test living in the task that builds it, which is what makes each task independently reviewable.

**Placeholder scan.** No TBDs. Every code step carries the code. Steps 5/6 of Tasks 2–5 describe mirroring markup that the same task's Step 3 gives in full rather than repeating it a second time — the source is in the task, not elsewhere.

**Type consistency.** `afristream_landing_body()` is defined in Task 1 and extended by Tasks 2–5, with the final section order stated in Task 5. `afristream_landing_section_header( $eyebrow, $title, $sub )` is defined in Task 3 and used by Tasks 4 and 5. `afristream_landing_asset( $file )` is defined in Task 1 and used by the header and footer in the same task. The calculator's test IDs (`calc-saving`, `calc-total`, `calc-basis`, `calc-other`) are identical between the PHP in Task 4 Step 3 and the JS in Task 4 Step 4. `AFRISTREAM_LANDING_PRICE` (1599) is used in the PHP; the JS repeats it as `AFRISTREAM_PRICE` because the script takes no server-rendered configuration — Task 4's tests pin both to the same arithmetic.
