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

/**
 * A snapshot of what license-admin.php registered at require time, taken here
 * because af_run_tests() calls af_reset_store() before every single test and
 * would otherwise wipe it before any test body could observe it. The requires
 * above are require_once, so by this line the file's top-level add_action()
 * call has already run — whether triggered by this file or an earlier one —
 * and this is the only point before the first reset where that registration
 * is still visible.
 */
$GLOBALS['af_license_admin_wiring_snapshot'] = array(
	'transition_post_status' => af_registered_actions( 'transition_post_status' ),
	'publish_license'        => af_registered_actions( 'publish_license' ),
);

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

af_test( 'a licence logs exactly one created entry across a draft edit, a publish, a re-save, an unpublish and a re-publish', function () {
	// af_reset_store() wipes registered hooks before every test, so the
	// callback is re-registered here to drive it through do_action() and
	// exercise its logic. Proof that the plugin itself performs this same
	// registration at file-load time — the hook name, priority and argument
	// count all correct — lives in the separate wiring test below, not here.
	add_action( 'transition_post_status', 'afristream_log_license_created', 10, 3 );

	af_seed_post( 10, 'alpha', 'draft' );
	$post = (object) array(
		'ID'        => 10,
		'post_type' => 'license',
	);

	// A field saved while the licence is still a draft writes an "updated"
	// entry before "created" ever exists. This is the case that used to make
	// the created-guard mistake "history has something in it" for "this
	// licence was already created" and skip logging "created" for good.
	$_POST = array(
		'afristream_license_nonce' => 'nonce',
		'app_password'             => 'hunter2',
	);
	afristream_save_license_fields( 10 );
	unset( $_POST );
	af_assert_same( 1, count( afristream_license_log_get( 10 ) ), 'the draft save logged one entry before the licence has ever been published' );

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

	af_assert_same( 1, count( $created ), 'exactly one created entry across the whole cycle, even though history began with an update' );
} );

af_test( 'a revision or autosave cannot produce a created entry', function () {
	add_action( 'transition_post_status', 'afristream_log_license_created', 10, 3 );

	af_seed_post( 10, 'alpha', 'draft' );

	// WordPress stores both a plain revision and an autosave as a post with
	// post_type 'revision' — there is no separate "autosave" type — and it is
	// that post, not the licence, whose status transition_post_status would
	// report here. afristream_log_license_created excludes anything that
	// is not post_type 'license' outright, before it ever looks at status.
	$revision = (object) array(
		'ID'        => 10,
		'post_type' => 'revision',
	);
	do_action( 'transition_post_status', 'publish', 'draft', $revision );

	af_assert_same( 0, count( afristream_license_log_get( 10 ) ), 'a revision/autosave transition writes nothing to the licence it shadows' );
} );

af_test( 'the plugin wires afristream_log_license_created to transition_post_status, not publish_license', function () {
	// Reads the require-time snapshot captured at the top of this file, not
	// live hook state — af_reset_store() has already wiped the live state by
	// the time this test body runs. This is what actually proves the wiring:
	// it would fail if the source still registered on publish_license, or
	// registered nothing at all, even though the behaviour test above passes
	// either way because it registers its own copy of the hook.
	$snapshot = $GLOBALS['af_license_admin_wiring_snapshot'];

	af_assert(
		in_array( 'afristream_log_license_created', $snapshot['transition_post_status'], true ),
		'license-admin.php registers afristream_log_license_created on transition_post_status at require time'
	);
	af_assert(
		! in_array( 'afristream_log_license_created', $snapshot['publish_license'], true ),
		'license-admin.php does not register afristream_log_license_created on publish_license'
	);
} );
