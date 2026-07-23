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
	if ( afristream_affiliate_is_false( afristream_affiliate_prop( $affiliation, 'active', true ) ) ) {
		return null;
	}

	return $affiliation;
}

/**
 * Whether a value from the SureCart API should be read as false.
 *
 * `false === $value` misses everything except the literal boolean — a JSON
 * `0` decodes to an int, and some endpoints report booleans as the strings
 * "false"/"0"/"no". Any of those has to be treated as inactive, not just the
 * exact type PHP happens to hand back.
 *
 * @param mixed $value Value to test.
 * @return bool
 */
function afristream_affiliate_is_false( $value ) {
	if ( is_string( $value ) ) {
		return in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no' ), true );
	}
	return ! $value;
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
	// No hardcoded currency fallback here — inventing "usd" for a GBP store is
	// worse than saying nothing. afristream_affiliate_payload() falls back to
	// the affiliation's own currency, and the front end formats without a
	// symbol if neither is known.
	$empty = array(
		'plans'    => array(),
		'currency' => '',
	);

	if ( ! class_exists( '\SureCart\Models\Price' ) ) {
		return $empty;
	}

	$prices = \SureCart\Models\Price::where(
		array(
			'archived' => false,
			// The default page size can silently drop plans for a store with a
			// large catalogue; ask for all of them in one page.
			'per_page' => 100,
		)
	)
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
			'currency'       => (string) afristream_affiliate_prop( $price, 'currency', '' ),
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
		'currency' => $currency,
	);
}

/**
 * The affiliate payload for the current user, cached per user.
 *
 * @return array
 */
/**
 * The currencies the portal will convert affiliate earnings into.
 * Rands first — most of the audience is South African.
 */
function afristream_affiliate_currencies() {
	return array( 'zar', 'usd', 'gbp', 'eur' );
}

/**
 * Conversion rates from the store's currency into each of the four the
 * calculator offers, or an empty array when they cannot be had.
 *
 * The rates come from Frankfurter, a keyless, permanently free feed of the
 * European Central Bank's published reference rates — the same class of
 * dependency as the sport feeds, and no key to keep. Cached for a day, since
 * the ECB publishes once a day. If the call fails the array comes back empty
 * and the front end drops the switcher rather than converting with a made-up
 * number.
 *
 * @param string $base The store's currency (ISO 4217, lowercase).
 * @return array<string,float> Keyed by lowercase currency code; base included at 1.0.
 */
function afristream_affiliate_rates( $base ) {
	$base = strtolower( (string) $base );
	if ( '' === $base ) {
		return array();
	}

	$cache_key = 'afristream_affiliate_rates_' . $base;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$wanted = array_values( array_diff( afristream_affiliate_currencies(), array( $base ) ) );
	$rates  = array( $base => 1.0 );

	$response = wp_remote_get(
		add_query_arg(
			array(
				'base'    => strtoupper( $base ),
				'symbols' => strtoupper( implode( ',', $wanted ) ),
			),
			'https://api.frankfurter.app/latest'
		),
		array( 'timeout' => 8 )
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// Cached briefly even on failure, so a feed that is down does not add a
		// timeout to every portal load.
		set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
		return array();
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $json ) || empty( $json['rates'] ) || ! is_array( $json['rates'] ) ) {
		set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
		return array();
	}

	foreach ( $json['rates'] as $code => $rate ) {
		$code = strtolower( (string) $code );
		if ( in_array( $code, afristream_affiliate_currencies(), true ) && $rate > 0 ) {
			$rates[ $code ] = (float) $rate;
		}
	}

	set_transient( $cache_key, $rates, DAY_IN_SECONDS );
	return $rates;
}

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

	$prices = afristream_affiliate_prices();
	// The price list is the best source for the store's currency, but when
	// SureCart's Price lookup errors or the class is missing, the affiliation
	// itself sometimes carries its own currency — worth trying before leaving
	// the front end to format with no symbol at all.
	$currency = '' !== $prices['currency']
		? $prices['currency']
		: (string) afristream_affiliate_prop( $affiliation, 'currency', '' );

	$payload = array(
		'affiliate'    => true,
		'code'         => (string) afristream_affiliate_prop( $affiliation, 'code', '' ),
		'commission'   => afristream_affiliate_commission( $affiliation ),
		'currency'     => $currency,
		'plans'        => $prices['plans'],
		'rates'        => afristream_affiliate_rates( $currency ),
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
