<?php
/**
 * The Configurations page has to show what an unresolved SureCart event was,
 * not merely that there was one.
 *
 * This exists because of a real failure. On the live site the status line read
 * "2 SureCart events arrived carrying no customer this plugin could recognise"
 * and there was nowhere to go from there: afristream_record_unresolved_event()
 * had already captured the shape of both payloads, and the page printed only
 * the count. The one fact needed to fix the bug was collected and then hidden,
 * so diagnosing it meant asking somebody to install a throwaway plugin on a
 * production site to read an option back out.
 *
 * @package bluegroup-project-afristream
 */

af_test( 'the unresolved-event panel names the attributes that were missing', function () {
	// The shape the live site actually recorded: a model whose attributes sit
	// behind __get, carrying a bare customer reference and no user_id.
	afristream_autoassign_from_surecart( new AF_Fake_SureCart_Model( array( 'customer_id' => 'cus_123' ) ) );

	ob_start();
	afristream_render_unresolved_events( afristream_unresolved_events() );
	$html = ob_get_clean();

	af_assert( false !== strpos( $html, 'AF_Fake_SureCart_Model' ), 'the payload class is shown' );
	af_assert( false !== strpos( $html, 'user_id: missing' ), 'the attribute that was looked for and absent' );
	af_assert( false !== strpos( $html, 'customer_id: present' ), 'the attribute that was actually there' );
} );

af_test( 'the panel prints nothing at all when there is nothing wrong', function () {
	ob_start();
	afristream_render_unresolved_events( array() );
	$html = ob_get_clean();

	af_assert_same( '', trim( $html ), 'no empty box on a healthy site' );
} );

af_test( 'a payload shape is escaped rather than trusted', function () {
	// The recorded keys come from a third party's webhook. They are printed
	// into an admin page, so they are attacker-influenced output.
	afristream_autoassign_from_surecart( array( '<script>alert(1)</script>' => 'x' ) );

	ob_start();
	afristream_render_unresolved_events( afristream_unresolved_events() );
	$html = ob_get_clean();

	af_assert( false === strpos( $html, '<script>' ), 'no raw script tag reaches the page' );
} );
