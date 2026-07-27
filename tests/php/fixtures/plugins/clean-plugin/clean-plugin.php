<?php
/**
 * Fixture: an active plugin that does not touch ACF.
 *
 * Read by the ACF-readiness scan in tests. It reads meta directly, which is
 * exactly the case the audit is not supposed to flag — those rows outlive ACF.
 *
 * @package bluegroup-project-afristream
 */

function af_fixture_clean_plugin_boot() {
	return get_post_meta( 1, 'some_key', true );
}
