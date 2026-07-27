<?php
/**
 * Proves the harness itself reports truthfully — a test suite that cannot fail
 * is worse than none, because it reads as coverage.
 */

af_test( 'post meta round-trips through the stub', function () {
	update_post_meta( 5, 'app_password', 'hunter2' );
	af_assert_same( 'hunter2', get_post_meta( 5, 'app_password', true ), 'meta reads back' );
	delete_post_meta( 5, 'app_password' );
	af_assert_same( '', get_post_meta( 5, 'app_password', true ), 'deleted meta reads empty' );
} );

af_test( 'the store resets between tests', function () {
	af_assert_same( '', get_post_meta( 5, 'app_password', true ), 'previous test did not leak' );
} );

af_test( 'add_option refuses to overwrite, as the real one does', function () {
	af_assert_same( true, add_option( 'thing', 'first', '', 'no' ), 'the first caller creates it' );
	af_assert_same( false, add_option( 'thing', 'second', '', 'no' ), 'the second is refused' );
	af_assert_same( 'first', get_option( 'thing' ), 'and the first value survives' );
} );

af_test( 'transients expire against the frozen clock', function () {
	set_transient( 'lock', '1', 30 );
	af_assert_same( '1', get_transient( 'lock' ), 'live transient reads back' );
	af_set_now( current_time( 'timestamp' ) + 31 );
	af_assert_same( false, get_transient( 'lock' ), 'expired transient reads false' );
} );
