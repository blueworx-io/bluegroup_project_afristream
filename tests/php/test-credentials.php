<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/licenses.php';

af_test( 'credentials list one profile per licence, numbered in order', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'BabyBlue123' );
	af_seed_post( 11, 'BabyBlue-TV' );
	update_post_meta( 10, 'app_password', 'pass-one' );
	update_post_meta( 11, 'app_password', 'pass-two' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );

	$profiles = afristream_portal_credentials_for_user( 7 );

	af_assert_same( 2, count( $profiles ), 'one entry per licence' );
	af_assert_same( 'Profile 1', $profiles[0]['label'], 'first is Profile 1' );
	af_assert_same( 'BabyBlue123', $profiles[0]['user'], 'username is the licence title' );
	af_assert_same( 'pass-one', $profiles[0]['pass'], 'password is the licence field' );
	af_assert_same( 'Profile 2', $profiles[1]['label'], 'second is Profile 2' );
	af_assert_same( 'BabyBlue-TV', $profiles[1]['user'], 'and its own title' );
} );

af_test( 'a user with no licences gets an empty list, not a placeholder', function () {
	af_seed_user( 7 );
	af_assert_same( array(), afristream_portal_credentials_for_user( 7 ), 'empty' );
} );

af_test( 'a licence with neither title nor password is skipped, not shown blank', function () {
	af_seed_user( 7 );
	af_seed_post( 10, '' );
	af_seed_post( 11, 'BabyBlue-TV' );
	update_post_meta( 11, 'app_password', 'pass-two' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );

	$profiles = afristream_portal_credentials_for_user( 7 );
	af_assert_same( 1, count( $profiles ), 'the empty licence is skipped' );
	af_assert_same( 'Profile 1', $profiles[0]['label'], 'and numbering closes up rather than skipping to 2' );
} );

af_test( 'a licence with only a title or only a password still appears, only neither is skipped', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'BabyBlue-TitleOnly' );
	af_seed_post( 11, '' );
	update_post_meta( 11, 'app_password', 'pass-only' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );

	$profiles = afristream_portal_credentials_for_user( 7 );

	af_assert_same( 2, count( $profiles ), 'both half-filled licences appear' );
	af_assert_same( 'BabyBlue-TitleOnly', $profiles[0]['user'], 'title-only keeps its title' );
	af_assert_same( '', $profiles[0]['pass'], 'title-only has no password' );
	af_assert_same( '', $profiles[1]['user'], 'password-only has no title' );
	af_assert_same( 'pass-only', $profiles[1]['pass'], 'password-only keeps its password' );
} );

af_test( 'afristream_portal_user_credentials() is empty when nobody is logged in', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'BabyBlue123' );
	update_post_meta( 10, 'app_password', 'pass-one' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );

	// Logged out is the bootstrap default, but set it explicitly so this test
	// does not depend on that default never changing.
	af_set_current_user( 0 );
	af_assert_same( array(), afristream_portal_user_credentials(), 'no session, no credentials, regardless of who holds a licence' );

	af_set_current_user( 7 );
	af_assert_same( 1, count( afristream_portal_user_credentials() ), 'sanity: the same licence appears once someone is logged in' );
} );
