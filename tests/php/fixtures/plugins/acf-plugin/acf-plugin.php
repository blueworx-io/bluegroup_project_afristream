<?php
/**
 * Fixture: an active plugin that calls ACF and would break without it.
 *
 * @package bluegroup-project-afristream
 */

function af_fixture_acf_plugin_render() {
	if ( function_exists( 'get_field' ) ) {
		return get_field( 'subtitle' );
	}
	return '';
}
