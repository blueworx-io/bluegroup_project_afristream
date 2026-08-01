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
