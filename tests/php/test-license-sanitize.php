<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-admin.php';

/**
 * afristream_license_sanitize_field() is the one place a submitted value
 * becomes something safe to store, so unlike the rest of this task — form
 * rendering and $_POST handling the harness cannot meaningfully exercise —
 * it is a pure function with real branching and is tested directly.
 */

af_test( 'a valid Y-m-d date becomes Ymd', function () {
	af_assert_same( '20261231', afristream_license_sanitize_field( 'expiry_date', '2026-12-31' ), 'reformatted for storage' );
} );

af_test( 'an empty date stays empty', function () {
	af_assert_same( '', afristream_license_sanitize_field( 'expiry_date', '' ), 'nothing submitted, nothing stored' );
} );

af_test( 'a malformed or impossible date is rejected rather than guessed at', function () {
	af_assert_same( '', afristream_license_sanitize_field( 'expiry_date', 'not-a-date' ), 'garbage input' );
	// DateTime::createFromFormat() rolls an overflowing day/month into a real
	// date rather than failing outright — the round-trip check is what catches
	// that, because the rolled-over date does not format back to what was sent.
	af_assert_same( '', afristream_license_sanitize_field( 'expiry_date', '2026-13-45' ), 'impossible calendar date' );
} );

af_test( 'a select value outside its own choices becomes empty', function () {
	af_assert_same( '', afristream_license_sanitize_field( 'license_provider', 'Netflix' ), 'not one of the two real providers' );
} );

af_test( 'a radio value outside its own choices becomes empty', function () {
	af_assert_same( '', afristream_license_sanitize_field( 'mobile_active', 'Maybe' ), 'not Yes or No' );
} );

af_test( 'a valid choice passes through', function () {
	af_assert_same( 'Shockwave', afristream_license_sanitize_field( 'license_provider', 'Shockwave' ), 'select' );
	af_assert_same( 'No', afristream_license_sanitize_field( 'mobile_active', 'No' ), 'radio' );
} );

af_test( 'an unknown field key returns empty', function () {
	af_assert_same( '', afristream_license_sanitize_field( 'not_a_real_field', 'anything' ), 'no field, nothing stored' );
} );

af_test( 'a plain text field is sanitised but otherwise preserved', function () {
	af_assert_same( 'hunter2', afristream_license_sanitize_field( 'app_password', 'hunter2' ), 'ordinary value unchanged' );
	af_assert_same( 'alert(1)', afristream_license_sanitize_field( 'app_password', '<script>alert(1)</script>' ), 'tags stripped, not stored raw' );
	af_assert_same( 'has spaces', afristream_license_sanitize_field( 'app_password', '  has spaces  ' ), 'trimmed like sanitize_text_field does' );
} );
