<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/auto-assign.php';
require_once __DIR__ . '/../../includes/configurations.php';

af_test( 'the registry is built from the filter, not a hand-written list', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array(
				'group'  => 'Licences',
				'name'   => 'A thing',
				'type'   => 'hook',
				'handle' => 'some/hook',
				'file'   => 'includes/x.php',
			);
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 1, count( $registry ), 'the contributed entry is there' );
	af_assert_same( 'A thing', $registry[0]['name'], 'intact' );
} );

af_test( 'entries are grouped in display order, not registration order', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array( 'group' => 'Admin UI', 'name' => 'Zed', 'type' => 'column', 'handle' => 'z', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'name' => 'Alpha', 'type' => 'shortcode', 'handle' => 'a', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'name' => 'Beta', 'type' => 'rest', 'handle' => 'b', 'file' => 'f.php' );
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 'Portal', $registry[0]['group'], 'Portal leads regardless of when it registered' );
	af_assert_same( 'Alpha', $registry[0]['name'], 'and sorts by name within its group' );
	af_assert_same( 'Beta', $registry[1]['name'], 'second' );
	af_assert_same( 'Admin UI', $registry[2]['group'], 'Admin UI comes later' );
} );

af_test( 'an entry missing its required parts is dropped rather than rendered broken', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array( 'group' => 'Portal', 'name' => 'Good', 'type' => 'rest', 'handle' => 'g', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'type' => 'rest' ); // no name
			$items[] = array( 'name' => 'No group', 'type' => 'rest' );
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 1, count( $registry ), 'only the complete entry survives' );
	af_assert_same( 'Good', $registry[0]['name'], 'the right one' );
} );

af_test( 'an unknown group is kept and sorted last rather than discarded', function () {
	add_filter(
		'afristream_registry',
		function ( $items ) {
			$items[] = array( 'group' => 'Something New', 'name' => 'X', 'type' => 'hook', 'handle' => 'x', 'file' => 'f.php' );
			$items[] = array( 'group' => 'Portal', 'name' => 'Y', 'type' => 'hook', 'handle' => 'y', 'file' => 'f.php' );
			return $items;
		}
	);

	$registry = afristream_registry();
	af_assert_same( 2, count( $registry ), 'nothing is lost' );
	af_assert_same( 'Portal', $registry[0]['group'], 'known groups first' );
	af_assert_same( 'Something New', $registry[1]['group'], 'unknown group still shown' );
} );

af_test( 'licence stock is counted for the page header', function () {
	af_seed_post( 10, 'free' );
	af_seed_post( 11, 'taken' );
	update_post_meta( 11, AFRISTREAM_LICENSE_OWNER_META, 7 );
	af_seed_post( 12, 'expired' );
	update_post_meta( 12, 'expiry_date', '20250101' );

	$stock = afristream_license_stock();
	af_assert_same( 3, $stock['total'], 'every published licence' );
	af_assert_same( 1, $stock['available'], 'one free and unexpired' );
	af_assert_same( 1, $stock['assigned'], 'one held' );
	af_assert_same( 1, $stock['expired'], 'one expired' );
} );

af_test( 'a licence whose expiry cannot be read is counted separately, not as available', function () {
	af_seed_post( 20, 'free' );
	af_seed_post( 21, 'unreadable-expiry' );
	update_post_meta( 21, 'expiry_date', 'not-a-date' );

	$stock = afristream_license_stock();
	af_assert_same( 2, $stock['total'], 'every published licence' );
	af_assert_same( 1, $stock['available'], 'only the genuinely free one' );
	af_assert_same( 0, $stock['assigned'], 'nothing held' );
	af_assert_same( 0, $stock['expired'], 'not counted as expired — it is not known to be' );
	af_assert_same( 1, $stock['unreadable'], 'named as something to fix' );
} );
