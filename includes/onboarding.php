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
 * The intro step's summary of what the subscription actually buys.
 *
 * Setup and discovery only. Nothing on this list is content, a channel or a
 * subscription to somebody else's service — the customer keeps and pays for
 * those directly with the providers, and this is the first screen where that
 * has to be unambiguous.
 */
const AFRISTREAM_ONBOARDING_INCLUDES = array(
	'Guided setup for your device, with a real person',
	'Written step-by-step guides for every device we cover',
	'Search once to see which of your services is carrying a title',
	'The AfriStream customer portal',
	'Support by email when something stops working',
);

/**
 * What AfriStream is not, said once, on the first screen of the flow.
 */
const AFRISTREAM_ONBOARDING_DISCLAIMER = 'AfriStream does not host, stream, supply or resell any video, channel or subscription. You watch on your own accounts, in the providers’ own apps.';

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
      <span class="as-ob-progress" data-testid="ob-progress">Step 1 of 3</span>
      <button class="as-ob-x" type="button" data-ob-close aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
      </button>
    </div>
    <h2 class="as-ob-title" id="as-ob-title" data-testid="ob-title" tabindex="-1">What you get with AfriStream</h2>
    <div class="as-ob-step" data-ob-step="intro">
      <p class="as-ob-lede">One annual subscription of R' . (int) afristream_landing_price() . ', covering everything below. One quick question and we will show you what it comes to.</p>
      <ul class="as-ob-list">' . $includes . '</ul>
      <p class="as-ob-fine" data-testid="ob-disclaimer">' . esc_html( AFRISTREAM_ONBOARDING_DISCLAIMER ) . '</p>
    </div>
    <div class="as-ob-step" data-ob-step="device" hidden>
      <p class="as-ob-lede">If you have not got a streaming device yet, we can source one, set it up and update it before it reaches you, for a one-off R' . (int) afristream_landing_setup_fee() . '. Plug it in and it works.</p>
      <div class="as-ob-choice" role="radiogroup" aria-label="Shall we source a device for you?">
        <label class="as-ob-opt"><input type="radio" name="as-ob-device" value="yes" data-testid="ob-device-yes"><span><strong>Yes, source one for me</strong>R' . (int) afristream_landing_setup_fee() . ' once off, added to this order. No content comes with it.</span></label>
        <label class="as-ob-opt"><input type="radio" name="as-ob-device" value="no" data-testid="ob-device-no"><span><strong>No, I already have one</strong>A smart TV, a stick or a box. Our guides and our support will get it working.</span></label>
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
      <p class="as-ob-fine">' . esc_html( AFRISTREAM_ONBOARDING_DISCLAIMER ) . '</p>
    </div>
    <div class="as-ob-nav">
      <button class="as-btn as-btn-ghost" type="button" data-testid="ob-back" data-ob-back hidden>Back</button>
      <button class="as-btn as-btn-primary" type="button" data-testid="ob-next" data-ob-next>Continue</button>
      <a class="as-btn as-btn-primary as-ob-checkout" data-testid="ob-checkout" href="' . esc_url( afristream_landing_cta_url() ) . '" hidden>Continue to checkout</a>
    </div>
  </div>
</div>';
}
