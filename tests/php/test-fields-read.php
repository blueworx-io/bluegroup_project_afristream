<?php
require_once __DIR__ . '/../../includes/fields.php';

af_test( 'expiry_date reads back in ACF display format', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, 'expiry_date', '20261231' );
	af_assert_same( '31/12/2026', afristream_license_meta( 10, 'expiry_date' ), 'Ymd becomes d/m/Y' );
	af_assert_same( '20261231', afristream_license_expiry_ymd( 10 ), 'raw form is unchanged' );
} );

af_test( 'a blank or malformed expiry_date does not become a fake date', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( '', afristream_license_meta( 10, 'expiry_date' ), 'unset reads empty' );
	update_post_meta( 10, 'expiry_date', 'not-a-date' );
	af_assert_same( 'not-a-date', afristream_license_meta( 10, 'expiry_date' ), 'unparseable passes through, not invented' );
	af_assert_same( '', afristream_license_expiry_ymd( 10 ), 'but the sortable form refuses it' );
} );

af_test( 'plain fields read back verbatim', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, 'app_password', 'hunter2' );
	update_post_meta( 10, 'license_provider', 'Shockwave' );
	update_post_meta( 10, 'mobile_active', 'Yes' );
	af_assert_same( 'hunter2', afristream_license_meta( 10, 'app_password' ), 'password' );
	af_assert_same( 'Shockwave', afristream_license_meta( 10, 'license_provider' ), 'provider' );
	af_assert_same( 'Yes', afristream_license_meta( 10, 'mobile_active' ), 'mobile' );
} );

af_test( 'ownership is a single scalar on the licence', function () {
	af_seed_post( 10, 'alpha' );
	af_assert_same( 0, afristream_license_owner( 10 ), 'unowned reads 0' );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_assert_same( 7, afristream_license_owner( 10 ), 'owner reads back as int' );
} );

af_test( 'a user holding two licences gets both, ascending', function () {
	af_seed_user( 7 );
	af_seed_post( 30, 'gamma' );
	af_seed_post( 10, 'alpha' );
	update_post_meta( 30, AFRISTREAM_LICENSE_OWNER_META, 7 );
	update_post_meta( 10, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_assert_same( array( 10, 30 ), afristream_user_license_ids( 7 ), 'both licences, sorted' );
	af_assert_same( array(), afristream_user_license_ids( 8 ), 'a user with none gets an empty array' );
} );

af_test( 'availability requires unowned, published and unexpired', function () {
	// The harness clock is frozen at 2026-07-26.
	af_seed_post( 10, 'free-future' );
	update_post_meta( 10, 'expiry_date', '20271231' );

	af_seed_post( 11, 'free-expired' );
	update_post_meta( 11, 'expiry_date', '20250101' );

	af_seed_post( 12, 'free-no-expiry' );

	af_seed_post( 13, 'taken' );
	update_post_meta( 13, 'expiry_date', '20271231' );
	update_post_meta( 13, AFRISTREAM_LICENSE_OWNER_META, 7 );

	af_seed_post( 14, 'draft', 'draft' );

	af_seed_post( 15, 'expires-today' );
	update_post_meta( 15, 'expiry_date', '20260726' );

	af_assert( afristream_license_is_available( 10 ), 'future expiry is available' );
	af_assert( ! afristream_license_is_available( 11 ), 'past expiry is not' );
	af_assert( afristream_license_is_available( 12 ), 'no expiry is available' );
	af_assert( ! afristream_license_is_available( 13 ), 'owned is not' );
	af_assert( ! afristream_license_is_available( 14 ), 'draft is not' );
	af_assert( afristream_license_is_available( 15 ), 'expiring today is still available today' );
} );

af_test( 'available licences come soonest-expiring first, never-expiring last', function () {
	af_seed_post( 10, 'far' );
	update_post_meta( 10, 'expiry_date', '20281231' );
	af_seed_post( 11, 'soon' );
	update_post_meta( 11, 'expiry_date', '20260901' );
	af_seed_post( 12, 'never' );
	af_seed_post( 13, 'also-never' );

	af_assert_same( array( 11, 10, 12, 13 ), afristream_available_licenses(), 'dated stock is spent before undated' );
	af_assert_same( array( 11, 10 ), afristream_available_licenses( 2 ), 'the limit takes from the front' );
} );
