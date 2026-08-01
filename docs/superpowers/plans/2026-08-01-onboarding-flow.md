# Onboarding Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put a six-step onboarding flow behind every "Get Started" button on the landing page, so the customer's answers about their device and existing subscriptions decide which of the two checkouts they are sent to.

**Architecture:** A modal overlay rendered into the landing page by PHP and driven by a new vanilla-JS step machine. Configuration (price, setup fee, both checkout URLs) rides on `data-` attributes of the modal root so the static preview mirror can reproduce it. Nothing is stored: the flow's only output is which checkout URL to open. Every CTA keeps its real `href`, so the page still buys with JavaScript off.

**Tech Stack:** WordPress plugin PHP (no framework), vanilla ES5-style JS (no build step), hand-written CSS, Playwright against the static preview mirror, and the repo's own tiny PHP test harness (`tests/php/assert.php`).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-01-onboarding-flow-design.md`. Read it before Task 1.
- **No new dependencies.** `approved-deps.json` governs; this feature adds none.
- **No build step.** JS is served as written. ES5-compatible style, matching `assets/landing.js` and `assets/portal.js` — `var`, `function`, no arrow functions, no template literals, IIFE wrapper, `'use strict'`.
- **`preview/landing.html` is a hand-maintained mirror of `afristream_landing_body()`.** `tests/php/test-landing.php` compares them on section ids, marker counts, `data-sub-price` values and every visible text node. Any markup added to PHP must be added to the mirror **verbatim, in the same place**, in the same commit.
- Text passed through PHP goes through `esc_html()` / `esc_url()` / `esc_attr()`. The mirror carries the already-decoded text.
- The mirror represents a **configured** install: setup fee `499`, setup checkout `https://pay.example.test/afristream-setup`, Get Started URL `#pricing` (the unset fallback), price `1599`. `af_landing_seed_setup_plan()` in the PHP tests seeds this.
- Test commands: `npm run test:php` (PHP only), `npx playwright test tests/onboarding.spec.js` (one spec), `npm test` (both).
- `npm run lint` lists each JS file explicitly. Every new `assets/*.js` file must be added to that list.
- Commit after every task. Do not bump the version until Task 6.

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/onboarding.php` (new) | Renders the modal markup and its configuration attributes. Nothing else — no settings, no options of its own; it reads the four accessors `includes/landing.php` already exports. |
| `assets/savings.js` (new) | The savings arithmetic and Rand formatting, shared by the page calculator and the modal. Exposes `window.AfriStreamSavings`. |
| `assets/onboarding.js` (new) | The step machine: which step is visible, what the progress counter reads, the breakdown's numbers, and which checkout URL the final button opens. |
| `assets/onboarding.css` (new) | Overlay, panel, steps, choices, breakdown. Separate from `landing.css`, which is already 1220 lines. |
| `includes/landing.php` (modify) | `data-onboard` on the five CTAs, `data-price` on the calculator, render the modal into the body, register/enqueue the two new assets. |
| `assets/landing.js` (modify) | Read the price from the DOM instead of the hardcoded `1599`; use `AfriStreamSavings` for the maths. |
| `preview/landing.html` (modify) | Mirror every markup change; load the two new assets. |
| `tests/onboarding.spec.js` (new) | Playwright coverage of the flow. |
| `tests/php/test-onboarding.php` (new) | Configuration attributes and conditional rendering. |
| `tests/php/test-landing.php` (modify) | The `data-sub-price` marker count doubles once the modal has its own chips. |
| `bluegroup-project-afristream.php`, `package.json`, `CHANGELOG.md` (modify) | Require the new include; version bump and changelog in Task 6. |

## The markup contract

Every task below writes against this shape. It is reproduced here once so tasks can be read out of order.

```html
<div class="as-ob" data-testid="onboarding"
     data-price="1599" data-setup-fee="499"
     data-cta="#pricing" data-setup-cta="https://pay.example.test/afristream-setup" hidden>
  <div class="as-ob-backdrop" data-ob-close></div>
  <div class="as-ob-panel" role="dialog" aria-modal="true" aria-labelledby="as-ob-title" tabindex="-1">
    <div class="as-ob-top">
      <span class="as-ob-progress" data-testid="ob-progress">Step 1 of 5</span>
      <button class="as-ob-x" type="button" data-ob-close aria-label="Close">…</button>
    </div>
    <h2 class="as-ob-title" id="as-ob-title" data-testid="ob-title">…</h2>
    <div class="as-ob-step" data-ob-step="intro">…</div>
    <div class="as-ob-step" data-ob-step="device" hidden>…</div>
    <div class="as-ob-step" data-ob-step="subs" hidden>…</div>
    <div class="as-ob-step" data-ob-step="offer" hidden>…</div>
    <div class="as-ob-step" data-ob-step="breakdown" hidden>…</div>
    <div class="as-ob-nav">
      <button class="as-btn as-btn-ghost" type="button" data-testid="ob-back" data-ob-back hidden>Back</button>
      <button class="as-btn as-btn-primary" type="button" data-testid="ob-next" data-ob-next>Continue</button>
    </div>
  </div>
</div>
```

The `<h2 id="as-ob-title">` is the dialog's accessible name and the only heading; the step machine rewrites its text. The modal is a `<div>`, never a `<section id>`, so the mirror's section-id parity test is unaffected.

JS state, held in one object for the life of the visit:

```js
var state = { device: null, subs: 0, other: 0, wantsDevice: null };
```

`device` and `wantsDevice` are `'yes'`, `'no'` or `null`. `subs` is the annual total of the pressed chips; `other` is the free-text amount.

The visible step list is derived, never stored:

```js
function steps() {
  var list = ['intro', 'device', 'subs'];
  if (state.device !== 'yes' && fee > 0) list.push('offer');
  list.push('breakdown');
  return list;
}
```

---

## Task 1: The modal shell and the intro step

Closes #22. After this task, every Get Started button opens a dialog showing what the subscription includes, and closing it returns the customer to the page.

**Files:**
- Create: `includes/onboarding.php`
- Create: `assets/onboarding.js`
- Create: `assets/onboarding.css`
- Create: `tests/onboarding.spec.js`
- Create: `tests/php/test-onboarding.php`
- Modify: `includes/landing.php` (asset registration, `data-onboard` on five CTAs, render the modal)
- Modify: `preview/landing.html`
- Modify: `bluegroup-project-afristream.php` (require the new include)
- Modify: `package.json` (lint list)

**Interfaces:**
- Consumes: `afristream_landing_price()`, `afristream_landing_setup_fee()`, `afristream_landing_cta_url()`, `afristream_landing_setup_cta_url()`, `AFRISTREAM_LANDING_PLAN_FEATURES` — all already in `includes/landing.php`.
- Produces: `afristream_onboarding_modal()` returning the modal HTML string; the markup contract above; the `data-onboard` attribute on CTAs.

- [ ] **Step 1: Write the failing Playwright test**

Create `tests/onboarding.spec.js`:

```js
import { test, expect } from '@playwright/test';

// The flow is a modal on the landing page. These run against the preview
// mirror at /landing, which loads the same CSS and JS the plugin enqueues,
// and which represents a configured install: R1599 a year, a R499 setup fee.

test.beforeEach(async ({ page }) => {
  await page.goto('/landing');
});

const modal = (page) => page.getByTestId('onboarding');

test('the flow is closed until a Get Started button opens it', async ({ page }) => {
  await expect(modal(page)).toBeHidden();
  await page.getByTestId('header-cta').click();
  await expect(modal(page)).toBeVisible();
  await expect(page.getByTestId('ob-title')).toHaveText('What you get with AfriStream');
});

test('all five Get Started buttons open the flow', async ({ page }) => {
  const buttons = await page.locator('.as-landing [data-onboard]').all();
  expect(buttons.length).toBe(6); // five Get Started, plus the setup card's

  for (const button of buttons) {
    await button.scrollIntoViewIfNeeded();
    await button.click();
    await expect(modal(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modal(page)).toBeHidden();
  }
});

test('every CTA still carries a working checkout href for a visitor without JS', async ({ page }) => {
  const hrefs = await page.locator('.as-landing [data-onboard]').evaluateAll((els) =>
    els.map((el) => el.getAttribute('href')));

  expect(hrefs).toHaveLength(6);
  hrefs.forEach((href) => expect(href, 'a CTA with no destination').toBeTruthy());
});

test('escape closes the flow and returns focus to the button that opened it', async ({ page }) => {
  const cta = page.getByTestId('header-cta');
  await cta.click();
  await expect(modal(page)).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(modal(page)).toBeHidden();
  await expect(cta).toBeFocused();
});

test('the backdrop closes the flow and the panel does not', async ({ page }) => {
  await page.getByTestId('header-cta').click();
  await page.locator('.as-ob-panel').click({ position: { x: 10, y: 10 } });
  await expect(modal(page)).toBeVisible();

  await page.locator('.as-ob-backdrop').click({ position: { x: 5, y: 5 } });
  await expect(modal(page)).toBeHidden();
});

test('focus moves into the dialog on open and is trapped there', async ({ page }) => {
  await page.getByTestId('header-cta').click();

  const inPanel = () => page.evaluate(() =>
    !!document.activeElement.closest('.as-ob-panel'));
  expect(await inPanel()).toBe(true);

  // Tab all the way round: focus must never escape to the page behind.
  for (let i = 0; i < 12; i += 1) {
    await page.keyboard.press('Tab');
    expect(await inPanel(), `focus left the dialog after ${i + 1} tabs`).toBe(true);
  }
});

test('the intro lists what the subscription includes and the annual price', async ({ page }) => {
  await page.getByTestId('header-cta').click();
  const step = page.locator('[data-ob-step="intro"]');

  await expect(step).toContainText('Access to over 20 000 live feeds');
  await expect(step).toContainText('R1599');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 5');
});
```

- [ ] **Step 2: Run it and watch it fail**

```
npx playwright test tests/onboarding.spec.js
```

Expected: every test fails — `getByTestId('onboarding')` resolves to nothing.

- [ ] **Step 3: Create `includes/onboarding.php`**

```php
<?php
/**
 * The onboarding flow behind the landing page's "Get Started" buttons.
 *
 * Markup only. The step machine lives in assets/onboarding.js, and every
 * number it needs is carried on this element's data attributes rather than a
 * localised script object: preview/landing.html is a static mirror of this
 * output, and a localised object is the one thing it could not reproduce —
 * which would put the whole flow outside the Playwright suite.
 *
 * @package bluegroup-project-afristream
 */

defined( 'ABSPATH' ) || exit;

/**
 * The intro step's summary of what is being bought.
 *
 * Deliberately its own list rather than AFRISTREAM_LANDING_PLAN_FEATURES: the
 * pricing card's lines are scannable fragments beside a price, and this is the
 * first thing a customer reads after committing to a click.
 */
const AFRISTREAM_ONBOARDING_INCLUDES = array(
	'Access to over 20 000 live feeds',
	'Films, series and live sport in one app',
	'The AfriStream app and the customer portal',
	'Setup guides and support',
	'A 14 day money back guarantee',
);

/**
 * The whole modal, hidden until a call to action opens it.
 */
function afristream_onboarding_modal() {
	$includes = '';
	foreach ( AFRISTREAM_ONBOARDING_INCLUDES as $line ) {
		$includes .= '<li>' . esc_html( $line ) . '</li>';
	}

	return '
<div class="as-ob" data-testid="onboarding" data-price="' . (int) afristream_landing_price() . '" data-setup-fee="' . (int) afristream_landing_setup_fee() . '" data-cta="' . esc_url( afristream_landing_cta_url() ) . '" data-setup-cta="' . esc_url( afristream_landing_setup_cta_url() ) . '" hidden>
  <div class="as-ob-backdrop" data-ob-close></div>
  <div class="as-ob-panel" role="dialog" aria-modal="true" aria-labelledby="as-ob-title" tabindex="-1">
    <div class="as-ob-top">
      <span class="as-ob-progress" data-testid="ob-progress">Step 1 of 5</span>
      <button class="as-ob-x" type="button" data-ob-close aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
      </button>
    </div>
    <h2 class="as-ob-title" id="as-ob-title" data-testid="ob-title">What you get with AfriStream</h2>
    <div class="as-ob-step" data-ob-step="intro">
      <p class="as-ob-lede">One annual subscription of R' . (int) afristream_landing_price() . ', covering everything below. The next few questions take under a minute and make sure you only pay for what you need.</p>
      <ul class="as-ob-list">' . $includes . '</ul>
    </div>
    <div class="as-ob-nav">
      <button class="as-btn as-btn-ghost" type="button" data-testid="ob-back" data-ob-back hidden>Back</button>
      <button class="as-btn as-btn-primary" type="button" data-testid="ob-next" data-ob-next>Continue</button>
    </div>
  </div>
</div>';
}
```

- [ ] **Step 4: Wire it into `includes/landing.php`**

Register and enqueue the new assets. In `afristream_landing_register_assets()`, after the existing `wp_register_script( 'afristream-landing', … )` call, add:

```php
	wp_register_style(
		'afristream-onboarding',
		plugins_url( 'assets/onboarding.css', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array( 'afristream-landing' ),
		AFRISTREAM_PORTAL_VERSION
	);
	wp_register_script(
		'afristream-onboarding',
		plugins_url( 'assets/onboarding.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
```

In `afristream_landing_enqueue()`, add the two lines:

```php
	wp_enqueue_style( 'afristream-onboarding' );
	wp_enqueue_script( 'afristream-onboarding' );
```

In `afristream_landing_body()`, add the modal as the last item before the closing `</div>`:

```php
		. afristream_landing_footer()
		. afristream_onboarding_modal()
		. '</div>';
```

Add `data-onboard` to the five Get Started buttons and the setup card's button. The header (line ~751), the mobile menu (~756), the setup card CTA (~859), the plain card CTA (~880), the calculator CTA (~942) and the closing signup CTA (in `afristream_landing_signup()`, ~1033). For each, insert `data-onboard` before `href` — and on the setup card's button only, `data-onboard="setup"`:

```php
      <a class="as-btn as-btn-primary as-plan-cta as-plan-cta-setup" data-testid="plan-setup-cta" data-onboard="setup" href="…">Get Started with Setup</a>
```

```php
      <a class="as-btn as-btn-primary as-plan-cta" data-testid="plan-cta" data-onboard href="…">Get Started</a>
```

The five plain ones take a bare `data-onboard`.

- [ ] **Step 5: Require the include**

In `bluegroup-project-afristream.php`, after line 35:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/onboarding.php';
```

- [ ] **Step 6: Write `assets/onboarding.js`**

Only the shell for this task — open, close, focus. Later tasks fill in the step machine.

```js
/* The onboarding flow behind the landing page's Get Started buttons. A step
   machine over the panels rendered by includes/onboarding.php. Every number it
   needs is on the root's data attributes. Nothing is stored: the only output
   is which checkout URL the last button opens. No framework, no build step —
   the same approach as landing.js. */
(function () {
  'use strict';

  var root = document.querySelector('[data-testid="onboarding"]');
  if (!root) return;

  var panel = root.querySelector('.as-ob-panel');
  var opener = null;

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function focusable() {
    return Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE))
      .filter(function (el) { return el.offsetParent !== null; });
  }

  function open(from) {
    opener = from || null;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    panel.focus();
  }

  function close() {
    root.hidden = true;
    document.body.style.overflow = '';
    // Returning focus to the button that opened the flow: without this a
    // keyboard user is dropped back at the top of the document, having lost
    // the place they were reading.
    if (opener) opener.focus();
    opener = null;
  }

  // The href stays on every CTA so the page still buys with this script
  // absent. Intercepting the click is what turns it into the flow.
  document.addEventListener('click', function (e) {
    var cta = e.target.closest('[data-onboard]');
    if (cta) {
      e.preventDefault();
      open(cta);
      return;
    }
    if (e.target.closest('[data-ob-close]')) close();
  });

  document.addEventListener('keydown', function (e) {
    if (root.hidden) return;

    if (e.key === 'Escape' || e.key === 'Esc') {
      close();
      return;
    }

    // Trap: tabbing off either end of the panel wraps rather than landing on
    // the page behind, which is still scrolled to wherever they clicked.
    if (e.key !== 'Tab') return;
    var items = focusable();
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    var active = document.activeElement;

    if (e.shiftKey && (active === first || active === panel)) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  });
})();
```

- [ ] **Step 7: Write `assets/onboarding.css`**

```css
/* The onboarding modal. Kept out of landing.css, which is long enough. */

.as-ob[hidden] { display: none; }

.as-ob {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
}

.as-ob-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(5, 4, 12, .78);
}

.as-ob-panel {
  position: relative;
  width: min(560px, 100%);
  max-height: min(88vh, 720px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 26px;
  border: 1px solid rgba(205, 45, 245, .28);
  border-radius: 18px;
  background: #0d0a17;
  color: #f4f1fa;
}

.as-ob-panel:focus { outline: none; }

.as-ob-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.as-ob-progress {
  font-family: 'Space Mono', monospace;
  font-size: 12px;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: #cd2df5;
}

.as-ob-x {
  display: inline-flex;
  padding: 6px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #f4f1fa;
  cursor: pointer;
}

.as-ob-x:hover { background: rgba(255, 255, 255, .08); }

.as-ob-title {
  margin: 0;
  font-size: 24px;
  line-height: 1.25;
}

.as-ob-lede { margin: 0 0 14px; color: #b9b2cc; }

.as-ob-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 9px;
}

.as-ob-list li {
  position: relative;
  padding-left: 26px;
  color: #d8d3e6;
}

.as-ob-list li::before {
  content: '';
  position: absolute;
  left: 6px;
  top: .55em;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #cd2df5;
}

.as-ob-step[hidden] { display: none; }

.as-ob-nav {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: auto;
}

.as-ob-nav [hidden] { display: none; }

@media (max-width: 480px) {
  .as-ob { padding: 0; align-items: stretch; }
  .as-ob-panel {
    width: 100%;
    max-height: 100%;
    border: 0;
    border-radius: 0;
  }
}
```

- [ ] **Step 8: Mirror it in `preview/landing.html`**

Add `data-onboard` to the same six links (header CTA, menu CTA, calculator CTA, plan CTA, plan-setup CTA with `="setup"`, signup CTA). Add the stylesheet to `<head>` after the landing one:

```html
<link rel="stylesheet" href="/assets/onboarding.css">
```

Paste the modal markup — exactly as `afristream_onboarding_modal()` renders it for a configured install (`data-price="1599"`, `data-setup-fee="499"`, `data-cta="#pricing"`, `data-setup-cta="https://pay.example.test/afristream-setup"`) — immediately after `</footer>`, before the closing `</div>`. Then add the script after `landing.js`:

```html
<script src="/assets/onboarding.js"></script>
```

- [ ] **Step 9: Write `tests/php/test-onboarding.php`**

```php
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
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 499 );
	update_option( AFRISTREAM_LANDING_CTA_OPTION, 'https://pay.example.test/afristream' );
	update_option( AFRISTREAM_LANDING_SETUP_CTA_OPTION, 'https://pay.example.test/afristream-setup' );

	$root = af_ob_root();
	af_assert_same( '1799', $root->getAttribute( 'data-price' ), 'data-price' );
	af_assert_same( '499', $root->getAttribute( 'data-setup-fee' ), 'data-setup-fee' );
	af_assert_same( 'https://pay.example.test/afristream', $root->getAttribute( 'data-cta' ), 'data-cta' );
	af_assert_same( 'https://pay.example.test/afristream-setup', $root->getAttribute( 'data-setup-cta' ), 'data-setup-cta' );
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
		'https://pay.example.test/afristream',
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
	af_assert_same( 0, ( new DOMXPath( af_ob_root()->ownerDocument ) )->query( './/section', af_ob_root() )->length, 'no sections inside the modal' );
} );
```

- [ ] **Step 10: Add the new scripts to the lint list**

In `package.json`, extend the `lint` script with `&& node --check assets/onboarding.js` after the `assets/landing.js` check.

- [ ] **Step 11: Run everything**

```
npm run test:php
npx playwright test tests/onboarding.spec.js
npm run lint
```

Expected: PHP tests all passing (including the existing `test-landing.php` mirror parity — if the text-node comparison fails, the mirror and the PHP disagree on copy; fix the mirror). All eight onboarding Playwright tests passing. Lint clean.

- [ ] **Step 12: Commit**

```bash
git add includes/onboarding.php assets/onboarding.js assets/onboarding.css tests/onboarding.spec.js tests/php/test-onboarding.php includes/landing.php preview/landing.html bluegroup-project-afristream.php package.json
git commit -m "feat: open an onboarding modal from every Get Started button"
```

---

## Task 2: One source for the savings maths

No issue of its own — groundwork for step 3, and a fix for a live defect: `assets/landing.js` hardcodes `AFRISTREAM_PRICE = 1599`, so an install that changes the annual price gets a calculator quoting savings against the old one.

**Files:**
- Create: `assets/savings.js`
- Modify: `assets/landing.js` (lines 50–97, the calculator block)
- Modify: `includes/landing.php` (`data-price` on the calculator section)
- Modify: `preview/landing.html`
- Modify: `package.json` (lint list)
- Test: `tests/landing.spec.js` (add one test)

**Interfaces:**
- Produces: `window.AfriStreamSavings` with `money(n) -> string`, `total(scope, other) -> number`, `saving(spend, price) -> number`, `chosen(scope) -> Element[]`. `scope` is any element containing `[data-sub-price]` chips; only those with `aria-pressed="true"` count. Task 3 and Task 5 consume all four.

- [ ] **Step 1: Write the failing test**

Append to `tests/landing.spec.js`:

```js
test('the calculator quotes savings against the configured price, not a hardcoded one', async ({ page }) => {
  // The price is a setting. The mirror advertises R1599; changing the attribute
  // the way a differently configured install would must move the arithmetic.
  await page.getByTestId('landing-calculator').evaluate((el) => el.setAttribute('data-price', '2000'));
  await page.getByTestId('landing-calculator').getByRole('button', { name: /Netflix Premium/ }).click();

  // Netflix Premium is R2748 a year: R748 left after a R2000 subscription.
  const saving = await digits(page.getByTestId('calc-saving'));
  expect(saving).toBe('748');
});
```

- [ ] **Step 2: Run it and watch it fail**

```
npx playwright test tests/landing.spec.js -g "configured price"
```

Expected: FAIL — the saving reads `1149`, computed against the hardcoded `1599`.

- [ ] **Step 3: Create `assets/savings.js`**

```js
/* The savings arithmetic, shared by the landing page's calculator and the
   onboarding flow's breakdown. Kept in one place because the two show the same
   number to the same customer minutes apart, and a disagreement between them
   is worse than either being wrong alone. */
window.AfriStreamSavings = (function () {
  'use strict';

  // en-ZA groups thousands the way the rest of the page's prices read.
  function money(n) {
    return 'R' + Math.round(n).toLocaleString('en-ZA');
  }

  /** The pressed subscription chips inside a container. */
  function chosen(scope) {
    return Array.prototype.slice.call(
      scope.querySelectorAll('[data-sub-price][aria-pressed="true"]')
    );
  }

  /** What the customer spends a year: the pressed chips, plus any free amount. */
  function total(scope, other) {
    return chosen(scope).reduce(function (sum, el) {
      return sum + (Number(el.getAttribute('data-sub-price')) || 0);
    }, Number(other) || 0);
  }

  /** Never negative: someone spending less than AfriStream costs saves nothing,
      they do not owe the difference. */
  function saving(spend, price) {
    return Math.max(0, spend - price);
  }

  return { money: money, chosen: chosen, total: total, saving: saving };
})();
```

- [ ] **Step 4: Put the price on the calculator in `includes/landing.php`**

In `afristream_landing_calculator()`, add the attribute to the section element:

```php
<section id="calculate" class="as-sec as-sec-calc" data-testid="landing-calculator" data-price="' . (int) afristream_landing_price() . '">
```

- [ ] **Step 5: Rewrite the calculator block in `assets/landing.js`**

Replace lines 50–97 (from `var calc = …` to the closing `}` of the `if (calc)` block) with:

```js
  var calc = root.querySelector('[data-testid="landing-calculator"]');

  if (calc) {
    // The price is a setting, so it comes off the DOM rather than being
    // repeated here. The fallback is the plugin's own default, for the case
    // where an older cached page has no attribute to read.
    var price = Number(calc.getAttribute('data-price')) || 1599;
    var sums = window.AfriStreamSavings;
    var subs = Array.prototype.slice.call(calc.querySelectorAll('[data-sub-price]'));
    var other = calc.querySelector('[data-testid="calc-other"]');
    var savingEl = calc.querySelector('[data-testid="calc-saving"]');
    var totalEl = calc.querySelector('[data-testid="calc-total"]');
    var basisEl = calc.querySelector('[data-testid="calc-basis"]');

    function recalc() {
      var otherAmount = Number(other && other.value) || 0;
      var total = sums.total(calc, otherAmount);
      var chosen = sums.chosen(calc).length;

      savingEl.textContent = sums.money(sums.saving(total, price));
      totalEl.textContent = sums.money(total) + ' / year';
      // No chips pressed but an "other" figure entered is still a non-zero
      // saving — "your selection below" would read as if nothing had been
      // chosen at all, right beside a number that says otherwise.
      basisEl.textContent = chosen === 0
        ? (otherAmount > 0 ? 'the other amount entered below' : 'your selection below')
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

Also update the file's opening comment: the calculator's maths now lives in `savings.js`.

- [ ] **Step 6: Register and enqueue it in `includes/landing.php`**

`savings.js` must load before both consumers. Register it, then make it a dependency of the other two scripts — in `afristream_landing_register_assets()`, before the `afristream-landing` script registration:

```php
	wp_register_script(
		'afristream-savings',
		plugins_url( 'assets/savings.js', dirname( __DIR__ ) . '/bluegroup-project-afristream.php' ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
```

Then change the `afristream-landing` and `afristream-onboarding` script registrations' `array()` dependency argument to `array( 'afristream-savings' )`.

- [ ] **Step 7: Mirror it**

In `preview/landing.html`: add `data-price="1599"` to the calculator section, and add the script *before* `landing.js`:

```html
<script src="/assets/savings.js"></script>
```

- [ ] **Step 8: Add it to the lint list**

`&& node --check assets/savings.js` in `package.json`.

- [ ] **Step 9: Run the tests**

```
npx playwright test tests/landing.spec.js
npm run test:php
npm run lint
```

Expected: all passing, including the pre-existing calculator tests — the arithmetic is unchanged for the mirror's R1599.

- [ ] **Step 10: Commit**

```bash
git add assets/savings.js assets/landing.js includes/landing.php preview/landing.html package.json tests/landing.spec.js
git commit -m "fix: quote calculator savings against the configured price"
```

---

## Task 3: The device and subscription questions

Closes #23 and #24. The step machine, the progress counter, and the two questions that shape everything after them.

**Files:**
- Modify: `includes/onboarding.php` (two step panels)
- Modify: `assets/onboarding.js` (the step machine)
- Modify: `assets/onboarding.css` (choices and chips)
- Modify: `preview/landing.html`
- Modify: `tests/onboarding.spec.js`
- Modify: `tests/php/test-landing.php` (the `data-sub-price` marker count)

**Interfaces:**
- Consumes: `window.AfriStreamSavings` from Task 2; the markup contract's `state` and `steps()`.
- Produces: `state.device` (`'yes'` / `'no'` / `null`), `state.subs` (number), `state.other` (number) — read by Tasks 4 and 5. `AFRISTREAM_LANDING_SUBS` chips rendered inside `[data-ob-step="subs"]`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/onboarding.spec.js`:

```js
const open = async (page) => {
  await page.getByTestId('header-cta').click();
  await expect(page.getByTestId('onboarding')).toBeVisible();
};

const step = (page, name) => page.locator(`[data-ob-step="${name}"]`);
const next = (page) => page.getByTestId('ob-next');
const back = (page) => page.getByTestId('ob-back');

test('continue moves from the intro to the device question', async ({ page }) => {
  await open(page);
  await next(page).click();

  await expect(step(page, 'intro')).toBeHidden();
  await expect(step(page, 'device')).toBeVisible();
  await expect(page.getByTestId('ob-title')).toHaveText('Do you already have a streaming device?');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 5');
});

test('the device question must be answered before continuing', async ({ page }) => {
  await open(page);
  await next(page).click();

  await expect(next(page)).toBeDisabled();
  await page.getByTestId('ob-device-yes').check();
  await expect(next(page)).toBeEnabled();
});

test('someone who has a device is never counted a step for the device offer', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
});

test('back returns to the previous step with the answer still selected', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();
  await next(page).click();

  await expect(step(page, 'subs')).toBeVisible();
  await back(page).click();
  await expect(step(page, 'device')).toBeVisible();
  await expect(page.getByTestId('ob-device-no')).toBeChecked();
});

test('back is not offered on the first step', async ({ page }) => {
  await open(page);
  await expect(back(page)).toBeHidden();
});

test('the subscription step is skippable and reports a running total', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();

  await expect(step(page, 'subs')).toBeVisible();
  await expect(next(page), 'nobody should be blocked from buying by an optional question').toBeEnabled();

  // Netflix Premium R2748 + Showmax + Premier League R1800.
  await step(page, 'subs').getByRole('button', { name: /Netflix Premium/ }).click();
  await step(page, 'subs').getByRole('button', { name: /Showmax \+ Premier League/ }).click();
  expect(await digits(page.getByTestId('ob-subs-total'))).toBe('4548');

  await page.getByTestId('ob-subs-other').fill('1000');
  expect(await digits(page.getByTestId('ob-subs-total'))).toBe('5548');
});

test('reopening the flow starts it over', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.keyboard.press('Escape');
  await open(page);

  await expect(step(page, 'intro')).toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 5');
});
```

`digits` is defined in `tests/landing.spec.js`; add a local copy at the top of `tests/onboarding.spec.js`:

```js
// en-ZA groups thousands with a non-breaking space, so assert on digits.
const digits = async (locator) => (await locator.innerText()).replace(/[^\d]/g, '');
```

- [ ] **Step 2: Run them and watch them fail**

```
npx playwright test tests/onboarding.spec.js
```

Expected: the seven new tests fail — there is no device step and `data-ob-next` does nothing.

- [ ] **Step 3: Add the two step panels to `includes/onboarding.php`**

Add a chip renderer and the panels. Insert after the intro step's closing `</div>`:

```php
    <div class="as-ob-step" data-ob-step="device" hidden>
      <div class="as-ob-choice" role="radiogroup" aria-label="Do you already have a streaming device?">
        <label class="as-ob-opt"><input type="radio" name="as-ob-device" value="yes" data-testid="ob-device-yes"><span><strong>Yes, I have one</strong>A smart TV, a FireStick, an Android box or similar.</span></label>
        <label class="as-ob-opt"><input type="radio" name="as-ob-device" value="no" data-testid="ob-device-no"><span><strong>No, I need one</strong>We can sort that out for you in a moment.</span></label>
      </div>
    </div>
    <div class="as-ob-step" data-ob-step="subs" hidden>
      <p class="as-ob-lede">Tick whatever you pay for today and we will show you what AfriStream saves you. Skip this if you would rather not.</p>
      <div class="as-ob-subs">' . afristream_onboarding_subs() . '</div>
      <div class="as-ob-other">
        <label for="as-ob-other">Anything else, per year?</label>
        <div class="as-ob-input"><span aria-hidden="true">R</span><input id="as-ob-other" data-testid="ob-subs-other" type="number" min="0" step="1" placeholder="0" inputmode="numeric"></div>
      </div>
      <p class="as-ob-running">You spend <span data-testid="ob-subs-total">R0</span> a year</p>
    </div>
```

And the renderer, above `afristream_onboarding_modal()`:

```php
/**
 * The subscription chips, from the same list the page's calculator uses.
 *
 * Same markup and the same data-sub-price attribute, so assets/savings.js adds
 * both up with one function. A second copy of sixteen annual prices is exactly
 * the kind of thing that goes stale in one place and not the other.
 */
function afristream_onboarding_subs() {
	$out = '';
	foreach ( AFRISTREAM_LANDING_SUBS as $sub ) {
		$out .= '
        <button type="button" class="as-sub" data-sub-price="' . (int) $sub[1] . '" aria-pressed="false">
          <span class="as-sub-box" aria-hidden="true"></span>
          <span class="as-sub-text"><span class="as-sub-name">' . esc_html( $sub[0] ) . '</span> <span class="as-sub-price">R' . (int) $sub[1] . '/yr</span></span>
        </button>';
	}
	return $out;
}
```

- [ ] **Step 4: Add the step machine to `assets/onboarding.js`**

Inside the IIFE, after the `panel` and `opener` declarations, add:

```js
  var titleEl = root.querySelector('[data-testid="ob-title"]');
  var progressEl = root.querySelector('[data-testid="ob-progress"]');
  var nextBtn = root.querySelector('[data-ob-next]');
  var backBtn = root.querySelector('[data-ob-back]');
  var sums = window.AfriStreamSavings;

  var fee = Number(root.getAttribute('data-setup-fee')) || 0;

  var TITLES = {
    intro: 'What you get with AfriStream',
    device: 'Do you already have a streaming device?',
    subs: 'What do you pay for today?',
    offer: 'Shall we sort the device out for you?',
    breakdown: 'Here is what that comes to'
  };

  var state = { device: null, subs: 0, other: 0, wantsDevice: null };
  var at = 0;

  /* The steps this customer will actually see. Someone with a device is never
     offered one, and an install that has not priced the fee is not selling it —
     so the counter must not promise a step the flow then skips. */
  function steps() {
    var list = ['intro', 'device', 'subs'];
    if (state.device !== 'yes' && fee > 0) list.push('offer');
    list.push('breakdown');
    return list;
  }

  /* Whether the current step has been answered well enough to move on. The
     subscriptions question is deliberately not on this list: it improves the
     breakdown, it does not gate the purchase. */
  function ready(name) {
    if (name === 'device') return state.device !== null;
    if (name === 'offer') return state.wantsDevice !== null;
    return true;
  }

  function render() {
    var list = steps();
    var name = list[at];

    Array.prototype.slice.call(root.querySelectorAll('[data-ob-step]')).forEach(function (el) {
      el.hidden = el.getAttribute('data-ob-step') !== name;
    });

    titleEl.textContent = TITLES[name];
    progressEl.textContent = 'Step ' + (at + 1) + ' of ' + list.length;
    backBtn.hidden = at === 0;
    nextBtn.hidden = name === 'breakdown';
    nextBtn.disabled = !ready(name);
  }

  function go(delta) {
    var list = steps();
    at = Math.min(Math.max(at + delta, 0), list.length - 1);
    render();
  }

  function reset() {
    state = { device: null, subs: 0, other: 0, wantsDevice: null };
    at = 0;
    Array.prototype.slice.call(root.querySelectorAll('input[type="radio"]')).forEach(function (el) {
      el.checked = false;
    });
    Array.prototype.slice.call(root.querySelectorAll('[data-sub-price]')).forEach(function (el) {
      el.setAttribute('aria-pressed', 'false');
    });
    var other = root.querySelector('[data-testid="ob-subs-other"]');
    if (other) other.value = '';
    recalcSubs();
    render();
  }

  var subsStep = root.querySelector('[data-ob-step="subs"]');
  var subsTotalEl = root.querySelector('[data-testid="ob-subs-total"]');
  var otherEl = root.querySelector('[data-testid="ob-subs-other"]');

  function recalcSubs() {
    if (!subsStep) return;
    state.other = Number(otherEl && otherEl.value) || 0;
    state.subs = sums.total(subsStep, state.other);
    if (subsTotalEl) subsTotalEl.textContent = sums.money(state.subs);
  }

  root.addEventListener('click', function (e) {
    var chip = e.target.closest('[data-sub-price]');
    if (chip) {
      chip.setAttribute('aria-pressed', chip.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
      recalcSubs();
      return;
    }
    if (e.target.closest('[data-ob-next]')) go(1);
    if (e.target.closest('[data-ob-back]')) go(-1);
  });

  root.addEventListener('change', function (e) {
    if (e.target.name === 'as-ob-device') state.device = e.target.value;
    if (e.target.name === 'as-ob-offer') state.wantsDevice = e.target.value;
    render();
  });

  if (otherEl) otherEl.addEventListener('input', recalcSubs);
```

In `open()`, call `reset()` before `panel.focus()` — a reopened flow must not show the last visitor's answers:

```js
  function open(from) {
    opener = from || null;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    reset();
    panel.focus();
  }
```

Then call `render()` once at the end of the IIFE.

- [ ] **Step 5: Add the step styles to `assets/onboarding.css`**

```css
.as-ob-choice { display: grid; gap: 10px; }

.as-ob-opt {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 14px;
  border: 1px solid rgba(255, 255, 255, .14);
  border-radius: 12px;
  cursor: pointer;
}

.as-ob-opt:hover { border-color: rgba(205, 45, 245, .5); }

.as-ob-opt:has(input:checked) {
  border-color: #cd2df5;
  background: rgba(205, 45, 245, .1);
}

.as-ob-opt input { margin-top: 3px; accent-color: #cd2df5; }

.as-ob-opt span { display: grid; gap: 3px; font-size: 14px; color: #b9b2cc; }
.as-ob-opt strong { font-size: 15px; color: #f4f1fa; }

.as-ob-subs {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 8px;
  max-height: 240px;
  overflow-y: auto;
  padding-right: 4px;
}

.as-ob-other { display: flex; align-items: center; gap: 10px; margin-top: 14px; }
.as-ob-other label { font-size: 14px; color: #b9b2cc; }

.as-ob-input {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 7px 10px;
  border: 1px solid rgba(255, 255, 255, .16);
  border-radius: 9px;
}

.as-ob-input input {
  width: 90px;
  border: 0;
  background: transparent;
  color: #f4f1fa;
  font: inherit;
}

.as-ob-input input:focus { outline: none; }

.as-ob-running { margin: 12px 0 0; font-size: 14px; color: #b9b2cc; }
.as-ob-running span { color: #f4f1fa; font-weight: 600; }

.as-ob-nav button[disabled] { opacity: .45; cursor: not-allowed; }
```

`:has()` is supported across the browsers Playwright runs and every browser this page targets; the fallback is simply an unhighlighted-but-working radio.

- [ ] **Step 6: Mirror the two panels in `preview/landing.html`**

Paste the rendered form of both new steps, including all sixteen chips with their `data-sub-price` values, matching the calculator's list exactly.

- [ ] **Step 7: Update the marker count in `tests/php/test-landing.php`**

The modal now carries a second set of chips. In the `marker counts match the preview mirror` test:

```php
	af_assert_same( 32, af_landing_xpath_count( $root, './/*[@data-sub-price]' ), 'subscription options: sixteen in the calculator, sixteen in the onboarding flow' );
```

- [ ] **Step 8: Run the tests**

```
npm run test:php
npx playwright test tests/onboarding.spec.js
```

Expected: all passing. If `the sixteen calculator subscription prices match the preview mirror in order` fails, the mirror's modal chips are in a different order from the PHP's — that test now compares all thirty-two.

- [ ] **Step 9: Commit**

```bash
git add includes/onboarding.php assets/onboarding.js assets/onboarding.css preview/landing.html tests/onboarding.spec.js tests/php/test-landing.php
git commit -m "feat: ask about the customer's device and subscriptions"
```

---

## Task 4: The device offer

Closes #25. The FireStick offer, shown only to a customer without a device on an install that has priced the fee.

**Files:**
- Modify: `includes/onboarding.php` (the offer panel)
- Modify: `preview/landing.html`
- Modify: `tests/onboarding.spec.js`
- Modify: `tests/php/test-onboarding.php`

**Interfaces:**
- Consumes: `state.device` and `steps()` from Task 3 — the machine already skips `offer` when `state.device === 'yes'` or the fee is zero. This task only adds the panel and its radios.
- Produces: `state.wantsDevice` (`'yes'` / `'no'` / `null`), read by Task 5.

- [ ] **Step 1: Write the failing tests**

Append to `tests/onboarding.spec.js`:

```js
const toOffer = async (page) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();
  await next(page).click();
  await next(page).click();
};

test('someone without a device is offered one, priced at the setup fee', async ({ page }) => {
  await toOffer(page);

  await expect(step(page, 'offer')).toBeVisible();
  await expect(step(page, 'offer')).toContainText('FireStick');
  await expect(step(page, 'offer')).toContainText('R499');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 4 of 5');
});

test('someone who has a device never sees the offer', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(step(page, 'offer')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 4 of 4');
});

test('the offer must be answered before continuing', async ({ page }) => {
  await toOffer(page);
  await expect(next(page)).toBeDisabled();
  await page.getByTestId('ob-offer-no').check();
  await expect(next(page)).toBeEnabled();
});

test('changing the device answer after seeing the offer drops the step', async ({ page }) => {
  // Going back and answering "yes" must not leave a device-offer step stranded
  // in the flow, nor a count that promises one.
  await toOffer(page);
  await back(page).click();
  await back(page).click();
  await page.getByTestId('ob-device-yes').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
  await next(page).click();
  await next(page).click();
  await expect(step(page, 'breakdown')).toBeVisible();
});

test('an install with no setup fee offers no device at all', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => el.setAttribute('data-setup-fee', '0'));
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
  await next(page).click();
  await next(page).click();
  await expect(step(page, 'offer')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
});

test('the setup card opens the flow with the device answered for them', async ({ page }) => {
  await page.getByTestId('plan-setup-cta').click();
  await expect(page.getByTestId('onboarding')).toBeVisible();

  await expect(step(page, 'subs'), 'the two questions it already answers are skipped').toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 3 of 5');
  await next(page).click();
  await expect(page.getByTestId('ob-offer-yes')).toBeChecked();
});
```

Note the `data-setup-fee` test reads the attribute at open time, so `fee` must be read inside `reset()` rather than once at load.

- [ ] **Step 2: Run them and watch them fail**

```
npx playwright test tests/onboarding.spec.js
```

Expected: the six new tests fail — there is no offer panel.

- [ ] **Step 3: Add the offer panel to `includes/onboarding.php`**

Insert after the subs step's closing `</div>`:

```php
    <div class="as-ob-step" data-ob-step="offer" hidden>
      <p class="as-ob-lede">We will send you a FireStick with AfriStream already installed and set up, for a one-off R' . (int) afristream_landing_setup_fee() . '. Plug it in and it works.</p>
      <div class="as-ob-choice" role="radiogroup" aria-label="Shall we sort the device out for you?">
        <label class="as-ob-opt"><input type="radio" name="as-ob-offer" value="yes" data-testid="ob-offer-yes"><span><strong>Yes, send me a FireStick</strong>R' . (int) afristream_landing_setup_fee() . ' once off, added to this order.</span></label>
        <label class="as-ob-opt"><input type="radio" name="as-ob-offer" value="no" data-testid="ob-offer-no"><span><strong>No thanks, I will sort my own</strong>Just the subscription. Our setup guides will walk you through it.</span></label>
      </div>
    </div>
```

- [ ] **Step 4: Read the fee per open, and honour the setup card's preset**

In `assets/onboarding.js`, change the `fee` declaration from a one-time read to a variable set in `reset()`:

```js
  var fee = 0;
```

and inside `reset()`, before `recalcSubs()`:

```js
    // Read per open, not once at load: a test — and a cached page whose
    // settings have since changed — can move the fee under us.
    fee = Number(root.getAttribute('data-setup-fee')) || 0;
```

Give `reset()` a preset argument, and use it in `open()`:

```js
  function reset(preset) {
    state = { device: null, subs: 0, other: 0, wantsDevice: null };
    at = 0;
    // …existing clearing code…
    fee = Number(root.getAttribute('data-setup-fee')) || 0;

    /* The "Get Started with Setup" button is an answer to the first two
       questions, so asking them again would be the page forgetting what it
       was just told. The offer is pre-ticked but still shown — being sent
       to a more expensive checkout without confirming it is not on. */
    if (preset === 'setup' && fee > 0) {
      state.device = 'no';
      state.wantsDevice = 'yes';
      var deviceNo = root.querySelector('[data-testid="ob-device-no"]');
      var offerYes = root.querySelector('[data-testid="ob-offer-yes"]');
      if (deviceNo) deviceNo.checked = true;
      if (offerYes) offerYes.checked = true;
      at = steps().indexOf('subs');
    }

    recalcSubs();
    render();
  }
```

and in `open()`:

```js
  function open(from) {
    opener = from || null;
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    reset(from ? from.getAttribute('data-onboard') : '');
    panel.focus();
  }
```

- [ ] **Step 5: Mirror the offer panel in `preview/landing.html`**

Paste the rendered panel — the mirror's fee is `499`, so both prices read `R499`.

- [ ] **Step 6: Add a PHP test for the fee copy**

Append to `tests/php/test-onboarding.php`:

```php
af_test( 'the device offer quotes the configured fee in both places it names a price', function () {
	update_option( AFRISTREAM_LANDING_SETUP_FEE_OPTION, 750 );

	$offer = ( new DOMXPath( af_ob_root()->ownerDocument ) )->query( './/*[@data-ob-step="offer"]', af_ob_root() );
	af_assert_same( 1, $offer->length, 'one offer panel' );
	af_assert_same( 2, substr_count( $offer->item( 0 )->textContent, 'R750' ), 'the lede and the yes option both quote the fee' );
} );
```

- [ ] **Step 7: Run the tests**

```
npm run test:php
npx playwright test tests/onboarding.spec.js
```

Expected: all passing.

- [ ] **Step 8: Commit**

```bash
git add includes/onboarding.php assets/onboarding.js preview/landing.html tests/onboarding.spec.js tests/php/test-onboarding.php
git commit -m "feat: offer a FireStick to customers without a device"
```

---

## Task 5: The breakdown and the route to checkout

Closes #26 and #27. The last screen: what you get, what it costs, what you save — and the button that opens the matching checkout.

**Files:**
- Modify: `includes/onboarding.php` (the breakdown panel)
- Modify: `assets/onboarding.js` (fill it in, route the button)
- Modify: `assets/onboarding.css`
- Modify: `preview/landing.html`
- Modify: `tests/onboarding.spec.js`

**Interfaces:**
- Consumes: `state.subs`, `state.other`, `state.wantsDevice`, `fee`, `sums` — all from Tasks 2–4.
- Produces: the flow's only output — `[data-testid="ob-checkout"]`'s `href`, set to `data-setup-cta` when the customer took the device and `data-cta` otherwise.

- [ ] **Step 1: Write the failing tests**

Append to `tests/onboarding.spec.js`:

```js
const CHECKOUT = 'https://pay.example.test/afristream-setup';

test('taking the device puts the fee on the bill and routes to the setup checkout', async ({ page }) => {
  await toOffer(page);
  await page.getByTestId('ob-offer-yes').check();
  await next(page).click();

  const bill = page.getByTestId('ob-cost');
  await expect(bill).toContainText('R1599');
  await expect(bill).toContainText('R499');
  await expect(page.getByTestId('ob-total')).toHaveText(/R\s*2\D?098/);
  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', CHECKOUT);
});

test('declining the device leaves the fee off the bill and off the checkout', async ({ page }) => {
  await toOffer(page);
  await page.getByTestId('ob-offer-no').check();
  await next(page).click();

  await expect(page.getByTestId('ob-cost')).not.toContainText('R499');
  await expect(page.getByTestId('ob-total')).toHaveText(/R\s*1\D?599/);
  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#pricing');
});

test('someone who has a device goes to the plain checkout', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#pricing');
});

test('the savings section reflects the subscriptions they ticked', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();

  // Netflix Premium R2748 + HBO Max R4968 = R7716, less R1599 = R6117.
  await step(page, 'subs').getByRole('button', { name: /Netflix Premium/ }).click();
  await step(page, 'subs').getByRole('button', { name: /HBO Max/ }).click();
  await next(page).click();

  expect(await digits(page.getByTestId('ob-saving'))).toBe('6117');
  await expect(page.getByTestId('ob-savings')).toContainText('R7716');
});

test('someone who ticked nothing sees no invented saving', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(page.getByTestId('ob-savings'))
    .toContainText('Tell us what you pay for today');
  await expect(page.getByTestId('ob-saving')).toHaveCount(0);
});

test('the breakdown agrees with the page calculator for the same selection', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await calc.getByRole('button', { name: /Disney Plus Premium/ }).click();
  const fromCalc = await digits(calc.getByTestId('calc-saving'));

  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await step(page, 'subs').getByRole('button', { name: /Disney Plus Premium/ }).click();
  await next(page).click();

  expect(await digits(page.getByTestId('ob-saving'))).toBe(fromCalc);
});

test('the checkout button is the last step, with no Continue beside it', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(next(page)).toBeHidden();
  await expect(back(page)).toBeVisible();
  await expect(page.getByTestId('ob-checkout')).toBeVisible();
});
```

- [ ] **Step 2: Run them and watch them fail**

```
npx playwright test tests/onboarding.spec.js
```

Expected: the seven new tests fail — there is no breakdown panel.

- [ ] **Step 3: Add the breakdown panel to `includes/onboarding.php`**

Insert after the offer step's closing `</div>`. The numbers are left to JS; PHP renders the skeleton and the static copy.

```php
    <div class="as-ob-step" data-ob-step="breakdown" hidden>
      <div class="as-ob-block">
        <span class="as-ob-block-h">What you get</span>
        <ul class="as-ob-list">' . $includes . '</ul>
      </div>
      <div class="as-ob-block">
        <span class="as-ob-block-h">What it costs</span>
        <ul class="as-ob-bill" data-testid="ob-cost"></ul>
        <p class="as-ob-total">Total today <span data-testid="ob-total">R0</span></p>
      </div>
      <div class="as-ob-block as-ob-block-save" data-testid="ob-savings"></div>
      <a class="as-btn as-btn-primary as-ob-checkout" data-testid="ob-checkout" href="' . esc_url( afristream_landing_cta_url() ) . '">Continue to checkout</a>
    </div>
```

The `$includes` variable is the same list built at the top of `afristream_onboarding_modal()` for the intro step — reuse it rather than rebuilding it.

- [ ] **Step 4: Fill it in from `assets/onboarding.js`**

Add these element handles beside the others:

```js
  var costEl = root.querySelector('[data-testid="ob-cost"]');
  var totalEl = root.querySelector('[data-testid="ob-total"]');
  var savingsEl = root.querySelector('[data-testid="ob-savings"]');
  var checkoutEl = root.querySelector('[data-testid="ob-checkout"]');
  var price = Number(root.getAttribute('data-price')) || 0;
```

And a function that rebuilds the breakdown, called from `render()` when the current step is `breakdown`:

```js
  function line(label, amount) {
    return '<li><span>' + label + '</span><span>' + sums.money(amount) + '</span></li>';
  }

  function breakdown() {
    var takesDevice = state.wantsDevice === 'yes' && state.device !== 'yes' && fee > 0;
    var total = price + (takesDevice ? fee : 0);

    costEl.innerHTML = line('AfriStream, one year', price)
      + (takesDevice ? line('FireStick, set up and delivered — once off', fee) : '');
    totalEl.textContent = sums.money(total);

    /* No subscriptions ticked means no saving to state. Printing "you save R0"
       under a heading about savings reads as a promise the product failed to
       keep, when in fact the customer simply skipped the question. */
    if (state.subs > 0) {
      savingsEl.innerHTML = '<span class="as-ob-block-h">What you save</span>'
        + '<p class="as-ob-save-figure" data-testid="ob-saving">' + sums.money(sums.saving(state.subs, price)) + '</p>'
        + '<p class="as-ob-save-basis">a year, against the ' + sums.money(state.subs) + ' you spend today</p>';
    } else {
      savingsEl.innerHTML = '<span class="as-ob-block-h">What you save</span>'
        + '<p class="as-ob-save-basis">Tell us what you pay for today and we will work it out — go back a step whenever you like.</p>';
    }

    // The flow's whole output: which checkout this customer belongs at.
    checkoutEl.setAttribute(
      'href',
      takesDevice ? root.getAttribute('data-setup-cta') : root.getAttribute('data-cta')
    );
  }
```

In `render()`, after the step visibility loop:

```js
    if (name === 'breakdown') breakdown();
```

Also read `price` per open, alongside `fee`, inside `reset()`.

- [ ] **Step 5: Style it**

Append to `assets/onboarding.css`:

```css
.as-ob-block { display: grid; gap: 8px; }

.as-ob-block + .as-ob-block { margin-top: 18px; }

.as-ob-block-h {
  font-family: 'Space Mono', monospace;
  font-size: 11px;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #8f88a3;
}

.as-ob-bill { margin: 0; padding: 0; list-style: none; display: grid; gap: 7px; }

.as-ob-bill li {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  font-size: 15px;
  color: #d8d3e6;
}

.as-ob-total {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  margin: 10px 0 0;
  padding-top: 10px;
  border-top: 1px solid rgba(255, 255, 255, .12);
  font-weight: 600;
}

.as-ob-block-save {
  padding: 14px;
  border: 1px solid rgba(205, 45, 245, .3);
  border-radius: 12px;
  background: rgba(205, 45, 245, .08);
}

.as-ob-save-figure { margin: 0; font-size: 30px; font-weight: 600; color: #cd2df5; }
.as-ob-save-basis { margin: 0; font-size: 14px; color: #b9b2cc; }

.as-ob-checkout { margin-top: 20px; text-align: center; }
```

- [ ] **Step 6: Mirror the breakdown panel**

Paste it into `preview/landing.html`, with `href="#pricing"` on the checkout link (the mirror's unset Get Started URL) and the two dynamic containers empty, exactly as PHP renders them.

- [ ] **Step 7: Run the whole suite**

```
npm run test:php
npx playwright test
npm run lint
```

Expected: all passing, including `tests/landing.spec.js` — the calculator is untouched by this task.

- [ ] **Step 8: Commit**

```bash
git add includes/onboarding.php assets/onboarding.js assets/onboarding.css preview/landing.html tests/onboarding.spec.js
git commit -m "feat: show the bill and send the customer to the matching checkout"
```

---

## Task 6: Version, changelog and a last look

**Files:**
- Modify: `bluegroup-project-afristream.php` (plugin header version, `AFRISTREAM_PORTAL_VERSION`)
- Modify: `package.json` (version)
- Modify: `CHANGELOG.md`
- Modify: `README.md` (only if it describes the Get Started buttons' behaviour)

- [ ] **Step 1: Bump the version**

A feature, so a minor bump: `0.27.0` → `0.28.0`. Three places must agree — the plugin header comment's `Version:`, the `AFRISTREAM_PORTAL_VERSION` constant below it, and `package.json`.

- [ ] **Step 2: Write the changelog entry**

Add a `## 0.28.0` section at the top of `CHANGELOG.md`, in the voice the existing entries use — what changed, and why it was worth changing. Cover: the flow behind every Get Started button; the device and subscription questions; the FireStick offer and when it is withheld; the breakdown and the two checkout destinations; and the calculator's price fix from Task 2.

- [ ] **Step 3: Check the README**

`README.md:29` describes the Get Started URL setting. Add a sentence that the buttons now open the onboarding flow, which chooses between the two configured checkouts, and that the URLs are still what it ends at.

- [ ] **Step 4: Run everything one last time**

```
npm run test:php
npx playwright test
npm run lint
```

Expected: green across all three. Present any lint findings to Luke rather than fixing them — the project rule is that lint is reported once at the end and actioned only on approval.

- [ ] **Step 5: Commit and open the pull request**

```bash
git add bluegroup-project-afristream.php package.json CHANGELOG.md README.md
git commit -m "chore: release 0.28.0"
git push -u origin onboarding-flow
```

The PR targets `add-setup-fee-price-point`, not `main`, until that branch is merged — this work depends on the setup fee and the second checkout URL it introduced. Body should close #22, #23, #24, #25, #26 and #27.

- [ ] **Step 6: Build the deployment zip**

Per the project's WordPress deployment rule, and only after the version bump is committed:

```bash
npm run build
/c/Windows/System32/tar.exe -a -c -f ../bluegroup-project-afristream.zip -C dist bluegroup-project-afristream
unzip -l ../bluegroup-project-afristream.zip | head -20
```

Remove any older `bluegroup-project-afristream.zip` in the parent folder first. Confirm every entry in the listing reads `bluegroup-project-afristream/…` with forward slashes. Never use `Compress-Archive`.

---

## Notes for the implementer

- **The mirror is not optional.** If a PHP test named "the visible text matches the preview mirror" fails, you changed copy in one file and not the other. The failure prints both arrays; the first differing index is your answer.
- **`data-onboard` on six elements, five of them bare.** The count assertion in Task 1 is what catches a CTA that was missed.
- **`fee` and `price` are read per open**, not once at load. Two tests move those attributes at runtime to simulate a differently configured install; reading them at load makes those tests pass for the wrong reason or fail for a confusing one.
- **Nothing is persisted.** If you find yourself writing a `fetch`, a nonce or an option, re-read the spec — that was decided against.
