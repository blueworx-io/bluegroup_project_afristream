<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/licenses.php';
require_once __DIR__ . '/../../includes/license-admin.php';
require_once __DIR__ . '/../../includes/auto-assign.php';
require_once __DIR__ . '/../../includes/configurations.php';
// Pulls in includes/shortcodes.php and includes/affiliates.php — the only two
// registry-contributing files not already required above — plus the portal's
// own declaration. ABSPATH is already defined by bootstrap.php, so the main
// file's own "exit if not loaded through WordPress" guard does not fire.
require_once dirname( __DIR__, 2 ) . '/bluegroup-project-afristream.php';

/**
 * Every afristream_registry callback each file wired up at require time.
 *
 * Captured here, before any test body runs, because af_run_tests() calls
 * af_reset_store() ahead of every single test and that wipes the filters
 * store clean — including whatever every feature file registered when it was
 * first required. Without this capture, afristream_registry() inside a test
 * below would only ever see the empty list, and the coverage tests would pass
 * for the wrong reason: not because every feature is declared, but because
 * nothing was ever asked. test-pending.php and test-license-admin.php already
 * use this same pattern for actions wired up at require time; this is the
 * same thing for a filter instead.
 *
 * By the time this line runs, every registry-contributing file has been
 * required at least once by the block above (six directly, two more via the
 * main plugin file) — whether that happened just now or earlier, while an
 * unrelated test file was loading the same shared include — so this captures
 * the complete set regardless of the order tests/php/run.php happens to glob
 * the test files in.
 */
$GLOBALS['af_registry_declarations'] = isset( $GLOBALS['af_store']['filters']['afristream_registry'] )
	? $GLOBALS['af_store']['filters']['afristream_registry']
	: array();

/**
 * Put every file's registry declaration back after af_reset_store() has
 * wiped it, so a test sees the same afristream_registry() a real page load
 * would build.
 */
function af_register_registry_declarations() {
	foreach ( $GLOBALS['af_registry_declarations'] as $fn ) {
		add_filter( 'afristream_registry', $fn );
	}
}

af_test( 'every shortcode, REST route and licence feature is declared', function () {
	af_register_registry_declarations();
	$registry = afristream_registry();

	$handles = array();
	foreach ( $registry as $item ) {
		$handles[] = $item['handle'];
	}

	$required = array(
		'afristream_portal',
		'user_acf_fields',
		'troubleshooting_guide',
		'afristream/v1/watch',
		'afristream/v1/editor-picks',
		'afristream/v1/detail',
		'afristream/v1/credentials',
		'afristream/v1/affiliate',
		'license',
		'surecart/purchase_created',
		'surecart/subscription_created',
	);

	foreach ( $required as $handle ) {
		af_assert( in_array( $handle, $handles, true ), 'the registry declares ' . $handle );
	}
} );

af_test( 'every declared entry names a real source file', function () {
	af_register_registry_declarations();
	foreach ( afristream_registry() as $item ) {
		af_assert(
			'' !== $item['file'] && file_exists( dirname( __DIR__, 2 ) . '/' . $item['file'] ),
			$item['name'] . ' points at a file that exists: ' . $item['file']
		);
	}
} );

af_test( 'every declared entry uses a known group', function () {
	af_register_registry_declarations();
	$groups = afristream_registry_groups();
	foreach ( afristream_registry() as $item ) {
		af_assert( in_array( $item['group'], $groups, true ), $item['name'] . ' is in a declared group, not "' . $item['group'] . '"' );
	}
} );
