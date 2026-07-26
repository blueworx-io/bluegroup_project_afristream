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

$GLOBALS['af_store'] = array();

function af_reset_store() {
	$GLOBALS['af_store'] = array(
		'postmeta'   => array(),
		'usermeta'   => array(),
		'options'    => array(),
		'transients' => array(),
		'posts'      => array(),
		'users'      => array(),
		'filters'    => array(),
		'actions'    => array(),
		'now'        => 1785024000, // 2026-07-26 08:00 UTC, fixed so date tests are stable.
	);
}
af_reset_store();

// -- Test-side seeding helpers ------------------------------------------------

function af_seed_post( $id, $title, $status = 'publish', $type = 'license' ) {
	$GLOBALS['af_store']['posts'][ $id ] = array(
		'ID'          => $id,
		'post_title'  => $title,
		'post_status' => $status,
		'post_type'   => $type,
	);
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
	$GLOBALS['af_store']['options'][ $key ] = $value;
	return true;
}

function update_option( $key, $value ) {
	$GLOBALS['af_store']['options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['af_store']['options'][ $key ] );
	return true;
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
 * status, returning IDs.
 */
function get_posts( $args = array() ) {
	$type   = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
	$status = isset( $args['post_status'] ) ? (array) $args['post_status'] : array( 'publish' );
	$out    = array();
	foreach ( $GLOBALS['af_store']['posts'] as $post ) {
		if ( $post['post_type'] !== $type ) {
			continue;
		}
		if ( ! in_array( $post['post_status'], $status, true ) ) {
			continue;
		}
		$out[] = $post['ID'];
	}
	sort( $out );
	return $out;
}

function get_users( $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['af_store']['users'] as $user ) {
		$out[] = (object) $user;
	}
	usort( $out, function ( $a, $b ) { return $a->ID <=> $b->ID; } );
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

function get_edit_post_link( $post_id ) {
	return 'post.php?post=' . (int) $post_id . '&action=edit';
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

	/** @var bool True while a query is running, so nested ones fire no actions. */
	private $in_query = false;

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
}

$GLOBALS['wpdb'] = new AF_Fake_WPDB();

// Admin-only functions the includes call at load time but tests never exercise.
function is_admin() { return false; }
function add_meta_box() {}
function add_menu_page() {}
function add_options_page() {}
function add_settings_section() {}
function add_settings_field() {}
function register_setting() {}
function register_post_type() {}
function register_meta() {}
function add_shortcode() {}
function wp_register_script() {}
function wp_register_style() {}
function wp_nonce_field() {}
function wp_verify_nonce() { return true; }
function wp_create_nonce() { return 'nonce'; }
function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected' : ''; }
function checked( $a, $b, $echo = true ) { return $a === $b ? ' checked' : ''; }
function plugins_url( $path = '', $file = '' ) { return '/wp-content/plugins/' . ltrim( (string) $path, '/' ); }
function rest_url( $path = '' ) { return '/wp-json/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $key, $value, $url = '' ) { return $url . '?' . $key . '=' . $value; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function current_user_can() { return true; }
function wp_get_current_user() { return (object) array( 'ID' => 0, 'display_name' => 'system' ); }
function get_current_user_id() { return 0; }
function is_user_logged_in() { return false; }
