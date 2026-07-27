<?php
require_once __DIR__ . '/../../includes/fields.php';

/**
 * What the licence post type and its four fields are registered with.
 *
 * These arguments are the whole of the protection: there is no REST stack here
 * to fetch /wp/v2/license through and no roles to act as, so the only honest
 * way to hold "customer credentials are not published to the world" still is to
 * read back what register_post_type() and register_meta() were handed. The
 * bootstrap records both instead of discarding them.
 *
 * afristream_register_license_post_type() is called by each test rather than
 * relied on from load time, because af_reset_store() wipes what it recorded
 * before every test.
 */

af_test( 'no licence field is exposed over REST', function () {
	afristream_register_license_post_type();

	foreach ( array_keys( afristream_license_fields() ) as $key ) {
		$meta = af_registered_meta( $key );
		af_assert( is_array( $meta ), $key . ' is registered' );
		af_assert_same( false, $meta['show_in_rest'], $key . ' is not served over REST' );
	}
} );

af_test( 'app_password in particular is registered as private to the site', function () {
	afristream_register_license_post_type();

	// Named on its own because this is the field the exposure actually mattered
	// for: the licence post type is public and show_in_rest, so a meta field
	// marked show_in_rest handed every customer's streaming password to an
	// unauthenticated GET of /wp-json/wp/v2/license. WP_REST_Meta_Fields does
	// not capability-check reads, so nothing else in the registration would
	// have held it back.
	$meta = af_registered_meta( 'app_password' );
	af_assert_same( false, $meta['show_in_rest'], 'the password is not readable over REST' );
	af_assert_same( 'license', $meta['object_subtype'], 'and it belongs to the licence post type' );
	af_assert_same( true, $meta['single'], 'stored as one value' );
} );

af_test( 'writing a licence field takes more than a Contributor', function () {
	afristream_register_license_post_type();
	af_seed_post( 10, 'alpha' );

	// A Contributor: edit_posts and nothing else. The post type is registered
	// with page capabilities, so editing a licence needs edit_pages, which a
	// Contributor does not hold — and the auth callback asks about the licence
	// itself rather than about posts in general.
	af_set_capabilities( array( 'edit_posts' => true ) );

	$auth = af_registered_meta( 'app_password' )['auth_callback'];

	af_assert_same( false, (bool) call_user_func( $auth, false, 'app_password', 10, 0, 'edit_post_meta', array() ), 'a Contributor cannot write a customer password' );
	af_assert_same( false, (bool) call_user_func( $auth, false, 'app_password', 0, 0, 'add_post_meta', array() ), 'nor when no licence is named at all' );
} );

af_test( 'somebody who can edit the licence can still write its fields', function () {
	afristream_register_license_post_type();
	af_seed_post( 10, 'alpha' );

	// The capability stub answers by name and does not model map_meta_cap()'s
	// per-object reduction, so this says only that the callback asks the right
	// question — edit_post against this licence — not that WordPress would
	// resolve it a particular way.
	af_set_capabilities( array( 'edit_post' => true, 'edit_pages' => true ) );

	$auth = af_registered_meta( 'app_password' )['auth_callback'];

	af_assert_same( true, (bool) call_user_func( $auth, false, 'app_password', 10, 0, 'edit_post_meta', array() ), 'an editor of this licence may write its fields' );
	af_assert_same( true, (bool) call_user_func( $auth, false, 'app_password', 0, 0, 'add_post_meta', array() ), 'and the no-object fallback is the plural capability' );
} );

af_test( 'the licence post type is not registered with ordinary post capabilities', function () {
	afristream_register_license_post_type();

	$args = af_registered_post_type( 'license' );
	af_assert( is_array( $args ), 'the post type is registered' );
	af_assert_same( 'page', $args['capability_type'], 'licences are administered at page level, not post level — a Contributor holds edit_posts' );
	af_assert_same( true, $args['map_meta_cap'], 'so per-licence checks resolve through map_meta_cap' );
	af_assert_same( false, $args['delete_with_user'], 'and deleting a customer never deletes the licences themselves' );
} );
