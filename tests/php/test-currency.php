<?php
/**
 * The display-currency layer: the rate table, the daily refresh, and the
 * markup the switcher rewrites.
 *
 * The thing worth protecting here is that none of it can change what a
 * customer is charged. Everything converts a Rand figure for display, the Rand
 * figure stays in the markup, and the checkout is never touched.
 */

if ( ! defined( 'AFRISTREAM_PORTAL_VERSION' ) ) {
	define( 'AFRISTREAM_PORTAL_VERSION', '0.24.0' );
}
require_once __DIR__ . '/../../includes/currency.php';

/** A complete, obviously-not-the-fallback table. */
function af_fx_table() {
	return array(
		'GBP' => 0.04,
		'NAD' => 1.0,
		'ZAR' => 1.0,
		'USD' => 0.05,
		'VND' => 1300.0,
	);
}

// -- The rate table ----------------------------------------------------------

af_test( 'with nothing cached the page still prices, from the baked-in rates', function () {
	af_assert_same( AFRISTREAM_FX_FALLBACK, afristream_fx_rates(), 'the fallback table' );
	af_assert_same( array(), af_http_calls(), 'and reading rates never goes to the network' );
} );

af_test( 'a cached table is used once it is there', function () {
	set_transient( AFRISTREAM_FX_TRANSIENT, af_fx_table(), AFRISTREAM_FX_TTL );

	af_assert_same( af_fx_table(), afristream_fx_rates(), 'the cached table' );
} );

af_test( 'a table that cannot price every currency is refused outright', function () {
	// Half a table would leave whatever it is missing quoted at the Rand
	// figure with somebody else's symbol in front of it — wrong, and silent.
	$partial = af_fx_table();
	unset( $partial['VND'] );
	set_transient( AFRISTREAM_FX_TRANSIENT, $partial, AFRISTREAM_FX_TTL );

	af_assert_same( AFRISTREAM_FX_FALLBACK, afristream_fx_rates(), 'a missing currency falls back' );

	$zeroed        = af_fx_table();
	$zeroed['USD'] = 0;
	set_transient( AFRISTREAM_FX_TRANSIENT, $zeroed, AFRISTREAM_FX_TTL );

	af_assert_same( AFRISTREAM_FX_FALLBACK, afristream_fx_rates(), 'and so does a zero rate' );
} );

af_test( 'the Namibian Dollar is pegged to the Rand, in the fallback as in life', function () {
	af_assert_same( 1.0, AFRISTREAM_FX_FALLBACK['NAD'], 'one for one' );
	af_assert_same( 1.0, AFRISTREAM_FX_FALLBACK['ZAR'], 'and the base is itself' );
} );

// -- The refresh -------------------------------------------------------------

af_test( 'the refresh keeps the five currencies offered and discards the rest', function () {
	af_seed_http_json(
		AFRISTREAM_FX_ENDPOINT,
		array(
			'rates' => array_merge( af_fx_table(), array( 'EUR' => 0.05, 'JPY' => 8.4 ) ),
		)
	);

	afristream_fx_refresh();

	af_assert_same( af_fx_table(), get_transient( AFRISTREAM_FX_TRANSIENT ), 'only what the switcher offers' );
} );

af_test( 'a feed that is down leaves yesterday’s rates alone', function () {
	set_transient( AFRISTREAM_FX_TRANSIENT, af_fx_table(), AFRISTREAM_FX_TTL );
	// Nothing seeded, so wp_remote_get() fails the way an unreachable host does.

	afristream_fx_refresh();

	af_assert_same( af_fx_table(), afristream_fx_rates(), 'the cached table survives a failed refresh' );
} );

af_test( 'a feed missing one of the five is treated as no feed at all', function () {
	$short = af_fx_table();
	unset( $short['NAD'] );
	af_seed_http_json( AFRISTREAM_FX_ENDPOINT, array( 'rates' => $short ) );

	af_assert_same( null, afristream_fx_fetch(), 'a partial answer is refused' );

	afristream_fx_refresh();
	af_assert_same( false, get_transient( AFRISTREAM_FX_TRANSIENT ), 'and nothing is cached from it' );
} );

af_test( 'a non-200 is refused too, whatever it says in the body', function () {
	af_seed_http_json( AFRISTREAM_FX_ENDPOINT, array( 'rates' => af_fx_table() ), 429 );

	af_assert_same( null, afristream_fx_fetch(), 'rate-limited is not a rate table' );
} );

af_test( 'the refresh is booked once, on a daily schedule', function () {
	afristream_fx_schedule();
	$first = wp_next_scheduled( AFRISTREAM_FX_CRON );
	af_assert( $first > 0, 'the daily refresh is scheduled' );

	afristream_fx_schedule();
	af_assert_same( $first, wp_next_scheduled( AFRISTREAM_FX_CRON ), 'and booking it again does not double it up' );
} );

// -- The markup --------------------------------------------------------------

af_test( 'a price prints in Rand and carries the Rand figure for the switcher', function () {
	$html = afristream_price( 1599 );

	af_assert( false !== strpos( $html, '>R1599<' ), 'the Rand price is the text, not something JavaScript has to fill in' );
	af_assert( false !== strpos( $html, 'data-money="1599"' ), 'and the figure the switcher converts from is on the element' );
} );

af_test( 'the switcher offers every currency, and starts on the one that is billed', function () {
	$html = afristream_currency_switcher( 'header' );

	foreach ( AFRISTREAM_CURRENCIES as $code => $currency ) {
		af_assert( false !== strpos( $html, 'value="' . $code . '"' ), $code . ' is offered' );
	}
	af_assert(
		false !== strpos( $html, 'value="ZAR" selected' ),
		'Rand is selected, so the page loads showing the currency it bills in'
	);
	af_assert( false !== strpos( $html, 'aria-label="Show prices in"' ), 'and the control is named' );
} );

af_test( 'the billed-in-Rand line ships hidden, because the page loads in Rand', function () {
	$html = afristream_fx_note();

	af_assert( false !== strpos( $html, 'data-fx-note' ), 'the switcher can find it' );
	af_assert( false !== strpos( $html, 'hidden' ), 'and it starts off' );
	af_assert( false !== strpos( $html, 'billed in Rand' ), 'and it says what is actually charged' );
} );
