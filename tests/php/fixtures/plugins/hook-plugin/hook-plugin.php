<?php
/**
 * Fixture: a plugin that extends ACF purely through its hooks and never calls
 * one of its template functions. The old detection regex could not see this.
 *
 * @package bluegroup-project-afristream
 */

add_action( 'acf/init', 'af_fixture_hook_plugin_init' );

function af_fixture_hook_plugin_init() {
	return true;
}
