<?php
require_once __DIR__ . '/../../includes/fields.php';
require_once __DIR__ . '/../../includes/license-log.php';
require_once __DIR__ . '/../../includes/licenses.php';

/**
 * The licence list table's sort, exercised through the one thing the hook
 * actually touches: the query variables it sets.
 *
 * There is no WP_Query here, and standing one up would be standing up the
 * database with it. What can be pinned down without either is which variables
 * the hook writes — and that is precisely where the bug was. Setting meta_key
 * and ordering by meta_value makes WordPress build an INNER JOIN onto postmeta,
 * so a licence with no expiry_date row is not merely sorted oddly, it is
 * dropped from the list entirely. A meta_query pairing EXISTS with NOT EXISTS
 * is joined as a LEFT JOIN instead, which keeps every licence in the list.
 */
class AF_Fake_Query {
	/** @var array<string,mixed> Query vars, as set(). */
	public $vars = array();

	/** @var bool Whether this stands in for the main query. */
	public $main = true;

	/**
	 * @param array<string,mixed> $vars Starting query vars.
	 * @param bool                $main Whether it is the main query.
	 */
	public function __construct( $vars = array(), $main = true ) {
		$this->vars = $vars;
		$this->main = $main;
	}

	public function is_main_query() {
		return $this->main;
	}

	/**
	 * @param string $key Query var.
	 * @return mixed '' for anything unset, as WP_Query::get() answers.
	 */
	public function get( $key ) {
		return array_key_exists( $key, $this->vars ) ? $this->vars[ $key ] : '';
	}

	/**
	 * @param string $key   Query var.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->vars[ $key ] = $value;
	}
}

af_test( 'sorting by expiry date keeps the licences that have no expiry date', function () {
	af_set_admin();

	$query = new AF_Fake_Query(
		array(
			'post_type' => 'license',
			'orderby'   => 'expiry_date',
			'order'     => 'asc',
		)
	);

	afristream_portal_sort_license_columns( $query );

	af_assert_same( '', $query->get( 'meta_key' ), 'meta_key is left alone — setting it is what forces the inner join' );

	$meta = $query->get( 'meta_query' );
	af_assert_same( 'OR', $meta['relation'], 'the two clauses are OR-ed, which is what makes the join a left join' );
	af_assert_same( 'expiry_date', $meta['afristream_value']['key'], 'one clause for the licences that have a value' );
	af_assert_same( 'EXISTS', $meta['afristream_value']['compare'], 'matched by existence, not by value' );
	af_assert_same( 'expiry_date', $meta['afristream_none']['key'], 'and one for the licences that do not' );
	af_assert_same( 'NOT EXISTS', $meta['afristream_none']['compare'], 'which is what keeps them in the list' );

	af_assert_same( array( 'afristream_value' => 'ASC' ), $query->get( 'orderby' ), 'ordered by the named clause' );
} );

af_test( 'the direction the column header asked for is honoured', function () {
	af_set_admin();

	$query = new AF_Fake_Query(
		array(
			'post_type' => 'license',
			'orderby'   => 'mobile_active',
			'order'     => 'DESC',
		)
	);

	afristream_portal_sort_license_columns( $query );

	// A per-clause direction overrides the query's own 'order', so leaving it
	// out would pin the column to ascending whichever way the arrow was clicked.
	af_assert_same( array( 'afristream_value' => 'DESC' ), $query->get( 'orderby' ), 'descending stays descending' );
	af_assert_same( 'mobile_active', $query->get( 'meta_query' )['afristream_value']['key'], 'on the column that was clicked' );
} );

af_test( 'an unrelated sort, query or screen is left completely alone', function () {
	af_set_admin();

	$title = new AF_Fake_Query( array( 'post_type' => 'license', 'orderby' => 'title' ) );
	afristream_portal_sort_license_columns( $title );
	af_assert_same( 'title', $title->get( 'orderby' ), 'sorting by title is none of this hook\'s business' );
	af_assert_same( '', $title->get( 'meta_query' ), 'and no meta_query is imposed on it' );

	$other = new AF_Fake_Query( array( 'post_type' => 'post', 'orderby' => 'expiry_date' ) );
	afristream_portal_sort_license_columns( $other );
	af_assert_same( '', $other->get( 'meta_query' ), 'another post type is not touched' );

	$secondary = new AF_Fake_Query( array( 'post_type' => 'license', 'orderby' => 'expiry_date' ), false );
	afristream_portal_sort_license_columns( $secondary );
	af_assert_same( '', $secondary->get( 'meta_query' ), 'and neither is a secondary query' );

	af_set_admin( false );
	$front = new AF_Fake_Query( array( 'post_type' => 'license', 'orderby' => 'expiry_date' ) );
	afristream_portal_sort_license_columns( $front );
	af_assert_same( '', $front->get( 'meta_query' ), 'nor anything running on the front end' );
} );
