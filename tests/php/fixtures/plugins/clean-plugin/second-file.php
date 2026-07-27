<?php
/**
 * Fixture: a second PHP file in the clean plugin.
 *
 * Its only job is to make the plugin two files deep, so a test can drop the
 * scan's file cap to one and prove that stopping early is reported as
 * "incomplete" rather than as a finished clean scan.
 *
 * @package bluegroup-project-afristream
 */

function af_fixture_clean_plugin_second() {
	return true;
}
