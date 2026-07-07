<?php
/**
 * Plugin Name: AfriStream Customer Portal
 * Plugin URI:  https://github.com/blueworx-io/bluegroup_project_afristream
 * Description: Customer portal for AfriStream subscribers — app profile credentials, what to watch, tips & tricks, and troubleshooting guides. Rendered via the [afristream_portal] shortcode.
 * Version:     0.2.0
 * Author:      BlueWorx
 * License:     GPL-2.0-or-later
 * Text Domain: afristream-portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFRISTREAM_PORTAL_VERSION', '0.2.0' );

/**
 * Register (but don't enqueue) the portal assets — they only load on pages
 * that actually render the shortcode.
 */
function afristream_portal_register_assets() {
	wp_register_style(
		'afristream-portal-fonts',
		'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap',
		array(),
		null
	);
	wp_register_style(
		'afristream-portal',
		plugins_url( 'assets/portal.css', __FILE__ ),
		array( 'afristream-portal-fonts' ),
		AFRISTREAM_PORTAL_VERSION
	);
	wp_register_script(
		'afristream-portal',
		plugins_url( 'assets/portal.js', __FILE__ ),
		array(),
		AFRISTREAM_PORTAL_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'afristream_portal_register_assets' );

/**
 * [afristream_portal default_tab="profile" show_sport="true"]
 *
 * default_tab: profile | watch | tips | help
 */
function afristream_portal_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'default_tab' => 'profile',
			'show_sport'  => 'true',
		),
		$atts,
		'afristream_portal'
	);

	wp_enqueue_style( 'afristream-portal' );
	wp_enqueue_script( 'afristream-portal' );

	return sprintf(
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s" data-endpoint="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] ),
		esc_url( rest_url( 'afristream/v1/watch' ) )
	);
}
add_shortcode( 'afristream_portal', 'afristream_portal_shortcode' );

/**
 * TMDB integration for the What to Watch section.
 *
 * The key comes from the AFRISTREAM_TMDB_API_KEY constant (wp-config.php) or
 * the afristream_tmdb_api_key option. Without a key the endpoint returns
 * source:"fallback" and the front-end keeps its built-in curated lists.
 * Data use is non-commercial per TMDB's free tier; the front-end shows TMDB
 * attribution whenever live data is displayed.
 */
function afristream_portal_tmdb_key() {
	if ( defined( 'AFRISTREAM_TMDB_API_KEY' ) && AFRISTREAM_TMDB_API_KEY ) {
		return AFRISTREAM_TMDB_API_KEY;
	}
	return get_option( 'afristream_tmdb_api_key', '' );
}

function afristream_portal_tmdb_get( $path, $args = array() ) {
	$args['api_key'] = afristream_portal_tmdb_key();
	$response        = wp_remote_get(
		add_query_arg( $args, 'https://api.themoviedb.org/3' . $path ),
		array( 'timeout' => 10 )
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $json ) ? $json : null;
}

function afristream_portal_tmdb_genres( $type ) {
	$json = afristream_portal_tmdb_get( '/genre/' . $type . '/list' );
	$map  = array();
	if ( $json && ! empty( $json['genres'] ) ) {
		foreach ( $json['genres'] as $genre ) {
			$map[ $genre['id'] ] = $genre['name'];
		}
	}
	return $map;
}

/**
 * Map raw TMDB results onto the shape portal.js renders:
 * { t, genre, platform (rating badge), meta, poster, type }.
 * $meta_label overrides the year-based meta line (used for New This Week).
 */
function afristream_portal_tmdb_map( $json, $genres, $type, $limit, $meta_label = '' ) {
	$items   = array();
	$results = ( $json && ! empty( $json['results'] ) ) ? $json['results'] : array();
	foreach ( $results as $row ) {
		if ( count( $items ) >= $limit ) {
			break;
		}
		$title = isset( $row['title'] ) ? $row['title'] : ( isset( $row['name'] ) ? $row['name'] : '' );
		if ( '' === $title ) {
			continue;
		}
		$date     = isset( $row['release_date'] ) ? $row['release_date'] : ( isset( $row['first_air_date'] ) ? $row['first_air_date'] : '' );
		$year     = substr( (string) $date, 0, 4 );
		$genre_id = ! empty( $row['genre_ids'] ) ? $row['genre_ids'][0] : 0;
		$rating   = isset( $row['vote_average'] ) ? (float) $row['vote_average'] : 0;
		if ( '' === $meta_label ) {
			$meta = ( 'Series' === $type ) ? trim( 'TV · ' . $year, ' ·' ) : $year;
		} else {
			$meta = $meta_label;
		}
		$items[] = array(
			't'        => $title,
			'genre'    => isset( $genres[ $genre_id ] ) ? $genres[ $genre_id ] : $type,
			'platform' => $rating > 0 ? '★ ' . number_format( $rating, 1 ) : 'New',
			'meta'     => $meta,
			'poster'   => ! empty( $row['poster_path'] ) ? 'https://image.tmdb.org/t/p/w342' . $row['poster_path'] : null,
			'type'     => $type,
		);
	}
	return $items;
}

function afristream_portal_watch_data() {
	$cached = get_transient( 'afristream_portal_watch' );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	if ( ! afristream_portal_tmdb_key() ) {
		return rest_ensure_response(
			array(
				'source' => 'fallback',
				'reason' => 'no-key',
			)
		);
	}

	$movie_genres = afristream_portal_tmdb_genres( 'movie' );
	$tv_genres    = afristream_portal_tmdb_genres( 'tv' );

	$trending_movies = afristream_portal_tmdb_get( '/trending/movie/week' );
	$trending_tv     = afristream_portal_tmdb_get( '/trending/tv/week' );
	$new_movies      = afristream_portal_tmdb_get(
		'/discover/movie',
		array(
			'sort_by'           => 'popularity.desc',
			'with_release_type' => '4|6',
			'release_date.gte'  => gmdate( 'Y-m-d', time() - 21 * DAY_IN_SECONDS ),
			'release_date.lte'  => gmdate( 'Y-m-d' ),
		)
	);
	$on_air          = afristream_portal_tmdb_get( '/tv/on_the_air' );

	if ( ! $trending_movies && ! $trending_tv ) {
		return rest_ensure_response(
			array(
				'source' => 'fallback',
				'reason' => 'tmdb-unreachable',
			)
		);
	}

	$data = array(
		'source'  => 'tmdb',
		'updated' => gmdate( 'c' ),
		'movies'  => afristream_portal_tmdb_map( $trending_movies, $movie_genres, 'Movies', 10 ),
		'series'  => afristream_portal_tmdb_map( $trending_tv, $tv_genres, 'Series', 10 ),
		'newWeek' => array_merge(
			afristream_portal_tmdb_map( $new_movies, $movie_genres, 'Movies', 4, 'New release' ),
			afristream_portal_tmdb_map( $on_air, $tv_genres, 'Series', 4, 'New episodes' )
		),
	);

	set_transient( 'afristream_portal_watch', $data, 12 * HOUR_IN_SECONDS );
	return rest_ensure_response( $data );
}

function afristream_portal_register_rest_routes() {
	register_rest_route(
		'afristream/v1',
		'/watch',
		array(
			'methods'             => 'GET',
			'callback'            => 'afristream_portal_watch_data',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'afristream_portal_register_rest_routes' );
