<?php
/**
 * Runs every tests/php/test-*.php against the in-memory stubs.
 * Exit code 1 on any failure so CI and npm treat it as a failing build.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/assert.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require_once $file;
}

exit( af_run_tests() );
