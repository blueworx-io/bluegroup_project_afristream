<?php
/**
 * In-memory stand-ins for the handful of WordPress functions the licence logic
 * calls. Deliberately not a WordPress emulator: each stub is the smallest thing
 * that behaves correctly for the code under test, so a passing test means the
 * logic is right rather than that the fake is generous.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

/**
 * The ACF-readiness scan reads real files off disk, so the one thing that
 * cannot be faked here is the filesystem — it is pointed at a small tree of
 * fixture plugins and themes instead. Which of them a given test sees is
 * decided per test by seeding active_plugins and the theme stubs, so the
 * constants can stay fixed the way WordPress's are.
 *
 * The must-use directory deliberately does not exist. A site without one is the
 * common case, and the scan has to treat a missing directory as nothing to read
 * rather than as something it failed to read.
 */
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures/plugins' );
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/fixtures/mu-plugins' );
define( 'AF_FIXTURE_THEMES', __DIR__ . '/fixtures/themes' );

$GLOBALS['af_store'] = array();

function af_reset_store() {
	$GLOBALS['af_store'] = array(
		'postmeta'        => array(),
		'usermeta'        => array(),
		'options'         => array(),
		'site_options'    => array(),
		'transients'      => array(),
		'autoload'        => array(),
		'posts'           => array(),
		'users'           => array(),
		'multisite'       => false,
		'theme'           => array(
			'stylesheet'           => 'clean-theme',
			'stylesheet_directory' => AF_FIXTURE_THEMES . '/clean-theme',
			'template'             => 'clean-theme',
			'template_directory'   => AF_FIXTURE_THEMES . '/clean-theme',
		),
		'filters'         => array(),
		'actions'         => array(),
		'surecart'        => array(
			'customers'     => array(),
			'subscriptions' => array(),
			'errors'        => array(),
		),
		'now'             => 1785024000, // 2026-07-26 08:00 UTC, fixed so date tests are stable.
		'capabilities'    => null, // null means permissive — see current_user_can() below.
		'current_user_id' => 0, // 0 means logged out — see is_user_logged_in() below.
		'is_admin'        => false, // see is_admin() and af_set_admin() below.
		'doing_ajax'      => false, // see wp_doing_ajax() and af_set_doing_ajax() below.
		'queries'         => array(), // every get_posts() argument set, in order — see af_get_posts_queries().
		'nonce_fields'    => array(), // action strings wp_nonce_field() minted, by field name.
		'nonce_verified'  => array(), // action strings wp_verify_nonce() was asked about.
		'registrations'   => array(
			'post_types' => array(),
			'meta'       => array(),
		),
	);

	// The fake $wpdb is a single long-lived object rather than part of the
	// store, so a query failure armed by one test would otherwise still be
	// armed for the next one and fail it somewhere unrelated.
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof AF_Fake_WPDB ) {
		$GLOBALS['wpdb']->af_reset();
	}
}
af_reset_store();

// -- Test-side seeding helpers ------------------------------------------------

/**
 * $content is stored because the ACF-readiness audit searches post_content for
 * ACF blocks and shortcodes, and there is nowhere else a test could put one.
 * It defaults to empty, so every existing caller is unaffected.
 */
function af_seed_post( $id, $title, $status = 'publish', $type = 'license', $content = '' ) {
	$GLOBALS['af_store']['posts'][ $id ] = array(
		'ID'           => $id,
		'post_title'   => $title,
		'post_status'  => $status,
		'post_type'    => $type,
		'post_content' => $content,
	);
}

/**
 * Point the theme stubs at a pair of fixture directories.
 *
 * Two arguments because a child theme and its parent are different directories
 * and the audit has to look in both — passing one name sets a theme with no
 * parent, which is the other real case.
 *
 * @param string $stylesheet Directory name under tests/php/fixtures/themes.
 * @param string $template   Parent directory name, or '' for no parent.
 */
function af_set_theme( $stylesheet, $template = '' ) {
	$template = '' === $template ? $stylesheet : $template;

	$GLOBALS['af_store']['theme'] = array(
		'stylesheet'           => $stylesheet,
		'stylesheet_directory' => AF_FIXTURE_THEMES . '/' . $stylesheet,
		'template'             => $template,
		'template_directory'   => AF_FIXTURE_THEMES . '/' . $template,
	);
}

/**
 * Turn multisite on for the rest of the current test, so the network-activated
 * plugin list is consulted.
 *
 * @param bool $on Whether this is a network install.
 */
function af_set_multisite( $on = true ) {
	$GLOBALS['af_store']['multisite'] = (bool) $on;
}

function af_seed_user( $id, $login = '', $registered = '2026-01-01 00:00:00' ) {
	$GLOBALS['af_store']['users'][ $id ] = array(
		'ID'              => $id,
		'user_login'      => $login ? $login : 'user' . $id,
		'display_name'    => $login ? $login : 'User ' . $id,
		'user_registered' => $registered,
	);
}

function af_set_now( $timestamp ) {
	$GLOBALS['af_store']['now'] = $timestamp;
}

/**
 * Restricts what current_user_can() answers for the rest of the current test.
 *
 * Only the capabilities named here are honoured; anything not listed reads as
 * refused, the same as a real WordPress role that was never granted it — a
 * stub that fell back to "true" for an unlisted capability would be more
 * permissive than WordPress itself for exactly the checks a test is trying to
 * pin down. af_reset_store() clears this back to the permissive default
 * (null) before every test, so a test that never calls this helper sees the
 * same always-true behaviour every other test always has.
 *
 * @param array<string,bool> $caps Capability name => whether it is held.
 */
function af_set_capabilities( array $caps ) {
	$GLOBALS['af_store']['capabilities'] = $caps;
}

// -- SureCart --------------------------------------------------------------

/**
 * The fake \SureCart\Models\Customer and \SureCart\Models\Subscription the
 * entitlement lookup queries.
 *
 * In their own file only because PHP will not accept a namespace declaration in
 * a file that already has code outside one, and wrapping this entire bootstrap
 * in a braced global namespace to gain two small classes would reindent every
 * line in it. They are part of the harness, not a separate concern, and are
 * seeded through the af_* helpers directly below.
 */
require_once __DIR__ . '/surecart-fakes.php';

/**
 * Give a WordPress user a SureCart customer record.
 *
 * @param int    $user_id     WordPress user the customer belongs to.
 * @param string $customer_id SureCart's own ID for them.
 */
function af_seed_surecart_customer( $user_id, $customer_id ) {
	$GLOBALS['af_store']['surecart']['customers'][] = array(
		'user_id' => (int) $user_id,
		'id'      => (string) $customer_id,
	);
}

/**
 * Give a SureCart customer a subscription in a given status.
 *
 * @param string $customer_id SureCart customer ID.
 * @param string $status      active, trialing, canceled, past_due …
 */
function af_seed_surecart_subscription( $customer_id, $status ) {
	$GLOBALS['af_store']['surecart']['subscriptions'][] = array(
		'customer' => (string) $customer_id,
		'status'   => (string) $status,
	);
}

/**
 * Make one of the two SureCart queries answer with a WP_Error, the way a
 * timeout or an expired API key does on the live site.
 *
 * @param string $which 'customers' or 'subscriptions'.
 * @param bool   $on    False to stop failing again.
 */
function af_surecart_fail( $which, $on = true ) {
	$GLOBALS['af_store']['surecart']['errors'][ $which ] = (bool) $on;
}

/**
 * What \SureCart\Models\Customer::where() answers.
 *
 * Honours user_ids, because a stub that handed every caller every customer
 * would make the join meaningless.
 *
 * @param array $args Query arguments.
 * @return array|WP_Error
 */
function af_surecart_customers( $args ) {
	if ( ! empty( $GLOBALS['af_store']['surecart']['errors']['customers'] ) ) {
		return new WP_Error( 'surecart_unavailable', 'SureCart could not be reached.' );
	}

	$wanted = isset( $args['user_ids'] ) ? array_map( 'intval', (array) $args['user_ids'] ) : array();
	$out    = array();

	foreach ( $GLOBALS['af_store']['surecart']['customers'] as $customer ) {
		if ( $wanted && ! in_array( $customer['user_id'], $wanted, true ) ) {
			continue;
		}
		$out[] = new AF_Fake_SureCart_Model( $customer );
	}

	return $out;
}

/**
 * What \SureCart\Models\Subscription::where() answers.
 *
 * Honours customer_ids and deliberately ignores the status filter. The code
 * under test re-checks each subscription's status in PHP precisely because the
 * API's filter is not something to take on trust, and a stub that pre-filtered
 * would make that re-check untestable — it would pass whether it was there or
 * not.
 *
 * @param array $args Query arguments.
 * @return array|WP_Error
 */
function af_surecart_subscriptions( $args ) {
	if ( ! empty( $GLOBALS['af_store']['surecart']['errors']['subscriptions'] ) ) {
		return new WP_Error( 'surecart_unavailable', 'SureCart could not be reached.' );
	}

	$wanted = isset( $args['customer_ids'] ) ? array_map( 'strval', (array) $args['customer_ids'] ) : array();
	$out    = array();

	foreach ( $GLOBALS['af_store']['surecart']['subscriptions'] as $subscription ) {
		if ( $wanted && ! in_array( $subscription['customer'], $wanted, true ) ) {
			continue;
		}
		$out[] = new AF_Fake_SureCart_Model( $subscription );
	}

	return $out;
}

// -- Meta ---------------------------------------------------------------------

function get_post_meta( $post_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['af_store']['postmeta'][ $post_id ] ) ? $GLOBALS['af_store']['postmeta'][ $post_id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}

/**
 * Writes the row, then fires the action WordPress fires after a meta write.
 * Modelled because it is the only honest way to reproduce a concurrent writer
 * landing between one caller's write and its read-back, with no threads to hand.
 */
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] = $value;
	do_action( 'updated_post_meta', 0, $post_id, $key, $value );
	return true;
}

/**
 * Insert-if-absent when $unique, like the real thing. The owner row is created
 * through this, so a stub that overwrote an existing row would quietly wipe out
 * whoever holds the licence at the start of every claim.
 */
function add_post_meta( $post_id, $key, $value, $unique = false ) {
	$existing = isset( $GLOBALS['af_store']['postmeta'][ $post_id ] ) ? $GLOBALS['af_store']['postmeta'][ $post_id ] : array();
	if ( $unique && array_key_exists( $key, $existing ) ) {
		return false;
	}
	$GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] = $value;
	return 1;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] );
	return true;
}

/**
 * There is no meta cache in front of this store — get_post_meta() reads the
 * array the fake $wpdb writes — so invalidation has nothing to do here. It is
 * stubbed rather than left out because the code under test must call it after
 * raw SQL, and a fatal for an undefined function would hide that.
 */
function wp_cache_delete( $key, $group = '' ) {
	return true;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['af_store']['usermeta'][ $user_id ] ) ? $GLOBALS['af_store']['usermeta'][ $user_id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}

/**
 * Fires the real 'update_user_meta' action WordPress itself fires before
 * saving, the same way update_post_meta() above fires 'updated_post_meta'.
 * Modelled for the same reason: it is the one honest way, with no threads to
 * hand, to stand a rival's own write up between this write being decided and
 * it landing on the store.
 */
function update_user_meta( $user_id, $key, $value ) {
	do_action( 'update_user_meta', 0, $user_id, $key, $value );
	$GLOBALS['af_store']['usermeta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['af_store']['usermeta'][ $user_id ][ $key ] );
	return true;
}

// -- Options and transients ---------------------------------------------------

/**
 * Reads the value, then passes it through the "option_{$name}" filter WordPress
 * really does apply. Modelled for the same reason as the meta write action: it
 * is the one place a test can stand another caller up between this caller's read
 * of the lock and what it does about it.
 */
function get_option( $key, $default = false ) {
	$value = array_key_exists( $key, $GLOBALS['af_store']['options'] ) ? $GLOBALS['af_store']['options'][ $key ] : $default;
	return apply_filters( 'option_' . $key, $value );
}

/**
 * Insert-if-absent, like the real thing: false and no overwrite when the option
 * already exists. The lock is built on exactly that refusal, so a forgiving stub
 * here would make every lock test pass without the lock working.
 */
function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['af_store']['options'] ) ) {
		return false;
	}
	$GLOBALS['af_store']['options'][ $key ]  = $value;
	$GLOBALS['af_store']['autoload'][ $key ] = $autoload;
	return true;
}

/**
 * $autoload is recorded rather than ignored, because whether an option is
 * loaded on every single front-end request is a property of the write and there
 * is nowhere else a test could observe it. Omitting it leaves whatever the
 * option already had, which is what real WordPress does with a null $autoload.
 */
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['af_store']['options'][ $key ] = $value;
	if ( null !== $autoload ) {
		$GLOBALS['af_store']['autoload'][ $key ] = $autoload;
	}
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['af_store']['options'][ $key ], $GLOBALS['af_store']['autoload'][ $key ] );
	return true;
}

/**
 * Network options live in their own table in real WordPress, and a
 * network-activated plugin is recorded only there — never in the per-site
 * active_plugins. Kept as a separate store here for exactly that reason: a stub
 * that aliased this onto get_option() would let a scan that only reads
 * active_plugins pass while missing every plugin on a real network install.
 */
function get_site_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['af_store']['site_options'] ) ? $GLOBALS['af_store']['site_options'][ $key ] : $default;
}

function update_site_option( $key, $value ) {
	$GLOBALS['af_store']['site_options'][ $key ] = $value;
	return true;
}

function is_multisite() {
	return (bool) $GLOBALS['af_store']['multisite'];
}

function get_current_blog_id() {
	return 1;
}

/**
 * The autoload setting the last write to this option asked for.
 *
 * @param string $key Option name.
 * @return string|bool|null Whatever was passed, or null if it was never set.
 */
function af_option_autoload( $key ) {
	return isset( $GLOBALS['af_store']['autoload'][ $key ] ) ? $GLOBALS['af_store']['autoload'][ $key ] : null;
}

function get_transient( $key ) {
	if ( ! array_key_exists( $key, $GLOBALS['af_store']['transients'] ) ) {
		return false;
	}
	list( $value, $expires ) = $GLOBALS['af_store']['transients'][ $key ];
	if ( $expires && $expires < $GLOBALS['af_store']['now'] ) {
		unset( $GLOBALS['af_store']['transients'][ $key ] );
		return false;
	}
	return $value;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['af_store']['transients'][ $key ] = array( $value, $ttl ? $GLOBALS['af_store']['now'] + $ttl : 0 );
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['af_store']['transients'][ $key ] );
	return true;
}

// -- Posts and users ----------------------------------------------------------

function get_the_title( $post_id ) {
	return isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ? $GLOBALS['af_store']['posts'][ $post_id ]['post_title'] : '';
}

function get_post_status( $post_id ) {
	return isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ? $GLOBALS['af_store']['posts'][ $post_id ]['post_status'] : false;
}

/**
 * Supports only the shapes this plugin actually asks for: licence posts by
 * status, optionally filtered by a meta_query, returning IDs.
 *
 * The meta_query support is deliberately the narrowest thing that answers the
 * one query the plugin makes — the reverse lookup of which licences name a
 * given owner. Only a flat list of clauses is understood, only with an '='
 * comparison, and anything else raises rather than being quietly ignored: a
 * stub that skipped a clause it did not recognise would answer "every licence"
 * to a query that asked for one user's, and the test built on it would pass
 * while the real query returned something else entirely. That is the same
 * standard the fake $wpdb holds itself to.
 *
 * Values are compared as strings because that is what MySQL does with a
 * meta_value column, and because the owner is written as a string of digits by
 * afristream_claim_license_row() but as an integer by the backfill's
 * update_post_meta() — both are '7' in the database, and both must match here.
 */
function get_posts( $args = array() ) {
	$GLOBALS['af_store']['queries'][] = $args;

	$type   = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
	$status = isset( $args['post_status'] ) ? (array) $args['post_status'] : array( 'publish' );
	$meta   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();

	if ( isset( $meta['relation'] ) ) {
		throw new RuntimeException( 'Fake get_posts(): meta_query relations are not modelled.' );
	}

	$out = array();
	foreach ( $GLOBALS['af_store']['posts'] as $post ) {
		if ( $post['post_type'] !== $type ) {
			continue;
		}
		if ( ! in_array( $post['post_status'], $status, true ) ) {
			continue;
		}
		if ( ! af_meta_query_matches( $post['ID'], $meta ) ) {
			continue;
		}
		$out[] = $post['ID'];
	}
	sort( $out );
	return $out;
}

/**
 * Every get_posts() call made since the store was reset, with its arguments.
 *
 * How a lookup is asked for is the thing under test in one case — the reverse
 * "which licences does this user hold" query has to be a single meta_query
 * rather than a scan of every licence, and both shapes return the same answer,
 * so the answer alone cannot tell them apart.
 *
 * @return array<int,array>
 */
function af_get_posts_queries() {
	return $GLOBALS['af_store']['queries'];
}

/**
 * Whether one post satisfies every clause of a modelled meta_query.
 *
 * @param int   $post_id Post to test.
 * @param array $clauses Flat list of meta_query clauses, ANDed.
 * @return bool
 */
function af_meta_query_matches( $post_id, $clauses ) {
	foreach ( $clauses as $clause ) {
		if ( ! is_array( $clause ) || ! isset( $clause['key'] ) || ! array_key_exists( 'value', $clause ) ) {
			throw new RuntimeException( 'Fake get_posts(): meta_query clause shape is not modelled.' );
		}
		if ( isset( $clause['compare'] ) && '=' !== $clause['compare'] ) {
			throw new RuntimeException( 'Fake get_posts(): meta_query compare "' . $clause['compare'] . '" is not modelled.' );
		}

		$rows = isset( $GLOBALS['af_store']['postmeta'][ $post_id ] ) ? $GLOBALS['af_store']['postmeta'][ $post_id ] : array();

		if ( ! array_key_exists( $clause['key'], $rows ) ) {
			return false;
		}
		if ( (string) $rows[ $clause['key'] ] !== (string) $clause['value'] ) {
			return false;
		}
	}

	return true;
}

/**
 * Every seeded user, in ID order, as objects.
 *
 * 'fields' is honoured, because the real get_users() honours it: handed an array
 * of field names it builds rows carrying only those. A stub that returned the
 * whole record regardless would let code reach for a property the query never
 * asked for and pass here while fataling on the live site.
 *
 * 'orderby' and 'order' are deliberately ignored, and rows come back in ID order,
 * which is not registration order. That is the less convenient answer on purpose:
 * the only caller sorts in PHP anyway — it has to, for the tie-break and for an
 * unreadable registration date — and a stub that pre-sorted into the order that
 * caller wants would let a broken sort pass unnoticed. 'number' is ignored
 * because the only caller passes -1, meaning all of them.
 */
function get_users( $args = array() ) {
	$users = $GLOBALS['af_store']['users'];
	ksort( $users );

	$fields = isset( $args['fields'] ) ? $args['fields'] : 'all';
	$out    = array();

	foreach ( $users as $user ) {
		if ( is_array( $fields ) ) {
			$row = array();
			foreach ( $fields as $field ) {
				$row[ $field ] = array_key_exists( $field, $user ) ? $user[ $field ] : null;
			}
			$user = $row;
		}
		$out[] = (object) $user;
	}

	return $out;
}

function get_userdata( $user_id ) {
	return isset( $GLOBALS['af_store']['users'][ $user_id ] ) ? (object) $GLOBALS['af_store']['users'][ $user_id ] : false;
}

// -- Hooks --------------------------------------------------------------------

function add_filter( $tag, $fn, $priority = 10, $args = 1 ) {
	$GLOBALS['af_store']['filters'][ $tag ][] = $fn;
	return true;
}

function apply_filters( $tag, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	if ( empty( $GLOBALS['af_store']['filters'][ $tag ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['af_store']['filters'][ $tag ] as $fn ) {
		$value = call_user_func_array( $fn, array_merge( array( $value ), $extra ) );
	}
	return $value;
}

function add_action( $tag, $fn, $priority = 10, $args = 1 ) {
	$GLOBALS['af_store']['actions'][ $tag ][] = $fn;
	return true;
}

function do_action( $tag ) {
	$extra = array_slice( func_get_args(), 1 );
	if ( empty( $GLOBALS['af_store']['actions'][ $tag ] ) ) {
		return;
	}
	foreach ( $GLOBALS['af_store']['actions'][ $tag ] as $fn ) {
		call_user_func_array( $fn, $extra );
	}
}

function has_action( $tag ) {
	return ! empty( $GLOBALS['af_store']['actions'][ $tag ] );
}

/**
 * What add_action() has registered against a tag, right now.
 *
 * af_reset_store() wipes the actions array before every test, including
 * whatever a plugin file registered at require time — so a test that wants to
 * prove that require-time registration actually happened has to capture it
 * before the first af_reset_store() call runs, not read it from inside a test
 * body. This is that capture point, kept to a single accessor rather than
 * having tests reach into $GLOBALS['af_store'] directly.
 *
 * @param string $tag Hook name.
 * @return array<int,callable|string> Registered callbacks, in registration order.
 */
function af_registered_actions( $tag ) {
	return isset( $GLOBALS['af_store']['actions'][ $tag ] ) ? $GLOBALS['af_store']['actions'][ $tag ] : array();
}

// -- Misc ---------------------------------------------------------------------

function current_time( $type = 'timestamp' ) {
	if ( 'timestamp' === $type || 'U' === $type ) {
		return $GLOBALS['af_store']['now'];
	}
	return gmdate( $type, $GLOBALS['af_store']['now'] );
}

function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( (string) $value ) );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html_e( $text, $domain = '' ) {
	echo esc_html( $text );
}

function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}

function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

function _x( $text, $context = '', $domain = '' ) {
	return $text;
}

function esc_url( $url ) {
	return (string) $url;
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? $GLOBALS['af_store']['now'] : $timestamp );
}

function human_time_diff( $from, $to = 0 ) {
	return abs( (int) $to - (int) $from ) . ' seconds';
}

function get_post_type( $post_id ) {
	return isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ? $GLOBALS['af_store']['posts'][ $post_id ]['post_type'] : false;
}

function get_edit_user_link( $user_id ) {
	return 'user-edit.php?user_id=' . (int) $user_id;
}

/**
 * Answers null for a post that does not exist, the way the real one answers
 * null for a post that has been deleted or that the current user cannot edit.
 * A stub that always handed back a URL would let a page linking to nothing pass
 * here and print an empty href on the live site.
 *
 * @param int    $post_id Post to link to.
 * @param string $context 'display' escapes the ampersand, anything else does not.
 * @return string|null
 */
function get_edit_post_link( $post_id, $context = 'display' ) {
	if ( ! isset( $GLOBALS['af_store']['posts'][ $post_id ] ) ) {
		return null;
	}
	$separator = 'display' === $context ? '&amp;' : '&';
	return 'post.php?post=' . (int) $post_id . $separator . 'action=edit';
}

function get_stylesheet() {
	return $GLOBALS['af_store']['theme']['stylesheet'];
}

function get_stylesheet_directory() {
	return $GLOBALS['af_store']['theme']['stylesheet_directory'];
}

function get_template() {
	return $GLOBALS['af_store']['theme']['template'];
}

function get_template_directory() {
	return $GLOBALS['af_store']['theme']['template_directory'];
}

function admin_url( $path = '' ) {
	return '/wp-admin/' . ltrim( (string) $path, '/' );
}

function rest_ensure_response( $data ) {
	return $data;
}

function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( $list as $item ) {
		$out[] = is_object( $item ) ? $item->$field : $item[ $field ];
	}
	return $out;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}

// -- Database -----------------------------------------------------------------

/**
 * The one piece of $wpdb the plugin uses: the conditional UPDATE that claims a
 * licence's owner row.
 *
 * Deliberately narrow. The value of that statement is that it changes a row only
 * when the row still holds the exact value the caller expected, so a stub that
 * were any more forgiving than MySQL would make every test built on it
 * meaningless — a race the real database would refuse would sail through here.
 * It therefore matches on post_id, meta_key and the exact current meta_value,
 * answers 1 only when a row genuinely matched and 0 otherwise, and throws on any
 * SQL it does not recognise rather than guessing at a success.
 *
 * It also fires an action either side of the write. That is the only honest way,
 * with no threads available, to stand another request up at a chosen instant:
 * before the statement (a rival that gets there first) or after it (a rival that
 * arrives once this caller already believes it has won). Nested queries — the
 * rival's own — do not re-fire the actions, so a test hook cannot recurse.
 */
class AF_Fake_WPDB {
	/** @var string Table name, interpolated into the statement by the plugin. */
	public $postmeta = 'wp_postmeta';

	/** @var string The other table name the ACF audit's joins name. */
	public $posts = 'wp_posts';

	/**
	 * @var string What the last statement went wrong with, '' when it did not.
	 *             Real $wpdb clears this at the start of every query and sets it
	 *             on failure, and the ACF audit reads it to tell a genuinely
	 *             empty result apart from a query that never ran.
	 */
	public $last_error = '';

	/** @var string Which modelled SELECT to fail, '' for none, '*' for all. */
	private $fail_query = '';

	/** @var string What to report in last_error when one fails. */
	private $fail_message = '';

	/** @var bool True while a query is running, so nested ones fire no actions. */
	private $in_query = false;

	/**
	 * Disarm any seeded failure and clear the error, between tests.
	 */
	public function af_reset() {
		$this->fail_query   = '';
		$this->fail_message = '';
		$this->last_error   = '';
	}

	/**
	 * Make a modelled SELECT fail the way a timed-out query does: null back, and
	 * a message in last_error.
	 *
	 * @param string $which        elementor, acf_posts, content, or '*' for all.
	 * @param string $message      What last_error should report.
	 */
	public function af_fail_query( $which = '*', $message = 'MySQL server has gone away' ) {
		$this->fail_query   = (string) $which;
		$this->fail_message = (string) $message;
	}

	/**
	 * Make a modelled SELECT fail the way $wpdb->ready being false does: null
	 * back from get_results(), but last_error left empty, because query() never
	 * ran far enough to set it. Deliberately distinct from af_fail_query(),
	 * which always leaves a message behind — this is the shape that used to be
	 * indistinguishable from "nothing found" because last_error was the only
	 * thing the audit checked.
	 *
	 * @param string $which elementor, acf_posts, content, or '*' for all.
	 */
	public function af_fail_query_silently( $which = '*' ) {
		$this->fail_query   = (string) $which;
		$this->fail_message = '';
	}

	/**
	 * Substitute %s and %d exactly as many times as there are arguments. A
	 * mismatch is a bug in the caller and is raised as one: silently ignoring a
	 * spare argument is how an unbound placeholder reaches a real database.
	 */
	public function prepare( $query, ...$args ) {
		$parts = preg_split( '/(%[sd])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$i     = 0;

		foreach ( $parts as $part ) {
			if ( '%s' === $part || '%d' === $part ) {
				if ( ! array_key_exists( $i, $args ) ) {
					throw new RuntimeException( 'Fake $wpdb->prepare: more placeholders than arguments.' );
				}
				$out .= '%s' === $part
					? "'" . addslashes( (string) $args[ $i ] ) . "'"
					: (string) (int) $args[ $i ];
				$i++;
				continue;
			}
			$out .= $part;
		}

		if ( count( $args ) !== $i ) {
			throw new RuntimeException( 'Fake $wpdb->prepare: more arguments than placeholders.' );
		}

		return $out;
	}

	/**
	 * @param string $sql Already through prepare().
	 * @return int Rows changed: 1 when a row matched, 0 when none did.
	 */
	public function query( $sql ) {
		$this->last_error = '';

		$parsed = $this->parse( $sql );

		$outer          = $this->in_query;
		$this->in_query = true;

		try {
			if ( ! $outer ) {
				do_action( 'af_wpdb_before_query', $parsed['post_id'], $parsed['meta_key'], $parsed['from'], $parsed['to'] );
			}

			$changed = $this->swap( $parsed );

			if ( ! $outer ) {
				do_action( 'af_wpdb_after_query', $parsed['post_id'], $parsed['meta_key'], $parsed['from'], $parsed['to'], $changed );
			}
		} finally {
			$this->in_query = $outer;
		}

		return $changed;
	}

	/**
	 * The single statement this fake models, and nothing else.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array{to:string,post_id:int,meta_key:string,from:string}
	 */
	private function parse( $sql ) {
		$quoted  = "'((?:[^'\\\\]|\\\\.)*)'";
		$pattern = '/^\s*UPDATE\s+' . preg_quote( $this->postmeta, '/' )
			. '\s+SET\s+meta_value\s*=\s*' . $quoted
			. '\s+WHERE\s+post_id\s*=\s*(\d+)'
			. '\s+AND\s+meta_key\s*=\s*' . $quoted
			. '\s+AND\s+meta_value\s*=\s*' . $quoted . '\s*$/';

		if ( ! preg_match( $pattern, $sql, $m ) ) {
			throw new RuntimeException( 'Fake $wpdb was handed SQL it does not model: ' . $sql );
		}

		return array(
			'to'       => stripslashes( $m[1] ),
			'post_id'  => (int) $m[2],
			'meta_key' => stripslashes( $m[3] ),
			'from'     => stripslashes( $m[4] ),
		);
	}

	/**
	 * @param array $p Parsed statement.
	 * @return int 1 if a row matched and changed, 0 otherwise.
	 */
	private function swap( $p ) {
		$rows = isset( $GLOBALS['af_store']['postmeta'][ $p['post_id'] ] ) ? $GLOBALS['af_store']['postmeta'][ $p['post_id'] ] : array();

		if ( ! array_key_exists( $p['meta_key'], $rows ) ) {
			return 0;
		}
		if ( (string) $rows[ $p['meta_key'] ] !== $p['from'] ) {
			return 0;
		}

		$GLOBALS['af_store']['postmeta'][ $p['post_id'] ][ $p['meta_key'] ] = $p['to'];
		return 1;
	}

	/**
	 * Escapes the LIKE wildcards, exactly as the real one does, so a search term
	 * carrying a % cannot turn into a wildcard.
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * The three SELECTs the ACF-readiness audit issues, and nothing else.
	 *
	 * Modelled to the same standard as the UPDATE above: narrow on purpose, and
	 * it throws at anything it does not recognise rather than guessing at an
	 * answer. The point of these three is that they can fail — a leading-wildcard
	 * LIKE across postmeta is the query that times out on a real site — so this
	 * returns null and sets last_error when armed, because null cast to an empty
	 * array is precisely how a timeout used to be reported as a clean site.
	 *
	 * @param string $sql Already through prepare() where it takes arguments.
	 * @return array<int,object>|null Rows, or null when the query failed.
	 */
	public function get_results( $sql ) {
		$this->last_error = '';

		$query = $this->identify( $sql );

		if ( '' !== $this->fail_query && ( '*' === $this->fail_query || $query['name'] === $this->fail_query ) ) {
			$this->last_error = $this->fail_message;
			return null;
		}

		if ( 'elementor' === $query['name'] ) {
			$rows = $this->rows_elementor( $query['like'][0] );
		} elseif ( 'acf_posts' === $query['name'] ) {
			$rows = $this->rows_acf_posts( $query['types'] );
		} else {
			$rows = $this->rows_content( $query['like'] );
		}

		// The real statements all carry a LIMIT now, the same as MySQL would
		// enforce one: this stub returns at most that many rows, so a test can
		// seed more matches than the display cap and see the truncation the
		// audit is supposed to disclose.
		return array_slice( $rows, 0, $query['limit'] );
	}

	/**
	 * Which of the three modelled SELECTs this is, with its bound values.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array{name:string,like:string[],types:string[],limit:int}
	 */
	private function identify( $sql ) {
		// Collapsed to single spaces first: the plugin writes these across
		// several indented lines, and matching the shape matters, not the
		// whitespace it is laid out with.
		$q      = trim( preg_replace( '/\s+/', ' ', (string) $sql ) );
		$quoted = "'((?:[^'\\\\]|\\\\.)*)'";
		$meta   = preg_quote( $this->postmeta, '/' );
		$posts  = preg_quote( $this->posts, '/' );

		$elementor = '/^SELECT p\.ID, p\.post_title, p\.post_type'
			. ' FROM ' . $meta . ' m'
			. ' INNER JOIN ' . $posts . ' p ON p\.ID = m\.post_id'
			. " WHERE m\.meta_key = '_elementor_data'"
			. ' AND m\.meta_value LIKE ' . $quoted
			. " AND p\.post_status != 'trash'"
			. " AND p\.post_type != 'revision'"
			. ' LIMIT (\d+)$/';

		if ( preg_match( $elementor, $q, $m ) ) {
			return array( 'name' => 'elementor', 'like' => array( stripslashes( $m[1] ) ), 'types' => array(), 'limit' => (int) $m[2] );
		}

		$acf_posts = '/^SELECT ID, post_title, post_type'
			. ' FROM ' . $posts
			. ' WHERE post_type IN \( ' . $quoted . ', ' . $quoted . ', ' . $quoted . ' \)'
			. " AND post_status != 'trash'"
			. ' LIMIT (\d+)$/';

		if ( preg_match( $acf_posts, $q, $m ) ) {
			return array(
				'name'  => 'acf_posts',
				'like'  => array(),
				'types' => array( stripslashes( $m[1] ), stripslashes( $m[2] ), stripslashes( $m[3] ) ),
				'limit' => (int) $m[4],
			);
		}

		$content = '/^SELECT ID, post_title, post_type'
			. ' FROM ' . $posts
			. " WHERE post_status != 'trash'"
			. " AND post_type != 'revision'"
			. ' AND \( post_content LIKE ' . $quoted . ' OR post_content LIKE ' . $quoted . ' \)'
			. ' LIMIT (\d+)$/';

		if ( preg_match( $content, $q, $m ) ) {
			return array(
				'name'  => 'content',
				'like'  => array( stripslashes( $m[1] ), stripslashes( $m[2] ) ),
				'types' => array(),
				'limit' => (int) $m[3],
			);
		}

		throw new RuntimeException( 'Fake $wpdb was handed a SELECT it does not model: ' . $sql );
	}

	/**
	 * MySQL's LIKE, including the escaping esc_like() applies and the
	 * case-insensitivity of WordPress's default collation. Written out rather
	 * than reduced to a strpos() because the audit binds a pattern built by
	 * esc_like(), and a stub that ignored the escaping would happily match on a
	 * wildcard the real database would have treated as a literal.
	 *
	 * @param string $pattern LIKE pattern.
	 * @param string $value   Value to test.
	 * @return bool
	 */
	private function like_matches( $pattern, $value ) {
		$regex  = '';
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
				continue;
			}
			if ( '%' === $char ) {
				$regex .= '.*';
				continue;
			}
			if ( '_' === $char ) {
				$regex .= '.';
				continue;
			}
			$regex .= preg_quote( $char, '/' );
		}

		return 1 === preg_match( '/^' . $regex . '$/si', (string) $value );
	}

	/**
	 * Posts whose _elementor_data matches, excluding trash and revisions.
	 *
	 * @param string $like LIKE pattern.
	 * @return array<int,object>
	 */
	private function rows_elementor( $like ) {
		$out = array();

		foreach ( $this->ordered_posts() as $post ) {
			if ( 'trash' === $post['post_status'] || 'revision' === $post['post_type'] ) {
				continue;
			}

			$meta = isset( $GLOBALS['af_store']['postmeta'][ $post['ID'] ]['_elementor_data'] )
				? $GLOBALS['af_store']['postmeta'][ $post['ID'] ]['_elementor_data']
				: null;

			if ( null === $meta || ! $this->like_matches( $like, $meta ) ) {
				continue;
			}

			$out[] = $this->row( $post );
		}

		return $out;
	}

	/**
	 * ACF's own field groups, post types and taxonomies.
	 *
	 * @param string[] $types Post types the statement named.
	 * @return array<int,object>
	 */
	private function rows_acf_posts( $types ) {
		$out = array();

		foreach ( $this->ordered_posts() as $post ) {
			if ( 'trash' === $post['post_status'] || ! in_array( $post['post_type'], $types, true ) ) {
				continue;
			}
			$out[] = $this->row( $post );
		}

		return $out;
	}

	/**
	 * Posts whose content matches either LIKE pattern.
	 *
	 * @param string[] $likes Two LIKE patterns, OR'd.
	 * @return array<int,object>
	 */
	private function rows_content( $likes ) {
		$out = array();

		foreach ( $this->ordered_posts() as $post ) {
			if ( 'trash' === $post['post_status'] || 'revision' === $post['post_type'] ) {
				continue;
			}

			$content = isset( $post['post_content'] ) ? $post['post_content'] : '';
			if ( ! $this->like_matches( $likes[0], $content ) && ! $this->like_matches( $likes[1], $content ) ) {
				continue;
			}

			$out[] = $this->row( $post );
		}

		return $out;
	}

	/**
	 * Seeded posts in ID order, so a result set is stable to assert against.
	 *
	 * @return array<int,array>
	 */
	private function ordered_posts() {
		$posts = $GLOBALS['af_store']['posts'];
		ksort( $posts );
		return $posts;
	}

	/**
	 * One row, carrying only the three columns the statements select — a stub
	 * that handed back the whole record would let code read a column the query
	 * never asked for and still pass here.
	 *
	 * @param array $post Stored post.
	 * @return object
	 */
	private function row( $post ) {
		return (object) array(
			'ID'         => $post['ID'],
			'post_title' => $post['post_title'],
			'post_type'  => $post['post_type'],
		);
	}
}

$GLOBALS['wpdb'] = new AF_Fake_WPDB();

/**
 * Arm a modelled SELECT to fail, the way a timeout on a big postmeta table does.
 *
 * @param string $which   elementor, acf_posts, content, or '*' for all of them.
 * @param string $message What $wpdb->last_error should then report.
 */
function af_wpdb_fail( $which = '*', $message = 'MySQL server has gone away' ) {
	$GLOBALS['wpdb']->af_fail_query( $which, $message );
}

/**
 * Arm a modelled SELECT to fail the way $wpdb->ready being false does: null
 * back, last_error left empty. See AF_Fake_WPDB::af_fail_query_silently().
 *
 * @param string $which elementor, acf_posts, content, or '*' for all of them.
 */
function af_wpdb_fail_silently( $which = '*' ) {
	$GLOBALS['wpdb']->af_fail_query_silently( $which );
}

// Admin-only functions the includes call at load time but tests never exercise.

/**
 * False by default, because nothing in the suite is a request to wp-admin. A
 * test that needs the admin-side branch of a hook — the licence list table's
 * sort, say — turns it on with af_set_admin() for the rest of that test.
 */
function is_admin() { return (bool) $GLOBALS['af_store']['is_admin']; }

/**
 * @param bool $on Whether this request should look like a wp-admin one.
 */
function af_set_admin( $on = true ) {
	$GLOBALS['af_store']['is_admin'] = (bool) $on;
}

/**
 * False by default: an ordinary admin page load, not admin-ajax.php. Settable
 * because admin_init fires on both and the migration gate depends on telling
 * them apart.
 */
function wp_doing_ajax() { return (bool) $GLOBALS['af_store']['doing_ajax']; }

/**
 * @param bool $on Whether this request should look like admin-ajax.php.
 */
function af_set_doing_ajax( $on = true ) {
	$GLOBALS['af_store']['doing_ajax'] = (bool) $on;
}

function add_meta_box() {}
function add_menu_page() {}
function add_options_page() {}
function add_settings_section() {}
function add_settings_field() {}
function register_setting() {}

/**
 * Records what was registered instead of discarding it. The post type's
 * capability_type and the meta's show_in_rest are security decisions that exist
 * nowhere but these arguments — there is no behaviour to observe them through
 * without a REST stack — so the only way a test can hold them still is to read
 * back what was passed.
 */
function register_post_type( $type = '', $args = array() ) {
	$GLOBALS['af_store']['registrations']['post_types'][ (string) $type ] = (array) $args;
	return (object) array( 'name' => (string) $type );
}

function register_meta( $object_type = '', $meta_key = '', $args = array() ) {
	$GLOBALS['af_store']['registrations']['meta'][ (string) $meta_key ] = (array) $args;
	return true;
}

/**
 * The arguments a post type was registered with, or null if it never was.
 *
 * @param string $type Post type name.
 * @return array|null
 */
function af_registered_post_type( $type ) {
	return isset( $GLOBALS['af_store']['registrations']['post_types'][ $type ] )
		? $GLOBALS['af_store']['registrations']['post_types'][ $type ]
		: null;
}

/**
 * The arguments a meta key was registered with, or null if it never was.
 *
 * @param string $meta_key Meta key.
 * @return array|null
 */
function af_registered_meta( $meta_key ) {
	return isset( $GLOBALS['af_store']['registrations']['meta'][ $meta_key ] )
		? $GLOBALS['af_store']['registrations']['meta'][ $meta_key ]
		: null;
}

function add_shortcode() {}
function wp_register_script() {}
function wp_register_style() {}

/**
 * Records the action a nonce was minted for, keyed by the field name it was
 * rendered under. There is no request cycle here to carry a real token across,
 * so what a test can check is the pair of action strings: the one the form
 * minted and the one the save verified. A nonce that is not scoped to the thing
 * it authorises shows up as those two strings being the same for two different
 * users, which is exactly the flaw.
 */
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {
	$GLOBALS['af_store']['nonce_fields'][ (string) $name ][] = (string) $action;
	echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="nonce" />';
}

/**
 * Always honours the token — there is none to check — but records which action
 * it was asked about, so the scoping can be asserted. See wp_nonce_field().
 */
function wp_verify_nonce( $nonce = '', $action = -1 ) {
	$GLOBALS['af_store']['nonce_verified'][] = (string) $action;
	return true;
}

/**
 * Every action string wp_nonce_field() minted under a field name, in order.
 *
 * @param string $name Field name.
 * @return string[]
 */
function af_nonce_field_actions( $name ) {
	return isset( $GLOBALS['af_store']['nonce_fields'][ $name ] )
		? $GLOBALS['af_store']['nonce_fields'][ $name ]
		: array();
}

/**
 * Every action string wp_verify_nonce() has been asked about, in order.
 *
 * @return string[]
 */
function af_nonce_verified_actions() {
	return $GLOBALS['af_store']['nonce_verified'];
}
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
/**
 * Always honours the nonce, the same permissive default as wp_verify_nonce()
 * above. The ACF-readiness "Recheck now" link is never followed by a test —
 * there is no request cycle here to drive it through — so this exists only so
 * requiring configurations.php does not leave the function undefined; it is
 * not meant to exercise WordPress's real CSRF behaviour.
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { return true; }
/**
 * @param string $actionurl URL to append the nonce to.
 * @param int|string $action Nonce action.
 * @param string $name Query arg name for the nonce.
 * @return string
 */
function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
	$actionurl = str_replace( '&amp;', '&', (string) $actionurl );
	$sep       = false !== strpos( $actionurl, '?' ) ? '&' : '?';
	return $actionurl . $sep . $name . '=' . wp_create_nonce( $action );
}
/**
 * $b defaults to true exactly as core's does, because core's callers rely on
 * it: selected( in_array( $id, $held, true ) ) is the one-argument form, and a
 * stub demanding two arguments turns that into a fatal under PHP 8.
 */
function selected( $a, $b = true, $echo = true ) { return $a === $b ? ' selected' : ''; }
function checked( $a, $b = true, $echo = true ) { return $a === $b ? ' checked' : ''; }
function plugins_url( $path = '', $file = '' ) { return '/wp-content/plugins/' . ltrim( (string) $path, '/' ); }
function rest_url( $path = '' ) { return '/wp-json/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $key, $value, $url = '' ) { return $url . '?' . $key . '=' . $value; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
/**
 * Permissive by default (af_store['capabilities'] === null), the same as
 * before this stub was made settable, so no existing test's behaviour
 * changes. A test that calls af_set_capabilities() switches this to an
 * allow-list: a capability not present in it is refused, matching a real
 * WordPress role that was simply never granted it. The object-id argument
 * some callers pass (e.g. current_user_can( 'edit_post', $post_id )) is
 * deliberately not modelled — this stub answers only by capability name — so
 * it must not be used to fake map_meta_cap()'s per-object reductions.
 */
function current_user_can( $capability, ...$args ) {
	$caps = $GLOBALS['af_store']['capabilities'];
	if ( null === $caps ) {
		return true;
	}
	return ! empty( $caps[ $capability ] );
}
/**
 * The user af_set_current_user() named, read from the same seeded records
 * get_userdata() serves. It used to answer with a fixed anonymous object, which
 * meant the licence log's actor could only ever be 'system' and nothing could
 * exercise what happens when the name comes from an account — a display name is
 * something a subscriber sets for themselves, so it is the one field in a log
 * entry that is not this plugin's own words.
 *
 * Falls back to that same anonymous object when nobody is logged in, or when
 * the current user ID names an account no test seeded, which is what a webhook
 * or a cron run looks like.
 */
function wp_get_current_user() {
	$user = get_userdata( $GLOBALS['af_store']['current_user_id'] );
	return $user ? $user : (object) array( 'ID' => 0, 'display_name' => 'system' );
}

/**
 * Logged-out by default, the same as before this stub was made settable, so
 * no existing test's behaviour changes. af_set_current_user() switches both
 * this and is_user_logged_in() together, the way a real WordPress session
 * would, and af_reset_store() clears back to logged-out before every test.
 *
 * @param int $user_id 0 for logged out, a truthy ID for logged in.
 */
function af_set_current_user( $user_id ) {
	$GLOBALS['af_store']['current_user_id'] = (int) $user_id;
}
function get_current_user_id() { return $GLOBALS['af_store']['current_user_id']; }
function is_user_logged_in() { return 0 !== $GLOBALS['af_store']['current_user_id']; }
