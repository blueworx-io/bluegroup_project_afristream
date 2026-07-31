<?php
/**
 * ACF is gone, and the page has to say so in the tense it actually happened in.
 *
 * What this replaces was a six-hundred-line scanner that walked every active
 * plugin, the theme and its parent, and three table scans, to answer one
 * question: is it safe to deactivate ACF yet? That question was answered on
 * 2026-07-27 by deactivating it. Afterwards every branch of the panel gave
 * advice about a decision already taken — the live site read "Something still
 * uses ACF — do not deactivate it yet" for four days after ACF was switched
 * off — and a warning that is obviously stale is a warning people learn to
 * scroll past.
 *
 * One real hazard survives the scanner, and it is the only one that was ever
 * specific to this plugin: if ACF comes back, it and includes/fields.php both
 * register the licence post type and the editor shows every field twice. That
 * needs a one-line check, not a filesystem walk.
 *
 * @package bluegroup-project-afristream
 */

af_test( 'ACF is reported as gone when nothing defines its functions', function () {
	af_assert_same( false, afristream_acf_present(), 'no ACF in the test process' );
} );

af_test( 'the retired note does not talk about deactivating anything', function () {
	ob_start();
	afristream_render_acf_state( false );
	$html = ob_get_clean();

	// The exact phrasing that was wrong on the live site.
	af_assert( false === stripos( $html, 'do not deactivate' ), 'no advice about a decision already taken' );
	af_assert( false === stripos( $html, 'safe to deactivate' ), 'nor the opposite of it' );
	af_assert( false !== stripos( $html, 'retired' ), 'says plainly that ACF is gone' );
} );

af_test( 'ACF coming back is reported as a live problem, not a future one', function () {
	ob_start();
	afristream_render_acf_state( true );
	$html = ob_get_clean();

	af_assert( false !== stripos( $html, 'twice' ), 'names the duplicate-field failure' );
	af_assert( false !== stripos( $html, 'notice-error' ), 'and does so as an error, not a note' );
} );
