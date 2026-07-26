<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';

af_test( 'an entry records what happened, to whom, and who did it', function () {
	af_seed_post( 10, 'alpha' );
	afristream_license_log_add( 10, 'assigned', 7, 'auto-assign' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 1, count( $log ), 'one entry' );
	af_assert_same( 'assigned', $log[0]['event'], 'event' );
	af_assert_same( 7, $log[0]['user'], 'user' );
	af_assert_same( 'auto-assign', $log[0]['context'], 'context' );
	af_assert_same( 'system', $log[0]['actor'], 'actor falls back to system with no logged-in user' );
	af_assert_same( 1785024000, $log[0]['time'], 'stamped from the frozen clock' );
} );

af_test( 'the log reads newest first', function () {
	af_seed_post( 10, 'alpha' );
	afristream_license_log_add( 10, 'created', 0, 'admin' );
	af_set_now( 1785024000 + 60 );
	afristream_license_log_add( 10, 'assigned', 7, 'profile' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 'assigned', $log[0]['event'], 'most recent first' );
	af_assert_same( 'created', $log[1]['event'], 'oldest last' );
} );

af_test( 'the log is capped and drops the oldest, not the newest', function () {
	af_seed_post( 10, 'alpha' );
	for ( $i = 0; $i < AFRISTREAM_LOG_CAP + 15; $i++ ) {
		afristream_license_log_add( 10, 'updated', 0, 'admin', 'change ' . $i );
	}

	$log = afristream_license_log_get( 10 );
	af_assert_same( AFRISTREAM_LOG_CAP, count( $log ), 'capped' );
	af_assert_same( 'change ' . ( AFRISTREAM_LOG_CAP + 14 ), $log[0]['detail'], 'newest kept' );
	af_assert_same( 'change 15', $log[ AFRISTREAM_LOG_CAP - 1 ]['detail'], 'oldest dropped' );
} );

af_test( 'recent events merge across licences, newest first', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_post( 11, 'beta' );

	afristream_license_log_add( 10, 'assigned', 7, 'profile' );
	af_set_now( 1785024000 + 60 );
	afristream_license_log_add( 11, 'assigned', 8, 'auto-assign' );

	$recent = afristream_license_log_recent( 10 );
	af_assert_same( 2, count( $recent ), 'both licences appear' );
	af_assert_same( 11, $recent[0]['license'], 'newest first, tagged with its licence' );
	af_assert_same( 10, $recent[1]['license'], 'then the older one' );
	af_assert_same( 1, count( afristream_license_log_recent( 1 ) ), 'the limit applies' );
} );

af_test( 'the last assignment is findable for a manual override', function () {
	af_seed_post( 10, 'alpha' );
	afristream_license_log_add( 10, 'assigned', 7, 'auto-assign' );
	af_set_now( 1785024000 + 60 );
	afristream_license_log_add( 10, 'unassigned', 7, 'admin' );

	$last = afristream_license_last_assignment( 10 );
	af_assert_same( 7, $last['user'], 'the last person it went to, even after being freed' );
	af_assert_same( 'auto-assign', $last['context'], 'and how it got there' );

	af_seed_post( 11, 'never-assigned' );
	af_assert_same( null, afristream_license_last_assignment( 11 ), 'null when it never happened' );
} );

af_test( 'assigning and unassigning write their own log entries', function () {
	af_seed_user( 7 );
	af_seed_post( 10, 'alpha' );

	afristream_assign_license( 10, 7, 'auto-assign' );
	afristream_unassign_license( 10, 'admin' );

	$log = afristream_license_log_get( 10 );
	af_assert_same( 2, count( $log ), 'both events recorded' );
	af_assert_same( 'unassigned', $log[0]['event'], 'newest is the unassign' );
	af_assert_same( 'assigned', $log[1]['event'], 'then the assign' );
	af_assert_same( 7, $log[1]['user'], 'naming who it went to' );
} );
