<?php
/**
 * The smallest test harness that does the job. Not PHPUnit, because adding a
 * composer dependency to a plugin that has none — and getting it approved in
 * approved-deps.json — costs more than the fifty lines below.
 */

$GLOBALS['af_tests']    = array();
$GLOBALS['af_failures'] = array();
$GLOBALS['af_current']  = '';
$GLOBALS['af_count']    = 0;

function af_test( $name, $fn ) {
	$GLOBALS['af_tests'][] = array( $name, $fn );
}

function af_fail( $message ) {
	$GLOBALS['af_failures'][] = $GLOBALS['af_current'] . ' — ' . $message;
}

function af_assert( $cond, $message ) {
	$GLOBALS['af_count']++;
	if ( ! $cond ) {
		af_fail( $message );
	}
}

function af_assert_same( $expected, $actual, $message ) {
	$GLOBALS['af_count']++;
	if ( $expected !== $actual ) {
		af_fail(
			$message . "\n      expected: " . var_export( $expected, true )
			. "\n      actual:   " . var_export( $actual, true )
		);
	}
}

function af_run_tests() {
	foreach ( $GLOBALS['af_tests'] as $test ) {
		list( $name, $fn ) = $test;
		$GLOBALS['af_current'] = $name;
		af_reset_store();
		$fn();
	}

	$failures = $GLOBALS['af_failures'];
	$tests    = count( $GLOBALS['af_tests'] );
	$asserts  = $GLOBALS['af_count'];

	if ( empty( $failures ) ) {
		echo "PHP tests: {$tests} tests, {$asserts} assertions, all passing\n";
		return 0;
	}

	echo "PHP tests: {$tests} tests, {$asserts} assertions, " . count( $failures ) . " FAILED\n\n";
	foreach ( $failures as $failure ) {
		echo "  ✗ {$failure}\n";
	}
	echo "\n";
	return 1;
}
