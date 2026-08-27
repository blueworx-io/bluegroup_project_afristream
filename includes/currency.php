<?php
/**
 * Display currencies for the landing page.
 *
 * A shop window, not a till. SureCart still bills in Rand and carries its own
 * currency control on the checkout, so nothing here touches a checkout URL or
 * an amount that will be charged — every figure on the page is priced in Rand
 * by PHP and converted for display only. That is also why every converted
 * price carries a line saying what the customer is actually billed in: a
 * quoted price the checkout then contradicts is worse than no conversion.
 *
 * @package bluegroup-project-afristream
 */

defined( 'ABSPATH' ) || exit;

/** What everything is priced in, and what SureCart bills. */
const AFRISTREAM_CURRENCY_BASE = 'ZAR';

/**
 * The currencies offered, in the order the switcher lists them — by name,
 * matching the currency list configured in SureCart.
 *
 * `step` is what a converted figure rounds to. Đồng is quoted in thousands
 * wherever it is used, so an unrounded ₫2 318 550 reads as a machine's output
 * rather than as a price. `group` follows local convention: South African and
 * Namibian prices are written unbroken at this length, the others are not.
 */
const AFRISTREAM_CURRENCIES = array(
	'GBP' => array(
		'name'   => 'British Pound',
		'symbol' => '£',
		'step'   => 1,
		'group'  => true,
	),
	'NAD' => array(
		'name'   => 'Namibian Dollar',
		'symbol' => 'N$',
		'step'   => 1,
		'group'  => false,
	),
	'ZAR' => array(
		'name'   => 'South African Rand',
		'symbol' => 'R',
		'step'   => 1,
		'group'  => false,
	),
	'USD' => array(
		'name'   => 'United States Dollar',
		'symbol' => '$',
		'step'   => 1,
		'group'  => true,
	),
	'VND' => array(
		'name'   => 'Vietnamese Đồng',
		'symbol' => '₫',
		'step'   => 1000,
		'group'  => true,
	),
);

/**
 * Units per Rand, used until the daily feed has been reached and whenever it
 * cannot be.
 *
 * Deliberately baked in rather than left empty: a site whose first cron run
 * has not happened yet, or whose host blocks outbound requests entirely, must
 * still price the page. The Namibian Dollar is pegged one-for-one to the Rand,
 * so its entry is a fact rather than a snapshot and never moves.
 */
const AFRISTREAM_FX_FALLBACK = array(
	'GBP' => 0.043,
	'NAD' => 1.0,
	'ZAR' => 1.0,
	'USD' => 0.055,
	'VND' => 1420.0,
);

/** Where the fetched table is cached, and for how long. */
const AFRISTREAM_FX_TRANSIENT = 'afristream_fx_rates';

/**
 * Kept for two days against a one-day refresh, so a feed that is down for a
 * day leaves yesterday's rates in place instead of dropping the whole site
 * back to the baked-in ones.
 */
const AFRISTREAM_FX_TTL = 172800;

/** Free, keyless, and quotes all five. */
const AFRISTREAM_FX_ENDPOINT = 'https://open.er-api.com/v6/latest/ZAR';

/** The daily refresh hook. */
const AFRISTREAM_FX_CRON = 'afristream_fx_refresh';

/**
 * The rate table the front end reads.
 *
 * Never fetches. The refresh runs on cron, in its own request, because a
 * visitor whose page view happens to be the one that finds the cache empty
 * must not be the one who waits for a third-party API to answer.
 *
 * @return array<string, float>
 */
function afristream_fx_rates() {
	$cached = get_transient( AFRISTREAM_FX_TRANSIENT );
	return afristream_fx_valid( $cached ) ? $cached : AFRISTREAM_FX_FALLBACK;
}

/**
 * Whether a table can price every currency offered.
 *
 * All or nothing: a partial table would leave whatever it is missing quoted
 * at the Rand figure with a Dollar sign in front of it — silently wrong
 * rather than visibly broken.
 *
 * @param mixed $rates Candidate table.
 * @return bool
 */
function afristream_fx_valid( $rates ) {
	if ( ! is_array( $rates ) ) {
		return false;
	}
	foreach ( array_keys( AFRISTREAM_CURRENCIES ) as $code ) {
		if ( ! isset( $rates[ $code ] ) || ! is_numeric( $rates[ $code ] ) || $rates[ $code ] <= 0 ) {
			return false;
		}
	}
	return true;
}

/**
 * One call to the feed, reduced to the five currencies offered.
 *
 * @return array<string, float>|null Null on anything short of a complete table.
 */
function afristream_fx_fetch() {
	$response = wp_remote_get( AFRISTREAM_FX_ENDPOINT, array( 'timeout' => 8 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $json ) || empty( $json['rates'] ) || ! is_array( $json['rates'] ) ) {
		return null;
	}

	$rates = array();
	foreach ( array_keys( AFRISTREAM_CURRENCIES ) as $code ) {
		$rate = isset( $json['rates'][ $code ] ) ? (float) $json['rates'][ $code ] : 0.0;
		if ( $rate <= 0 ) {
			return null;
		}
		$rates[ $code ] = $rate;
	}
	return $rates;
}

/**
 * The cron callback. A failed fetch leaves the cached table alone rather than
 * overwriting good rates with nothing.
 */
function afristream_fx_refresh() {
	$rates = afristream_fx_fetch();
	if ( $rates ) {
		set_transient( AFRISTREAM_FX_TRANSIENT, $rates, AFRISTREAM_FX_TTL );
	}
}
add_action( AFRISTREAM_FX_CRON, 'afristream_fx_refresh' );

/**
 * Book the daily refresh once. Scheduled a minute out rather than immediately
 * so it never lands inside the request that booked it.
 */
function afristream_fx_schedule() {
	if ( ! wp_next_scheduled( AFRISTREAM_FX_CRON ) ) {
		wp_schedule_event( time() + 60, 'daily', AFRISTREAM_FX_CRON );
	}
}
add_action( 'init', 'afristream_fx_schedule' );

/**
 * A Rand price, as markup the switcher can rewrite in place.
 *
 * The Rand figure is printed as the element's text, not left for JavaScript to
 * fill: a visitor without it, and a search engine, both get a real price.
 *
 * @param int    $rand  The price in Rand.
 * @param string $class Optional class for the wrapping element.
 * @return string
 */
function afristream_price( $rand, $class = '' ) {
	$attrs = ' data-money="' . (int) $rand . '"';
	if ( '' !== $class ) {
		$attrs = ' class="' . esc_attr( $class ) . '"' . $attrs;
	}
	return '<span' . $attrs . '>R' . (int) $rand . '</span>';
}

/** Said wherever a converted price is shown, and hidden while Rand is showing. */
const AFRISTREAM_FX_NOTE = 'Converted from South African Rand at today’s rate. You are billed in Rand, and your bank sets the rate you actually pay.';

/**
 * The billed-in-Rand line. Hidden in the markup: Rand is what the page loads
 * showing, so the note starts off and the switcher turns it on.
 *
 * @param string $class Class for the element.
 * @return string
 */
function afristream_fx_note( $class = 'as-fine as-fx-note' ) {
	return '<p class="' . esc_attr( $class ) . '" data-fx-note hidden>' . esc_html( AFRISTREAM_FX_NOTE ) . '</p>';
}

/**
 * The switcher itself.
 *
 * A native select rather than a styled dropdown: it is one control in the
 * header and one in the mobile menu, and a native one is keyboard- and
 * screen-reader-correct for free, and opens as the OS picker on a phone.
 * Options are labelled by code and symbol rather than by full name — the full
 * names are twice the width of the header control they would have to sit in.
 *
 * @param string $context Where it is being rendered, for the test id and class.
 * @return string
 */
function afristream_currency_switcher( $context ) {
	$options = '';
	foreach ( AFRISTREAM_CURRENCIES as $code => $currency ) {
		$options .= '<option value="' . esc_attr( $code ) . '"'
			. ( AFRISTREAM_CURRENCY_BASE === $code ? ' selected' : '' )
			. '>' . esc_html( $code . ' (' . $currency['symbol'] . ')' ) . '</option>';
	}

	return '<div class="as-fx as-fx-' . esc_attr( $context ) . '" data-testid="currency-' . esc_attr( $context ) . '">'
		. '<select class="as-fx-select" data-fx-select aria-label="Show prices in">' . $options . '</select>'
		. '</div>';
}
