<?php
/**
 * Fixture: a single-file plugin, the shape WordPress's own hello.php has.
 *
 * It sits directly in the plugins directory, so dirname() of its entry in
 * active_plugins is "." — the case that used to point the scan at every plugin
 * on the site, including ACF's own source, and blame the result on this file.
 *
 * It does not call ACF.
 *
 * @package bluegroup-project-afristream
 */

function af_fixture_hello() {
	return 'hello';
}
