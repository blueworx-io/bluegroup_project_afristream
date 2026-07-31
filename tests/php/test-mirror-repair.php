<?php
/**
 * The usermeta mirror repairs itself instead of asking somebody to.
 *
 * The mirror is derived data: a copy of the licences pointing at a user, kept
 * in the shape ACF wrote so existing readers keep working. When it disagrees
 * with the licences it comes from, the licences are right and the copy is
 * stale — there is no judgement to make, and no information a person has that
 * the plugin does not.
 *
 * It was reported and not fixed. On the live site that meant one user sat in
 * "Users whose stored licence list disagrees with their licences" indefinitely,
 * and clearing it meant somebody opening that profile and pressing Update — a
 * manual step whose entire effect is to call afristream_rebuild_user_mirror(),
 * which this plugin can call itself.
 *
 * Deliberately unlike the over-allocation report next to it, which stays a
 * report: taking a lapsed-then-recovered customer's licence away is a decision
 * about a person's access. Rewriting a derived copy to match its source is not.
 *
 * @package bluegroup-project-afristream
 */

af_test( 'a stale mirror is rewritten from the licences it should have matched', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_user( 7 );
	afristream_assign_license( 10, 7, 'test' );

	// The state the live site was in: the licence points at the user, the
	// user's copy of that fact says something else. Written directly, which is
	// the only way this happens — every path through the plugin rebuilds it.
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '99' ) );
	af_assert_same( array( 7 ), afristream_mirror_mismatches(), 'stale to begin with' );

	$repaired = afristream_repair_user_mirrors();

	af_assert_same( array( 7 ), $repaired['repaired'], 'the user was put right' );
	af_assert_same( array(), $repaired['failed'], 'and nothing was left over' );
	af_assert_same( array( 10 ), afristream_user_mirror_ids( 7 ), 'the copy now matches the licence' );
	af_assert_same( array(), afristream_mirror_mismatches(), 'and the page has nothing to report' );
} );

af_test( 'a user who holds nothing has their leftover copy cleared', function () {
	af_seed_user( 7 );
	// A licence they used to hold, still listed against them after it moved on.
	update_user_meta( 7, AFRISTREAM_USER_LICENSE_META, array( '10' ) );

	$repaired = afristream_repair_user_mirrors();

	af_assert_same( array( 7 ), $repaired['repaired'], 'an emptied mirror is a repair too' );
	af_assert_same( array(), afristream_user_mirror_ids( 7 ), 'nothing claimed any more' );
} );

af_test( 'a site with nothing wrong is not written to', function () {
	af_seed_post( 10, 'alpha' );
	af_seed_user( 7 );
	afristream_assign_license( 10, 7, 'test' );

	$repaired = afristream_repair_user_mirrors();

	af_assert_same( array(), $repaired['repaired'], 'nothing to do' );
	af_assert_same( array(), $repaired['failed'], 'and nothing failed' );
	af_assert_same( array( 10 ), afristream_user_mirror_ids( 7 ), 'left exactly as it was' );
} );

af_test( 'the repair runs on a schedule, so nobody has to open the page for it', function () {
	afristream_schedule_mirror_repair();

	af_assert(
		false !== wp_next_scheduled( AFRISTREAM_MIRROR_REPAIR_HOOK ),
		'a site nobody visits still converges'
	);
} );

af_test( 'scheduling twice does not queue it twice', function () {
	afristream_schedule_mirror_repair();
	$first = wp_next_scheduled( AFRISTREAM_MIRROR_REPAIR_HOOK );
	afristream_schedule_mirror_repair();

	af_assert_same( $first, wp_next_scheduled( AFRISTREAM_MIRROR_REPAIR_HOOK ), 'the existing run stands' );
} );
