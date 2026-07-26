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

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['af_store']['postmeta'][ $post_id ][ $key ] );
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

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['af_store']['usermeta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['af_store']['usermeta'][ $user_id ][ $key ] );
	return true;
}

// -- Options and transients ---------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['af_store']['options'] ) ? $GLOBALS['af_store']['options'][ $key ] : $default;
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
