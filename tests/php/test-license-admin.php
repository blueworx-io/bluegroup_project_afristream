<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/license-admin.php';

/**
 * afristream_save_license_fields() and afristream_log_license_created() are
 * exercised directly rather than through the meta box markup: the harness has
 * no admin-post request cycle to drive, but both functions are ordinary hooks
 * that only need $_POST and the actions WordPress fires, both of which the
 * bootstrap stubs provide honestly.
 */

af_test( 'an unreadable stored expiry_date survives a save that submits an empty date', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, 'expiry_date', 'garbage' );
	af_assert_same( false, afristream_license_expiry_is_readable( 10 ), 'sanity: the seeded value really is unreadable' );

	$_POST = array(
		'afristream_license_nonce' => 'nonce',
		'expiry_date'              => '', // the date input rendered blank; nothing was typed either way.
	);
	afristream_save_license_fields( 10 );
	unset( $_POST );

	af_assert_same( 'garbage', get_post_meta( 10, 'expiry_date', true ), 'the corrupted raw value is left in place, not deleted' );
	af_assert_same( 0, count( afristream_license_log_get( 10 ) ), 'no "updated" entry is logged for a field that was not actually changed' );
} );

af_test( 'a readable stored expiry_date is still cleared normally when an empty date is submitted', function () {
	af_seed_post( 10, 'alpha' );
	update_post_meta( 10, 'expiry_date', '20261231' );
	af_assert_same( true, afristream_license_expiry_is_readable( 10 ), 'sanity: the seeded value really is readable' );

	$_POST = array(
		'afristream_license_nonce' => 'nonce',
		'expiry_date'              => '', // the administrator genuinely cleared the field.
	);
	afristream_save_license_fields( 10 );
	unset( $_POST );

	af_assert_same( '', get_post_meta( 10, 'expiry_date', true ), 'a deliberate clear of a readable date still deletes the meta' );
	af_assert_same( 1, count( afristream_license_log_get( 10 ) ), 'and the clear is logged as an update' );
} );

af_test( 'a licence logs exactly one created entry across a publish, an edit, an unpublish and a re-publish', function () {
	// af_reset_store() wipes registered hooks before every test, including the
	// add_action() the plugin itself makes at file-load time, so the hook is
	// re-registered here. Driving it through do_action() rather than calling
	// afristream_log_license_created() directly still proves the wiring — the
	// hook name, priority and argument count all have to be right for this to
	// pass — and not just the function body in isolation.
	add_action( 'transition_post_status', 'afristream_log_license_created', 10, 3 );

	af_seed_post( 10, 'alpha', 'draft' );
	$post = (object) array(
		'ID'        => 10,
		'post_type' => 'license',
	);

	// Publish: a genuine draft → publish transition, the first ever for this licence.
	do_action( 'transition_post_status', 'publish', 'draft', $post );

	// Edit while already published: WordPress re-fires transition_post_status
	// with new === old === 'publish' on every save, which is exactly the
	// re-save case that must not add a second "created".
	do_action( 'transition_post_status', 'publish', 'publish', $post );

	// Unpublish, then publish again: a second genuine transition, but the
	// licence already has history from its first creation.
	do_action( 'transition_post_status', 'draft', 'publish', $post );
	do_action( 'transition_post_status', 'publish', 'draft', $post );

	$log = afristream_license_log_get( 10 );
	$created = array_filter( $log, function ( $entry ) {
		return 'created' === $entry['event'];
	} );

	af_assert_same( 1, count( $created ), 'exactly one created entry across the whole cycle' );
} );
