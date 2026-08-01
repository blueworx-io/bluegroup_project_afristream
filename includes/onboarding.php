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
    <div class="as-ob-step" data-ob-step="offer" hidden>
      <p class="as-ob-lede">We will send you a FireStick with AfriStream already installed and set up, for a one-off R' . (int) afristream_landing_setup_fee() . '. Plug it in and it works.</p>
      <div class="as-ob-choice" role="radiogroup" aria-label="Shall we sort the device out for you?">
        <label class="as-ob-opt"><input type="radio" name="as-ob-offer" value="yes" data-testid="ob-offer-yes"><span><strong>Yes, send me a FireStick</strong>R' . (int) afristream_landing_setup_fee() . ' once off, added to this order.</span></label>
        <label class="as-ob-opt"><input type="radio" name="as-ob-offer" value="no" data-testid="ob-offer-no"><span><strong>No thanks, I will sort my own</strong>Just the subscription. Our setup guides will walk you through it.</span></label>
      </div>
    </div>
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
    <div class="as-ob-nav">
      <button class="as-btn as-btn-ghost" type="button" data-testid="ob-back" data-ob-back hidden>Back</button>
      <button class="as-btn as-btn-primary" type="button" data-testid="ob-next" data-ob-next>Continue</button>
    </div>
  </div>
</div>';
}
