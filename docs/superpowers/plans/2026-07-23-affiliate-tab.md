# Affiliate Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a portal tab that only approved SureCart affiliates can see, carrying a link to their SureCart affiliate portal, their referral link and rate, and a calculator that projects twelve months of lifetime recurring commission.

**Architecture:** A new PHP file (`includes/affiliates.php`) resolves the logged-in user against SureCart's affiliations collection, assembles a single JSON payload (affiliation details, commission structure, live recurring plan prices) and serves it from an authenticated REST route, cached per user for five minutes. The front end fetches that route on mount and only then appends the nav item and renders the section — a non-affiliate never receives an affiliate payload, and no page cache can leak the tab. The projection maths lives in a pure function so the figures are verifiable.

**Tech Stack:** WordPress plugin PHP (procedural, `afristream_` prefix), SureCart PHP models (`\SureCart\Models\Affiliation`, `\SureCart\Models\Price`), vanilla ES5-flavoured JS in one IIFE (`assets/portal.js`, no build step, no framework), Node preview harness (`scripts/preview-server.mjs`), Playwright.

## Global Constraints

- Spec: [docs/superpowers/specs/2026-07-23-affiliate-tab-design.md](../specs/2026-07-23-affiliate-tab-design.md). Read it before Task 1.
- No new npm or Composer dependencies. `approved-deps.json` is not to be touched.
- All SureCart access is guarded by `class_exists()` and `is_wp_error()`. Any failure means **no tab** — never an error message, never a partial tab.
- The REST route always describes the caller. It takes no user, email or affiliate parameter.
- `{"affiliate": false}` is the entire response body for a non-affiliate — no rate, no plans.
- Money is handled in minor units (pence/cents) as integers everywhere, and only converted for display.
- Style matches the existing portal: white panels, `border-radius:18px`, `1px solid rgba(11,21,51,.08)`, brand purple `#65009F`, tint `#F7E9FF`, inline styles (there is no class system beyond a few `as-` helpers).
- British English in all user-facing copy ("colour", "personalise"), sentence case headings, no exclamation marks.
- Lint is run once at the end (`npm run lint`), never in a fix/re-lint loop. Findings go to Luke, unactioned.
- Version bump plus changelog entry is part of the work, not an afterthought (Task 5).

---

### Task 1: Affiliate REST endpoint and settings field

**Files:**
- Create: `includes/affiliates.php`
- Modify: `bluegroup-project-afristream.php:25-26` (require), `bluegroup-project-afristream.php:80-90` (portal root attribute)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: REST route `GET /wp-json/afristream/v1/affiliate`, returning either `{"affiliate":false}` or the full payload documented in Step 1. The portal root div gains `data-affiliate-endpoint="<url>"`. PHP functions `afristream_affiliate_payload()`, `afristream_affiliate_lookup()`, `afristream_affiliate_commission( $affiliation )`, `afristream_affiliate_prices()`, `afristream_affiliate_prop( $obj, $key, $default )`, and the option `afristream_affiliate_default_rate`.

- [ ] **Step 1: Create `includes/affiliates.php`**

```php
<?php
/**
 * Affiliate tab data — SureCart affiliation lookup, commission rate, plan prices.
 *
 * Affiliates are approved by hand in SureCart (wp-admin → SureCart → Affiliates).
 * This file answers one question for the portal front-end: is the logged-in user
 * an active affiliate, and if so what do they earn and on what. Everything
 * SureCart-dependent is guarded with class_exists(), so the plugin degrades to
 * "no affiliate tab" rather than fatally when SureCart is absent or errors.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long an answer — positive or negative — is cached per user. Long enough
 * that tab switching costs nothing, short enough that approving an affiliate in
 * SureCart shows up while you are still looking at the screen.
 */
define( 'AFRISTREAM_AFFILIATE_CACHE', 5 * MINUTE_IN_SECONDS );

/**
 * Read a property off a SureCart model, a plain object or an array.
 *
 * SureCart's models expose attributes through __get, which means neither
 * isset() nor ?? can be relied on; this walks the safe accessors first and only
 * then falls back to magic property access.
 *
 * @param mixed  $obj     Model, object or array.
 * @param string $key     Attribute name.
 * @param mixed  $default Returned when the attribute is missing or null.
 * @return mixed
 */
function afristream_affiliate_prop( $obj, $key, $default = null ) {
	if ( is_array( $obj ) ) {
		return isset( $obj[ $key ] ) ? $obj[ $key ] : $default;
	}
	if ( ! is_object( $obj ) ) {
		return $default;
	}
	$vars = get_object_vars( $obj );
	if ( array_key_exists( $key, $vars ) && null !== $vars[ $key ] ) {
		return $vars[ $key ];
	}
	if ( $obj instanceof ArrayAccess && isset( $obj[ $key ] ) ) {
		return $obj[ $key ];
	}
	$value = $obj->$key ?? null;
	return null === $value ? $default : $value;
}

/**
 * The active SureCart affiliation for the logged-in user, or null.
 *
 * Matched on email, which is the only join SureCart offers between a WordPress
 * login and an affiliation. An affiliate whose SureCart record uses a different
 * address to their login will not be recognised.
 *
 * @return object|array|null
 */
function afristream_affiliate_lookup() {
	if ( ! is_user_logged_in() || ! class_exists( '\SureCart\Models\Affiliation' ) ) {
		return null;
	}

	$user = wp_get_current_user();
	if ( ! $user || empty( $user->user_email ) ) {
		return null;
	}

	$affiliation = \SureCart\Models\Affiliation::where(
		array(
			'email'  => $user->user_email,
			'active' => true,
		)
	)->with( array( 'commission_structure' ) )->first();

	if ( is_wp_error( $affiliation ) || empty( $affiliation ) ) {
		return null;
	}

	// The collection filter is the API's, not ours — confirm the match rather
	// than trusting a fuzzy result to be the right person.
	$email = (string) afristream_affiliate_prop( $affiliation, 'email', '' );
	if ( '' === $email || strtolower( $email ) !== strtolower( $user->user_email ) ) {
		return null;
	}
	if ( false === afristream_affiliate_prop( $affiliation, 'active', true ) ) {
		return null;
	}

	return $affiliation;
}

/**
 * The commission an affiliation earns, normalised for the front-end.
 *
 * A null commission_structure means the affiliate is on the store default,
 * which SureCart keeps on the affiliation protocol rather than on the
 * affiliate — so the configured fallback rate stands in for it.
 *
 * @param object|array $affiliation Affiliation model.
 * @return array{percent:float|null,amount:int|null,recurring:bool,recurring_days:int|null}
 */
function afristream_affiliate_commission( $affiliation ) {
	$structure = afristream_affiliate_prop( $affiliation, 'commission_structure' );
	// Unexpanded relations come back as a bare ID string.
	if ( is_string( $structure ) ) {
		$structure = null;
	}

	$percent   = $structure ? afristream_affiliate_prop( $structure, 'percent_commission' ) : null;
	$amount    = $structure ? afristream_affiliate_prop( $structure, 'amount_commission' ) : null;
	$recurring = $structure ? (bool) afristream_affiliate_prop( $structure, 'recurring_commissions_enabled', true ) : true;
	$days      = $structure ? afristream_affiliate_prop( $structure, 'recurring_commission_days' ) : null;

	if ( null === $percent && null === $amount ) {
		$default = (float) get_option( 'afristream_affiliate_default_rate', 0 );
		$percent = $default > 0 ? $default : null;
	}

	return array(
		// amount_commission is read as minor units, matching every other money
		// field in the SureCart API.
		'amount'         => null === $amount ? null : (int) $amount,
		'percent'        => null === $percent ? null : (float) $percent,
		'recurring'      => $recurring,
		'recurring_days' => null === $days ? null : (int) $days,
	);
}

/**
 * Live recurring plans, cheapest first, plus the store currency.
 *
 * Only monthly and yearly prices are offered — the projection is monthly, and a
 * daily or weekly plan would have to be fudged into it.
 *
 * @return array{plans:array<int,array>,currency:string}
 */
function afristream_affiliate_prices() {
	$empty = array(
		'plans'    => array(),
		'currency' => 'usd',
	);

	if ( ! class_exists( '\SureCart\Models\Price' ) ) {
		return $empty;
	}

	$prices = \SureCart\Models\Price::where( array( 'archived' => false ) )
		->with( array( 'product' ) )
		->get();

	if ( is_wp_error( $prices ) || empty( $prices ) ) {
		return $empty;
	}

	$plans    = array();
	$currency = '';

	foreach ( $prices as $price ) {
		if ( '' === $currency ) {
			$currency = (string) afristream_affiliate_prop( $price, 'currency', '' );
		}

		$interval = (string) afristream_affiliate_prop( $price, 'recurring_interval', '' );
		if ( ! in_array( $interval, array( 'month', 'year' ), true ) ) {
			continue;
		}

		$amount = (int) afristream_affiliate_prop( $price, 'amount', 0 );
		if ( $amount <= 0 ) {
			continue;
		}

		$product      = afristream_affiliate_prop( $price, 'product' );
		$product_name = is_string( $product ) ? '' : (string) afristream_affiliate_prop( $product, 'name', '' );
		$price_name   = (string) afristream_affiliate_prop( $price, 'name', '' );
		$label        = trim( $product_name . ( '' !== $price_name ? ' — ' . $price_name : '' ) );

		$plans[] = array(
			'amount'         => $amount,
			'currency'       => (string) afristream_affiliate_prop( $price, 'currency', 'usd' ),
			'id'             => (string) afristream_affiliate_prop( $price, 'id', '' ),
			'interval'       => $interval,
			'interval_count' => max( 1, (int) afristream_affiliate_prop( $price, 'recurring_interval_count', 1 ) ),
			'name'           => '' !== $label ? $label : __( 'AfriStream plan', 'bluegroup-project-afristream' ),
		);
	}

	// Cheapest first: the entry plan is what most referrals actually buy, and it
	// is the honest number to open the calculator on.
	usort(
		$plans,
		function ( $a, $b ) {
			return $a['amount'] <=> $b['amount'];
		}
	);

	return array(
		'plans'    => $plans,
		'currency' => '' !== $currency ? $currency : 'usd',
	);
}

/**
 * The affiliate payload for the current user, cached per user.
 *
 * @return array
 */
function afristream_affiliate_payload() {
	$no = array( 'affiliate' => false );

	if ( ! is_user_logged_in() ) {
		return $no;
	}

	$cache_key = 'afristream_affiliate_' . get_current_user_id();
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$affiliation = afristream_affiliate_lookup();
	if ( ! $affiliation ) {
		// Negative answers are cached too, so a portal full of ordinary
		// subscribers does not hammer the SureCart API on every page view.
		set_transient( $cache_key, $no, AFRISTREAM_AFFILIATE_CACHE );
		return $no;
	}

	$prices  = afristream_affiliate_prices();
	$payload = array(
		'affiliate'    => true,
		'code'         => (string) afristream_affiliate_prop( $affiliation, 'code', '' ),
		'commission'   => afristream_affiliate_commission( $affiliation ),
		'currency'     => $prices['currency'],
		'plans'        => $prices['plans'],
		'portal_url'   => (string) afristream_affiliate_prop( $affiliation, 'portal_url', '' ),
		'referral_url' => (string) afristream_affiliate_prop( $affiliation, 'referral_url', '' ),
	);

	set_transient( $cache_key, $payload, AFRISTREAM_AFFILIATE_CACHE );
	return $payload;
}

/**
 * GET /wp-json/afristream/v1/affiliate — the current user's affiliate details.
 * Logged-in only, and always about the caller: it takes no parameters, so one
 * affiliate can never read another's referral code.
 */
function afristream_affiliate_register_route() {
	register_rest_route(
		'afristream/v1',
		'/affiliate',
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( afristream_affiliate_payload() );
			},
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
}
add_action( 'rest_api_init', 'afristream_affiliate_register_route' );

/**
 * Settings → AfriStream Portal: the store's default commission rate.
 *
 * SureCart exposes a custom per-affiliate rate through the API but keeps the
 * store default on the affiliation protocol, which has no documented PHP model.
 * This field stands in for it, and is only used for affiliates who have no rate
 * of their own.
 */
function afristream_affiliate_register_settings() {
	register_setting(
		'afristream_portal',
		'afristream_affiliate_default_rate',
		array(
			'type'              => 'number',
			'sanitize_callback' => 'afristream_affiliate_sanitize_rate',
			'default'           => 0,
		)
	);

	add_settings_field(
		'afristream_affiliate_default_rate',
		__( 'Default affiliate commission (%)', 'bluegroup-project-afristream' ),
		'afristream_affiliate_rate_field',
		'bluegroup-project-afristream',
		'afristream_portal_data',
		array( 'label_for' => 'afristream_affiliate_default_rate' )
	);
}
add_action( 'admin_init', 'afristream_affiliate_register_settings' );

/**
 * Clamp the default rate to a sane percentage.
 *
 * @param mixed $value Submitted value.
 * @return float
 */
function afristream_affiliate_sanitize_rate( $value ) {
	$rate = (float) $value;
	if ( $rate < 0 ) {
		$rate = 0;
	}
	if ( $rate > 100 ) {
		$rate = 100;
	}
	return $rate;
}

function afristream_affiliate_rate_field() {
	printf(
		'<input type="number" min="0" max="100" step="0.5" class="small-text" name="afristream_affiliate_default_rate" id="afristream_affiliate_default_rate" value="%s">',
		esc_attr( (string) get_option( 'afristream_affiliate_default_rate', 0 ) )
	);
	echo '<p class="description">' . esc_html__( 'The commission rate you set as the store default in SureCart. Affiliates with a custom rate use theirs — this only fills in for everyone else, because SureCart does not expose the store default to plugins. A change takes up to five minutes to appear in the portal.', 'bluegroup-project-afristream' ) . '</p>';
}
```

- [ ] **Step 2: Check the syntax**

Run: `php -l includes/affiliates.php`
Expected: `No syntax errors detected in includes/affiliates.php`

- [ ] **Step 3: Require the new file**

In `bluegroup-project-afristream.php`, after the existing requires (line 25-26):

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/licenses.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/affiliates.php';
```

- [ ] **Step 4: Pass the endpoint to the front end**

In `afristream_portal_shortcode()`, add the attribute to the `sprintf` format string, immediately after `data-credentials-endpoint="%s"`:

```php
	return sprintf(
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s" data-endpoint="%s" data-editor-endpoint="%s" data-detail-endpoint="%s" data-credentials-endpoint="%s" data-affiliate-endpoint="%s" data-apps-url="%s" data-rest-nonce="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] ),
		esc_url( rest_url( 'afristream/v1/watch' ) ),
		esc_url( rest_url( 'afristream/v1/editor-picks' ) ),
		esc_url( rest_url( 'afristream/v1/detail' ) ),
		esc_url( rest_url( 'afristream/v1/credentials' ) ),
		esc_url( rest_url( 'afristream/v1/affiliate' ) ),
		esc_url( add_query_arg( 'ver', AFRISTREAM_PORTAL_VERSION, plugins_url( 'data/apps.json', __FILE__ ) ) ),
		esc_attr( wp_create_nonce( 'wp_rest' ) )
	);
```

Also extend the shortcode docblock above it (line 58) so `affiliate` is a documented entry point:

```php
 * default_tab: profile | setup | watch | apps | editor | download | affiliate | tips | help
```

- [ ] **Step 5: Check the syntax of both files**

Run: `php -l includes/affiliates.php && php -l bluegroup-project-afristream.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add includes/affiliates.php bluegroup-project-afristream.php
git commit -m "Answer whether the logged-in user is a SureCart affiliate

Affiliates are approved by hand in SureCart, and the portal has no way of
knowing who they are. This resolves the current user against SureCart's
affiliations by email and returns their code, referral link, commission
structure and the store's live recurring plans as one payload, cached per user
for five minutes so ordinary subscribers do not hammer the API.

Everything SureCart-dependent is guarded, and every failure - no plugin, an API
error, no match, an inactive affiliation - answers the same way a non-affiliate
does, so the tab can only ever appear for someone who really is one.

The store's default rate has no documented model, so it falls back to a
settings field for affiliates who have no custom rate of their own."
```

---

### Task 2: Gate the tab on the payload, and build the top card

**Files:**
- Modify: `scripts/preview-server.mjs` (fixture + affiliate preview page route), `preview/index.html`, `assets/portal.js`
- Create: `preview/affiliate.html`
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `data-affiliate-endpoint` from Task 1 and its payload shape.
- Produces: in `assets/portal.js` — module-scope `affiliate` (payload or null) and `affiliateState` (`'off' | 'loading' | 'ready'`), `navItems()` returning the nav array, `affiliateSection()` rendering the tab, `money( minorUnits, currency )` returning a formatted string, `rateSentence()` returning the plain-English rate line. Test ids: `nav-affiliate`, `affiliate-card`, `affiliate-referral`, `affiliate-rate`, `affiliate-portal-link`.

- [ ] **Step 1: Add the affiliate fixture to the preview harness**

In `scripts/preview-server.mjs`, after `CREDENTIALS_FIXTURE` (around line 112):

```js
// Deterministic affiliate fixture (mirrors the plugin's afristream/v1/affiliate,
// which is SureCart-backed and per-user in production). 30% recurring on a £15
// monthly plan is the arithmetic the Playwright projection test asserts.
const AFFILIATE_FIXTURE = {
  affiliate: true,
  code: 'FIXTURE1',
  commission: { amount: null, percent: 30, recurring: true, recurring_days: null },
  currency: 'gbp',
  plans: [
    { amount: 1500, currency: 'gbp', id: 'price_month', interval: 'month', interval_count: 1, name: 'AfriStream — 1 Month' },
    { amount: 12000, currency: 'gbp', id: 'price_year', interval: 'year', interval_count: 1, name: 'AfriStream — 12 Months' },
  ],
};
```

Next to the `/api/credentials` handler (around line 602), add:

```js
    if (path === '/api/affiliate') {
      const mode = url.searchParams.get('fixture') || '0';
      const payload = mode === '1'
        ? AFFILIATE_FIXTURE
        : mode === 'noplans'
          ? { ...AFFILIATE_FIXTURE, plans: [] }
          : { affiliate: false };
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(payload));
      return;
    }
```

And immediately before the `if (path === '/' || path === '/index.html')` line, add the affiliate page route:

```js
    // The affiliate preview page carries its fixture mode in its own query
    // string, so one page covers approved, not-approved and no-prices without
    // three near-identical files.
    if (path === '/preview/affiliate.html') {
      const mode = url.searchParams.get('fixture') || '1';
      const html = (await readFile(join(ROOT, 'preview', 'affiliate.html'), 'utf8'))
        .replace('/api/affiliate?fixture=1', `/api/affiliate?fixture=${encodeURIComponent(mode)}`);
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(html);
      return;
    }
```

- [ ] **Step 2: Create the affiliate preview page**

Create `preview/affiliate.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AfriStream Customer Portal — Affiliate Preview</title>
<!-- Test harness: the portal with an affiliate payload behind it. The server
     rewrites the fixture mode from this page's own query string, so
     ?fixture=0 serves a non-affiliate and ?fixture=noplans an affiliate whose
     store has no recurring prices. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" href="data:,">
<link rel="stylesheet" href="/assets/portal.css">
<style>body{margin:0}</style>
</head>
<body>
<div class="afristream-portal" data-afristream-portal data-default-tab="affiliate" data-show-sport="true" data-endpoint="/api/watch?fixture=1" data-editor-endpoint="/api/editor-picks?fixture=1" data-detail-endpoint="/api/detail?fixture=1" data-credentials-endpoint="/api/credentials?fixture=1" data-affiliate-endpoint="/api/affiliate?fixture=1" data-apps-url="/data/apps.json"></div>
<script src="/assets/portal.js"></script>
</body>
</html>
```

In `preview/index.html` line 16, add `data-affiliate-endpoint="/api/affiliate"` (no fixture, so the demo page exercises the non-affiliate path):

```html
<div class="afristream-portal" data-afristream-portal data-default-tab="profile" data-show-sport="true" data-endpoint="/api/watch" data-editor-endpoint="/api/editor-picks" data-detail-endpoint="/api/detail" data-affiliate-endpoint="/api/affiliate" data-apps-url="/data/apps.json"></div>
```

- [ ] **Step 3: Write the failing tests**

Append to `tests/portal.spec.js`:

```js
test('the affiliate tab is hidden from anyone who is not an affiliate', async ({ page }) => {
  await page.goto('/');
  // The payload says affiliate:false, so the nav must not carry the tab at all.
  await expect(page.getByRole('button', { name: 'Affiliates' })).toHaveCount(0);
  await expect(page.getByTestId('nav-affiliate')).toHaveCount(0);
});

test('a non-affiliate deep-linked to the affiliate tab lands on Account', async ({ page }) => {
  await page.goto('/preview/affiliate.html?fixture=0');
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
  await expect(page.getByTestId('nav-affiliate')).toHaveCount(0);
});

test('an affiliate gets the tab, their referral link and their rate', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  await expect(page.getByTestId('nav-affiliate')).toBeVisible();
  await expect(page.getByTestId('affiliate-card')).toBeVisible();
  await expect(page.getByTestId('affiliate-referral')).toHaveText('https://afristream.io/?ref=FIXTURE1');
  await expect(page.getByTestId('affiliate-rate')).toHaveText('You earn 30% of every payment, for as long as they stay subscribed.');
  await expect(page.getByTestId('affiliate-portal-link')).toHaveAttribute('href', 'https://afristream.surecart.com/affiliates/');

  await page.getByRole('button', { name: 'Copy link' }).click();
  await expect(page.getByRole('button', { name: 'Copied!' })).toBeVisible();
});
```

- [ ] **Step 4: Run the tests and watch them fail**

Run: `npx playwright test tests/portal.spec.js -g "affiliate" --reporter=list`
Expected: FAIL — the first test passes vacuously (nothing renders the tab yet), the other two fail on `nav-affiliate` / `affiliate-card` not being found.

- [ ] **Step 5: Read the payload and hold the state**

In `assets/portal.js`, add to the `props` object (after `credentialsEndpoint`, line 297):

```js
      affiliateEndpoint: root.getAttribute('data-affiliate-endpoint') || '',
```

Next to `let credState = …` (line 307):

```js
    // Affiliate payload, and whether the tab exists at all. 'off' covers every
    // negative: no endpoint, not an affiliate, SureCart unreachable. There is
    // deliberately no 'error' state — a failed lookup must look exactly like
    // not being an affiliate, or the tab becomes a way to probe for one.
    let affiliate = null;
    let affiliateState = props.affiliateEndpoint ? 'loading' : 'off';
```

Add `affiliate` to the accepted `default_tab` list (line 320):

```js
      section: ['profile', 'setup', 'watch', 'apps', 'editor', 'download', 'affiliate', 'tips', 'help'].includes(props.defaultTab) ? props.defaultTab : 'profile',
```

Add the calculator inputs to the same state object, after `setupSub: ''` (line 326):

```js
      affPlan: '',
      affPerMonth: 5,
      affValue: 15,
```

- [ ] **Step 6: Fetch the payload on mount**

At the end of `createPortal`, after the credentials fetch block (line 2014):

```js
    // Is this person an affiliate? The tab is appended only on a yes, so the
    // markup never contains anything affiliate-related for anyone else — which
    // also means a page-cached portal cannot leak it.
    if (props.affiliateEndpoint && typeof fetch === 'function') {
      const affHeaders = props.restNonce ? { 'X-WP-Nonce': props.restNonce } : {};
      fetch(props.affiliateEndpoint, { headers: affHeaders, credentials: 'same-origin' })
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          if (payload && payload.affiliate) {
            affiliate = payload;
            affiliateState = 'ready';
            const plans = Array.isArray(payload.plans) ? payload.plans : [];
            if (plans.length) state.affPlan = plans[0].id;
          } else {
            affiliateState = 'off';
            if (state.section === 'affiliate') state.section = 'profile';
          }
          render(true);
        })
        .catch(() => {
          affiliateState = 'off';
          if (state.section === 'affiliate') state.section = 'profile';
          render(true);
        });
    }
```

- [ ] **Step 7: Make the nav a function of state**

Replace the `NAV` constant and the `SECTIONS` line (lines 1463-1478) with:

```js
    // Tips & Tricks and Troubleshooting are deliberately absent here while they
    // are hidden — their sections stay in SECTIONS below, so they are still
    // reachable via default_tab="tips"/"help" and restoring them to the portal
    // nav is a matter of adding the two rows back.
    const NAV = [
      { id: 'profile', label: 'Account' },
      { id: 'setup', label: 'Setup' },
      { id: 'watch', label: 'What to Watch' },
      { id: 'editor', label: 'Editor Picks' },
      { id: 'apps', label: 'Free Streaming' },
      { id: 'download', label: 'Download' }
    ];
    // Affiliates is the one tab that is not for everyone: it appears only once
    // SureCart has confirmed this user is an active affiliate, and sits last so
    // its late arrival never shifts a tab out from under a click.
    const navItems = () => (affiliateState === 'ready' ? NAV.concat([{ id: 'affiliate', label: 'Affiliates' }]) : NAV);

    const SECTIONS = { profile: profileSection, setup: setupSection, watch: watchSection, apps: appsSection, editor: editorSection, download: downloadSection, affiliate: affiliateSection, tips: tipsSection, help: helpSection };
    // A deep link to a tab this user cannot have falls back to the Account tab,
    // the same way an unknown tab name already does.
    const currentSection = () => (
      'affiliate' === state.section && 'ready' !== affiliateState
        ? profileSection
        : (SECTIONS[state.section] || profileSection)
    );
```

In `render()`, replace the nav strip's `NAV.map(…)` (line 1700-1705) with the plain-button form — nothing in the nav carries an `href` any more, so the anchor branch goes with the outbound link it existed for:

```js
      ${navItems().map((n) => `<button style="${navBtn(n.id === state.section)}" data-act="nav" data-val="${n.id}">${esc(n.label)}</button>`).join('')}
```

And replace the main body line (1713):

```js
${currentSection()()}
```

- [ ] **Step 8: Add the money and rate helpers**

In `createPortal`, next to `copy()` (after line 369):

```js
    // Minor units in, formatted money out. AfriStream sells globally, so the
    // currency comes from the plan rather than being assumed.
    const money = (minor, currency) => {
      const value = (Number(minor) || 0) / 100;
      try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: String(currency || 'usd').toUpperCase() }).format(value);
      } catch (e) {
        return value.toFixed(2);
      }
    };

    // 30 rather than 30.0, 12.5 kept as 12.5.
    const trimNum = (n) => String(Math.round(Number(n) * 100) / 100);

    // The rate in a sentence, because "30%" on its own does not tell an
    // affiliate the part that matters — that it keeps paying.
    function rateSentence() {
      const c = (affiliate && affiliate.commission) || {};
      const cur = (affiliate && affiliate.currency) || 'usd';
      let lead;
      if (c.percent) lead = `You earn ${trimNum(c.percent)}% of`;
      else if (c.amount) lead = `You earn ${money(c.amount, cur)} on`;
      else return 'Your commission rate is set in SureCart — open your dashboard to see it.';

      if (!c.recurring) return `${lead} the first payment each customer makes.`;
      if (c.recurring_days) return `${lead} every payment, for the first ${Math.round(c.recurring_days / 30)} months of each subscription.`;
      return `${lead} every payment, for as long as they stay subscribed.`;
    }
```

- [ ] **Step 9: Render the top card**

Add `affiliateSection()` to the sections block, immediately after `profileSection()` ends (line 411). The calculator panel arrives in Task 3 — for now the section is the card alone:

```js
    function affiliateSection() {
      const aff = affiliate || {};
      const portalUrl = aff.portal_url || 'https://afristream.surecart.com/affiliates/';
      const referral = aff.referral_url || '';

      return `
  <div data-testid="affiliate-card" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:22px 23px 24px;margin:0 2px 20px;box-shadow:0 1px 2px rgba(11,21,51,.04)">
    <h2 style="margin:0 0 4px;font-size:17px;font-weight:800;letter-spacing:-0.01em">Your affiliate dashboard</h2>
    <p data-testid="affiliate-rate" style="margin:0 0 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:720px">${esc(rateSentence())}</p>
    <a data-testid="affiliate-portal-link" class="as-hover-primary" href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#65009F;color:#fff;border-radius:13px;padding:13px 24px;font-weight:700;font-size:13.5px;text-decoration:none;box-shadow:0 8px 18px -10px rgba(101,0,159,.7)">Open your dashboard in SureCart ↗</a>
    ${referral ? `
    <div style="margin-top:20px">
      <div style="font-size:13.5px;font-weight:700;margin-bottom:8px">Your referral link</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <div data-testid="affiliate-referral" style="flex:1 1 240px;min-width:0;background:#F4F5F9;border:1px solid rgba(11,21,51,.1);border-radius:13px;padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:14.5px;overflow-wrap:anywhere">${esc(referral)}</div>
        <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-referral">${state.copied === 'referral' ? 'Copied!' : 'Copy link'}</button>
      </div>
    </div>` : ''}
  </div>`;
    }
```

Add the copy action to the click handler, next to `copy-pass` (line 1788):

```js
        case 'copy-referral': copy((affiliate && affiliate.referral_url) || '', 'referral'); break;
```

- [ ] **Step 10: Run the tests and watch them pass**

Run: `npx playwright test tests/portal.spec.js -g "affiliate" --reporter=list`
Expected: 3 passed.

- [ ] **Step 11: Run the whole suite — the nav change touches every tab test**

Run: `npx playwright test --reporter=list`
Expected: all pass. If a test asserted on the outbound Affiliates anchor, it must now assert the tab is absent instead — the link is gone by design.

- [ ] **Step 12: Commit**

```bash
git add assets/portal.js preview/affiliate.html preview/index.html scripts/preview-server.mjs tests/portal.spec.js
git commit -m "Show affiliates their own tab, and nobody else

The portal advertised the affiliate programme to every subscriber with an
outbound link, which is both noise for the people who will never use it and
useless to the people who do. That link is gone. In its place, a tab that
appears only after SureCart confirms an active affiliation, carrying the link
to their dashboard, their referral URL and their rate written as a sentence -
because a bare percentage does not say the part that matters, that it keeps
paying for as long as the customer stays.

The tab is appended on the payload rather than rendered and hidden, so there is
nothing affiliate-related in the markup for anyone else, and a deep link to it
falls back to Account."
```

---

### Task 3: The profit calculator

**Files:**
- Modify: `assets/portal.js`
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `affiliate`, `money()`, `state.affPlan`, `state.affPerMonth`, `state.affValue` from Task 2.
- Produces: `projectEarnings( commissionMinor, perMonth, intervalMonths, months )` returning `{ monthly: number[], first: number, last: number, total: number }` in minor units; `planIntervalMonths( plan )`; `commissionPerPayment( amountMinor )`. Test ids: `affiliate-calculator`, `aff-per-payment`, `aff-month-1`, `aff-month-12`, `aff-year-total`, `aff-chart`, `aff-value-input`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/portal.spec.js`:

```js
// £15/month at 30% is £4.50 a payment. Five new customers a month, each paying
// every month for good: month 1 is 5 payments, month 12 is 60, and the year is
// 4.50 × 5 × (1+2+…+12) = £1,755.
test('the calculator projects a year of stacking recurring commission', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  await expect(page.getByTestId('affiliate-calculator')).toBeVisible();
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£4.50');
  await expect(page.getByTestId('aff-month-1')).toHaveText('£22.50');
  await expect(page.getByTestId('aff-month-12')).toHaveText('£270.00');
  await expect(page.getByTestId('aff-year-total')).toHaveText('£1,755.00');
  await expect(page.getByTestId('aff-chart').locator('[data-bar]')).toHaveCount(12);
});

test('changing the plan and the referral count moves every figure', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  // Ten a month on the same plan doubles everything.
  await page.getByTestId('aff-per-month').fill('10');
  await expect(page.getByTestId('aff-month-12')).toHaveText('£540.00');
  await expect(page.getByTestId('aff-year-total')).toHaveText('£3,510.00');

  // The annual plan pays £36 a head once a year, so every month is the same
  // £180 and the year is £2,160 — flat, not a staircase.
  await page.getByTestId('aff-plan').selectOption('price_year');
  await page.getByTestId('aff-per-month').fill('5');
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£36.00');
  await expect(page.getByTestId('aff-month-1')).toHaveText('£180.00');
  await expect(page.getByTestId('aff-month-12')).toHaveText('£180.00');
  await expect(page.getByTestId('aff-year-total')).toHaveText('£2,160.00');
});

test('with no plans to pick from the calculator falls back to a sale value', async ({ page }) => {
  await page.goto('/preview/affiliate.html?fixture=noplans');

  await expect(page.getByTestId('aff-plan')).toHaveCount(0);
  await expect(page.getByTestId('aff-value-input')).toBeVisible();

  await page.getByTestId('aff-value-input').fill('15');
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£4.50');
  await expect(page.getByTestId('aff-month-12')).toHaveText('£270.00');
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `npx playwright test tests/portal.spec.js -g "calculator|projects a year|sale value" --reporter=list`
Expected: FAIL — `affiliate-calculator` is not found.

- [ ] **Step 3: Add the projection maths**

In `assets/portal.js`, at module scope next to the other pure helpers (after `esc`, around line 24):

```js
  // Commission runs for the life of the subscription, so a month's income is
  // every cohort whose renewal lands in it — not just that month's new sign-ups.
  // Minor units throughout (pence, cents) so twelve months of arithmetic cannot
  // drift by a penny. Returns totals plus the month-by-month series the chart
  // draws.
  const projectEarnings = (commissionMinor, perMonth, intervalMonths, months) => {
    const step = Math.max(1, intervalMonths);
    const monthly = [];
    for (let m = 0; m < months; m++) {
      let payers = 0;
      for (let cohort = 0; cohort <= m; cohort++) {
        if ((m - cohort) % step === 0) payers += perMonth;
      }
      monthly.push(payers * commissionMinor);
    }
    return {
      monthly,
      first: monthly[0] || 0,
      last: monthly[months - 1] || 0,
      total: monthly.reduce((a, b) => a + b, 0)
    };
  };

  // A yearly plan renews every twelve months; interval_count multiplies both.
  const planIntervalMonths = (plan) => Math.max(1, ('year' === plan.interval ? 12 : 1) * (Number(plan.interval_count) || 1));
```

- [ ] **Step 4: Render the calculator**

Inside `createPortal`, next to `rateSentence()`:

```js
    // What one payment on a given sale value earns this affiliate. A percentage
    // structure wins over a fixed amount when SureCart somehow returns both.
    const commissionPerPayment = (amountMinor) => {
      const c = (affiliate && affiliate.commission) || {};
      if (c.percent) return Math.round((amountMinor * c.percent) / 100);
      if (c.amount) return Math.round(c.amount);
      return 0;
    };
```

Then replace the `return` in `affiliateSection()` so the calculator panel follows the card:

```js
    function affiliateSection() {
      const aff = affiliate || {};
      const portalUrl = aff.portal_url || 'https://afristream.surecart.com/affiliates/';
      const referral = aff.referral_url || '';

      const plans = Array.isArray(aff.plans) ? aff.plans : [];
      const plan = plans.find((p) => p.id === state.affPlan) || plans[0] || null;
      const currency = plan ? plan.currency : (aff.currency || 'usd');
      // Clamped for the maths only — the input keeps rendering exactly what was
      // typed, or clearing the box to type "10" would snap it back to 1.
      const perMonth = Math.max(1, Math.min(100, Number(state.affPerMonth) || 1));
      // No live prices to pick from — the affiliate types what a sale is worth.
      const saleMinor = plan ? plan.amount : Math.max(0, Math.round((Number(state.affValue) || 0) * 100));
      const intervalMonths = plan ? planIntervalMonths(plan) : 1;
      const perPayment = commissionPerPayment(saleMinor);
      const proj = projectEarnings(perPayment, perMonth, intervalMonths, 12);
      const peak = Math.max.apply(null, proj.monthly.concat([1]));

      const inputStyle = 'width:100%;box-sizing:border-box;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:13px;padding:12px 14px;font-family:inherit;font-size:14px;color:inherit';
      const figure = (id, label, value) => `
        <div style="background:#F7E9FF;border:1px solid rgba(101,0,159,.18);border-radius:14px;padding:14px 16px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(11,21,51,.5)">${esc(label)}</div>
          <div data-testid="${id}" style="margin-top:5px;font-size:21px;font-weight:800;letter-spacing:-0.02em;color:#65009F">${esc(value)}</div>
        </div>`;

      const picker = plans.length
        ? `<label style="display:block">
            <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">Plan they sign up to</span>
            <select data-testid="aff-plan" data-act="aff-plan" style="${inputStyle}">
              ${plans.map((p) => `<option value="${esc(p.id)}"${p.id === (plan ? plan.id : '') ? ' selected' : ''}>${esc(p.name)} · ${esc(money(p.amount, p.currency))} / ${esc('year' === p.interval ? 'year' : 'month')}</option>`).join('')}
            </select>
          </label>`
        : `<label style="display:block">
            <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">What one subscription is worth</span>
            <input data-testid="aff-value-input" data-act="aff-value" type="number" min="0" step="1" value="${esc(state.affValue)}" style="${inputStyle}">
          </label>`;

      return `
  <div data-testid="affiliate-card" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:22px 23px 24px;margin:0 2px 20px;box-shadow:0 1px 2px rgba(11,21,51,.04)">
    <h2 style="margin:0 0 4px;font-size:17px;font-weight:800;letter-spacing:-0.01em">Your affiliate dashboard</h2>
    <p data-testid="affiliate-rate" style="margin:0 0 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:720px">${esc(rateSentence())}</p>
    <a data-testid="affiliate-portal-link" class="as-hover-primary" href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#65009F;color:#fff;border-radius:13px;padding:13px 24px;font-weight:700;font-size:13.5px;text-decoration:none;box-shadow:0 8px 18px -10px rgba(101,0,159,.7)">Open your dashboard in SureCart ↗</a>
    ${referral ? `
    <div style="margin-top:20px">
      <div style="font-size:13.5px;font-weight:700;margin-bottom:8px">Your referral link</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <div data-testid="affiliate-referral" style="flex:1 1 240px;min-width:0;background:#F4F5F9;border:1px solid rgba(11,21,51,.1);border-radius:13px;padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:14.5px;overflow-wrap:anywhere">${esc(referral)}</div>
        <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-referral">${state.copied === 'referral' ? 'Copied!' : 'Copy link'}</button>
      </div>
    </div>` : ''}
  </div>

  <div data-testid="affiliate-calculator" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:22px 23px 24px;margin:0 2px 20px;box-shadow:0 1px 2px rgba(11,21,51,.04)">
    <h2 style="margin:0 0 4px;font-size:17px;font-weight:800;letter-spacing:-0.01em">What you could earn</h2>
    <p style="margin:0 0 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:720px">Because commission is paid on every payment, each person you sign up keeps paying you while the next one starts. That is what stacks up over a year.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px">
      ${picker}
      <label style="display:block">
        <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">People you sign up each month</span>
        <input data-testid="aff-per-month" data-act="aff-per-month" type="number" min="1" max="100" step="1" value="${esc(state.affPerMonth)}" style="${inputStyle}">
      </label>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
      ${figure('aff-per-payment', 'Each payment', money(perPayment, currency))}
      ${figure('aff-month-1', 'Month 1', money(proj.first, currency))}
      ${figure('aff-month-12', 'Month 12', money(proj.last, currency))}
      ${figure('aff-year-total', 'First year', money(proj.total, currency))}
    </div>

    <div data-testid="aff-chart" aria-hidden="true" style="display:flex;align-items:flex-end;gap:6px;height:120px;margin-top:20px">
      ${proj.monthly.map((v, i) => `<div data-bar title="Month ${i + 1}" style="flex:1;height:${Math.max(3, Math.round((v / peak) * 100))}%;background:linear-gradient(180deg,#CD2DF5,#65009F);border-radius:6px 6px 3px 3px"></div>`).join('')}
    </div>
    <div aria-hidden="true" style="display:flex;justify-content:space-between;margin-top:7px;font-size:11px;color:rgba(11,21,51,.45)"><span>Month 1</span><span>Month 12</span></div>

    <p style="margin:16px 0 0;font-size:12.5px;line-height:1.6;color:rgba(11,21,51,.5)">These figures assume the people you sign up stay subscribed — commission keeps coming for as long as they do, and stops if they cancel.</p>
  </div>`;
    }
```

- [ ] **Step 5: Wire the inputs**

The `input` handler currently only knows about the search box, and `render()` only restores focus to it — a number input would lose focus on every keystroke. Generalise both.

Replace the focus capture at the top of `render()` (lines 1685-1689):

```js
      const active = document.activeElement;
      const activeAct = preserveFocus && active && active.getAttribute ? active.getAttribute('data-act') : null;
      // Inputs that re-render on every keystroke have to get their focus and
      // caret back, or typing a two-digit number is impossible.
      const focusAct = ['query', 'aff-per-month', 'aff-value'].includes(activeAct) ? activeAct : null;
      if (focusAct) {
        hadFocus = true;
        caret = active.selectionStart;
      }
```

Replace the restore block (lines 1719-1725):

```js
      if (hadFocus) {
        const input = root.querySelector(`[data-act="${focusAct}"]`);
        if (input) {
          input.focus();
          try { input.setSelectionRange(caret, caret); } catch (e) { /* number/search inputs reject this — ignore */ }
        }
      }
```

Replace the `input` listener (lines 1812-1817):

```js
    root.addEventListener('input', (e) => {
      const act = e.target.getAttribute && e.target.getAttribute('data-act');
      if ('query' === act) {
        state.query = e.target.value;
        render(true);
      } else if ('aff-per-month' === act) {
        state.affPerMonth = e.target.value;
        render(true);
      } else if ('aff-value' === act) {
        state.affValue = e.target.value;
        render(true);
      }
    });
```

And add a `change` listener for the plan picker immediately after it — a `<select>` is not covered by the click handler:

```js
    root.addEventListener('change', (e) => {
      if (e.target.getAttribute && 'aff-plan' === e.target.getAttribute('data-act')) {
        setState({ affPlan: e.target.value });
      }
    });
```

- [ ] **Step 6: Run the calculator tests**

Run: `npx playwright test tests/portal.spec.js -g "calculator|projects a year|sale value|referral count" --reporter=list`
Expected: 3 passed.

- [ ] **Step 7: Run the whole suite**

Run: `npx playwright test --reporter=list`
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "Show an affiliate what a year of referrals is actually worth

A percentage tells an affiliate nothing about the size of the opportunity. This
projects it: pick the plan, say how many people you sign up a month, and see
each payment, month one, month twelve and the year - with a chart of the
staircase that recurring commission builds.

Annual plans are modelled on their real renewal month rather than smeared
across twelve, so they read as the flat line they are next to a monthly plan's
climb. The maths is a pure function in minor units so a year of arithmetic
cannot drift, and it says plainly that the figures assume people stay
subscribed."
```

---

### Task 4: Ship it

**Files:**
- Modify: `bluegroup-project-afristream.php:6,16` (version), `package.json:3` (version), `CHANGELOG.md`

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: version 0.17.0, a changelog entry, and a verified deployment zip at `../bluegroup-project-afristream.zip`.

- [ ] **Step 1: Bump the version — a new tab is a feature, so minor**

`bluegroup-project-afristream.php` line 6: ` * Version:     0.17.0`
`bluegroup-project-afristream.php` line 16: `define( 'AFRISTREAM_PORTAL_VERSION', '0.17.0' );`
`package.json` line 3: `"version": "0.17.0",`

- [ ] **Step 2: Add the changelog entry**

Insert above the `## [0.16.1]` heading in `CHANGELOG.md`:

```markdown
## [0.17.0] - 2026-07-23

### Added

- **An Affiliates tab**, shown only to people SureCart confirms are active affiliates. It carries a link straight to their SureCart affiliate dashboard, their referral URL with a copy button, and their commission rate written as a sentence — including whether it keeps paying on renewals, which is the part a bare percentage never says.

  Below that, a profit calculator. Pick the plan a referral signs up to (pulled live from the store's own recurring prices, so it always matches what you charge), say how many people you sign up a month, and it projects each payment, month one, month twelve and the first year, with a chart of the staircase recurring commission builds. Annual plans are modelled on their real renewal month rather than smeared across twelve. Custom per-affiliate rates come through from SureCart automatically; a **Default affiliate commission (%)** setting stands in for the store default, which SureCart does not expose to plugins.

### Changed

- The public **Affiliates** link has been removed from the portal nav. It advertised the programme to every subscriber; the tab that replaces it appears only for affiliates, and only after SureCart says so.
```

- [ ] **Step 3: Lint once, and report rather than fix**

Run: `npm run lint`
Expected: exits 0. Anything it reports goes to Luke unactioned — no lint/fix/re-lint loop.

- [ ] **Step 4: Run the full suite one more time**

Run: `npx playwright test --reporter=list`
Expected: all pass.

- [ ] **Step 5: Build and zip the plugin**

```bash
npm run build
rm -f ../bluegroup-project-afristream.zip
/c/Windows/System32/tar.exe -a -c -f ../bluegroup-project-afristream.zip -C dist bluegroup-project-afristream
/c/Windows/System32/tar.exe -tf ../bluegroup-project-afristream.zip
```

Expected: every entry reads `bluegroup-project-afristream/…` with forward slashes, `includes/affiliates.php` among them. Never use PowerShell `Compress-Archive` — its backslash entries break WordPress installs.

- [ ] **Step 6: Commit**

```bash
git add bluegroup-project-afristream.php package.json CHANGELOG.md
git commit -m "Release 0.17.0 — the affiliate tab"
```

---

## Manual verification (after merge, on the live site)

The preview harness fakes SureCart, so the one thing tests cannot prove is that the real lookup matches. On the staging or live site, signed in as an approved affiliate:

1. The Affiliates tab appears within a second of the portal loading.
2. The referral link matches the one in SureCart → Affiliates for that person.
3. The rate matches theirs — set a custom rate on one affiliate and confirm the sentence changes for them within five minutes and not for anyone else.
4. Sign in as an ordinary subscriber: no tab, and `/wp-json/afristream/v1/affiliate` returns `{"affiliate":false}`.
5. Sign out: the same route returns a 401 rather than a payload.

## Risks carried into implementation

- **`amount_commission` units.** Read as minor units, consistent with every other money field in the SureCart API, but its schema type is `number` rather than `integer`. If a fixed-amount affiliate ever shows a figure 100× out, that is the line to change ([includes/affiliates.php](../../../includes/affiliates.php), `afristream_affiliate_commission`).
- **Email mismatch.** The lookup joins on email; an affiliate whose SureCart record uses a different address to their WordPress login sees no tab. The fix, if it bites, is a per-user affiliation ID override.
- **Model availability.** `Affiliation` and `Price` are documented SureCart models, but both calls are guarded — a rename in SureCart costs the tab, not the site.
