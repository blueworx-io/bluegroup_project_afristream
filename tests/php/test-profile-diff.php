<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/license-admin.php';

af_test( 'the profile diff only touches what actually changed', function () {
	$diff = afristream_license_selection_diff( array( 10, 11 ), array( 11, 12 ) );
	af_assert_same( array( 12 ), $diff['add'], 'newly selected' );
	af_assert_same( array( 10 ), $diff['remove'], 'deselected' );
} );

af_test( 'an unchanged selection produces no writes at all', function () {
	$diff = afristream_license_selection_diff( array( 10, 11 ), array( 11, 10 ) );
	af_assert_same( array(), $diff['add'], 'nothing to add regardless of order' );
	af_assert_same( array(), $diff['remove'], 'nothing to remove' );
} );

af_test( 'clearing the selection removes every licence', function () {
	$diff = afristream_license_selection_diff( array( 10, 11 ), array() );
	af_assert_same( array(), $diff['add'], 'nothing added' );
	af_assert_same( array( 10, 11 ), $diff['remove'], 'both removed' );
} );

af_test( 'duplicates and junk in the submission are ignored', function () {
	$diff = afristream_license_selection_diff( array(), array( 12, 12, 0, -3 ) );
	af_assert_same( array( 12 ), $diff['add'], 'deduped, and non-ids dropped' );
} );

/**
 * The four tests below exercise the save path itself, not just the pure diff,
 * because the thing under test is what afristream_save_user_license_field()
 * does with a WP_Error it gets back from afristream_assign_license() or
 * afristream_unassign_license() — the brief this file came from was written
 * before those two returned anything but a plain bool, and silently ignoring
 * the refusal would tell an administrator a save worked while a customer's
 * actual licences disagree with what the profile screen now shows.
 *
 * Forcing a refusal is done by holding the assignment lock for the whole save,
 * via afristream_with_lock(), the same technique test-fields-write.php uses to
 * exercise 'afristream_locked'. What matters here is not which of the several
 * error codes fires — that is already covered where each one is produced — but
 * that whichever one does is collected and surfaced rather than dropped, and
 * that an addition and a removal are told apart when both happen at once.
 */

af_test( 'a refused addition is surfaced, not silently dropped', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' ); // free, so it is a candidate for addition.

	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array( '10' ),
	);
	afristream_with_lock(
		function () {
			afristream_save_user_license_field( 7 );
		}
	);
	unset( $_POST );

	af_assert_same( 0, afristream_license_owner( 10 ), 'the refused claim wrote nothing' );

	ob_start();
	afristream_license_refused_notice();
	$html = ob_get_clean();

	af_assert( false !== strpos( $html, 'could not be assigned' ), 'the admin is told the addition was refused' );
	af_assert( false === strpos( $html, 'could not be removed' ), 'and it is not described as a removal problem' );
} );

af_test( 'a refused removal is surfaced, not silently dropped', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );
	afristream_assign_license( 10, 7, 'test' );

	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array(), // deselected
	);
	afristream_with_lock(
		function () {
			afristream_save_user_license_field( 7 );
		}
	);
	unset( $_POST );

	af_assert_same( 7, afristream_license_owner( 10 ), 'the refused release changed nothing — the customer still holds it' );

	ob_start();
	afristream_license_refused_notice();
	$html = ob_get_clean();

	af_assert( false !== strpos( $html, 'could not be removed' ), 'the admin is told the removal was refused' );
	af_assert( false === strpos( $html, 'could not be assigned' ), 'and it is not described as an assignment problem' );
} );

af_test( 'a refused addition and a refused removal in the same save are both surfaced', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' ); // held by 7, being deselected
	af_seed_post( 11, 'beta' );  // free, being selected
	afristream_assign_license( 10, 7, 'test' );

	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array( '11' ),
	);
	afristream_with_lock(
		function () {
			afristream_save_user_license_field( 7 );
		}
	);
	unset( $_POST );

	af_assert_same( 7, afristream_license_owner( 10 ), 'the removal was refused' );
	af_assert_same( 0, afristream_license_owner( 11 ), 'the addition was refused' );

	ob_start();
	afristream_license_refused_notice();
	$html = ob_get_clean();

	af_assert( false !== strpos( $html, 'could not be assigned' ), 'the refused addition is reported' );
	af_assert( false !== strpos( $html, 'could not be removed' ), 'the refused removal is reported too — neither one hides the other' );
} );

af_test( 'a save with nothing refused leaves no notice behind', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array( '10' ),
	);
	afristream_save_user_license_field( 7 );
	unset( $_POST );

	af_assert_same( 7, afristream_license_owner( 10 ), 'the claim actually succeeded' );

	ob_start();
	afristream_license_refused_notice();
	$html = ob_get_clean();

	af_assert_same( '', $html, 'nothing was refused, so nothing is printed' );
} );

/**
 * afristream_save_user_license_field() is gated on edit_users, not the
 * singular edit_user — see the doc comment on the function itself for why:
 * edit_user collapses to read for a user's own account under WordPress's
 * map_meta_cap(), which would otherwise let any logged-in user assign
 * themselves a licence. These two tests pin that gate down directly, with a
 * request that is otherwise entirely well-formed — valid nonce, a genuinely
 * free licence being selected — so a regression back to edit_user would still
 * pass every other test in this file (they never restrict capabilities) but
 * fail here.
 */

af_test( 'a user without edit_users cannot save a licence selection, even with an otherwise well-formed request', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' ); // free — nothing about the licence itself would block this claim.

	af_set_capabilities( array( 'edit_users' => false ) );

	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array( '10' ),
	);
	afristream_save_user_license_field( 7 );
	unset( $_POST );

	af_assert_same( 0, afristream_license_owner( 10 ), 'no assignment happened without edit_users, valid nonce notwithstanding' );

	ob_start();
	afristream_license_refused_notice();
	$html = ob_get_clean();

	af_assert_same( '', $html, 'the save returned before it ever reached the refusal-collecting code, so nothing is queued to report' );
} );

af_test( 'a user with edit_users can still save, both on their own profile and on someone else\'s', function () {
	af_seed_user( 7 );
	af_seed_user( 9 );
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	af_set_capabilities( array( 'edit_users' => true ) );

	// personal_options_update: the profile being saved is the acting user's own.
	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array( '10' ),
	);
	afristream_save_user_license_field( 7 );
	unset( $_POST );
	af_assert_same( 7, afristream_license_owner( 10 ), 'an administrator holding edit_users can still save their own profile' );

	// edit_user_profile_update: the profile being saved belongs to someone else.
	$_POST = array(
		'afristream_user_license_nonce' => 'nonce',
		'afristream_active_license'     => array( '11' ),
	);
	afristream_save_user_license_field( 9 );
	unset( $_POST );
	af_assert_same( 9, afristream_license_owner( 11 ), 'and can still save a different user\'s profile' );
} );
