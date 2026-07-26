<?php
/**
 * Fixture: a parent theme that uses ACF, with a child theme active over it.
 *
 * This is the common Elementor shape, and the reason the scan cannot stop at
 * get_stylesheet_directory(): the child holds a stylesheet and the parent holds
 * the template code that would break.
 *
 * @package bluegroup-project-afristream
 */

function af_fixture_acf_parent_theme_row() {
	the_sub_field( 'heading' );
}
