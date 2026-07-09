<?php
/**
 * Plugin Name: AfriStream Customer Portal
 * Plugin URI:  https://github.com/blueworx-io/bluegroup_project_afristream
 * Description: Customer portal for AfriStream subscribers — app profile credentials, what to watch, tips & tricks, and troubleshooting guides. Rendered via the [afristream_portal] shortcode.
 * Version:     0.4.0
 * Author:      BlueWorx
 * License:     GPL-2.0-or-later
 * Text Domain: afristream-portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFRISTREAM_PORTAL_VERSION', '0.4.0' );

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
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s" data-endpoint="%s" data-editor-endpoint="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] ),
		esc_url( rest_url( 'afristream/v1/watch' ) ),
		esc_url( rest_url( 'afristream/v1/editor-picks' ) )
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

/**
 * Origin countries for the deep, filterable catalog. ISO 3166-1 → display name.
 */
function afristream_portal_countries() {
	return array(
		'US' => 'United States', 'GB' => 'United Kingdom', 'ZA' => 'South Africa',
		'NG' => 'Nigeria', 'KE' => 'Kenya', 'IN' => 'India', 'FR' => 'France',
		'ES' => 'Spain', 'KR' => 'South Korea', 'JP' => 'Japan', 'BR' => 'Brazil',
		'DE' => 'Germany', 'AU' => 'Australia', 'EG' => 'Egypt',
	);
}

/**
 * One page of TMDB discover results for a given media kind and origin country,
 * mapped onto the portal item shape with a country name attached.
 */
function afristream_portal_tmdb_discover( $kind, $cc, $country_name, $genres ) {
	$json = afristream_portal_tmdb_get(
		'/discover/' . $kind,
		array(
			'sort_by'            => 'popularity.desc',
			'with_origin_country' => $cc,
			'vote_count.gte'     => 20,
			'page'               => 1,
		)
	);
	$type    = ( 'movie' === $kind ) ? 'Movies' : 'Series';
	$items   = array();
	$results = ( $json && ! empty( $json['results'] ) ) ? $json['results'] : array();
	foreach ( $results as $row ) {
		$title = isset( $row['title'] ) ? $row['title'] : ( isset( $row['name'] ) ? $row['name'] : '' );
		if ( '' === $title ) {
			continue;
		}
		$date     = isset( $row['release_date'] ) ? $row['release_date'] : ( isset( $row['first_air_date'] ) ? $row['first_air_date'] : '' );
		$year     = substr( (string) $date, 0, 4 );
		$genre_id = ! empty( $row['genre_ids'] ) ? $row['genre_ids'][0] : 0;
		$rating   = isset( $row['vote_average'] ) ? (float) $row['vote_average'] : 0;
		$items[]  = array(
			't'        => $title,
			'genre'    => isset( $genres[ $genre_id ] ) ? $genres[ $genre_id ] : $type,
			'platform' => $rating > 0 ? '★ ' . number_format( $rating, 1 ) : 'New',
			'meta'     => ( 'Series' === $type ) ? trim( 'TV · ' . $year, ' ·' ) : $year,
			'poster'   => ! empty( $row['poster_path'] ) ? 'https://image.tmdb.org/t/p/w342' . $row['poster_path'] : null,
			'type'     => $type,
			'country'  => $country_name,
		);
	}
	return $items;
}

/**
 * Movie/series catalog from TMDB. Returns the mapped arrays, or null when no
 * key is configured or TMDB is unreachable (only successful fetches are
 * cached, for 12 hours).
 */
function afristream_portal_tmdb_catalog() {
	$cached = get_transient( 'afristream_portal_tmdb' );
	if ( false !== $cached ) {
		return $cached;
	}
	if ( ! afristream_portal_tmdb_key() ) {
		return null;
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
		return null;
	}

	$deep = array();
	$seen = array();
	foreach ( afristream_portal_countries() as $cc => $country_name ) {
		$rows = array_merge(
			afristream_portal_tmdb_discover( 'movie', $cc, $country_name, $movie_genres ),
			afristream_portal_tmdb_discover( 'tv', $cc, $country_name, $tv_genres )
		);
		foreach ( $rows as $item ) {
			if ( ! isset( $seen[ $item['t'] ] ) ) {
				$seen[ $item['t'] ] = true;
				$deep[]             = $item;
			}
		}
	}

	$catalog = array(
		'movies'  => afristream_portal_tmdb_map( $trending_movies, $movie_genres, 'Movies', 10 ),
		'series'  => afristream_portal_tmdb_map( $trending_tv, $tv_genres, 'Series', 10 ),
		'newWeek' => array_merge(
			afristream_portal_tmdb_map( $new_movies, $movie_genres, 'Movies', 4, 'New release' ),
			afristream_portal_tmdb_map( $on_air, $tv_genres, 'Series', 4, 'New episodes' )
		),
		'catalog' => $deep,
	);

	set_transient( 'afristream_portal_tmdb', $catalog, 12 * HOUR_IN_SECONDS );
	return $catalog;
}

/**
 * Major global sporting events from ESPN's public scoreboard API — keyless,
 * so this works with no configuration at all. Live events first, then the
 * soonest kick-offs over the next week, max two per competition, eight total.
 * The front-end formats `iso` into the viewer's local time. Cached 2 hours
 * so LIVE flags stay reasonably fresh. Unofficial API: any failure just
 * means the portal keeps its curated sport list.
 */
function afristream_portal_sport_events() {
	$leagues = array(
		// Football (soccer) — mostly European seasons, so quiet over the summer.
		'soccer/fifa.world'       => 'FIFA World Cup',
		'soccer/eng.1'            => 'Premier League',
		'soccer/esp.1'            => 'LaLiga',
		'soccer/ita.1'            => 'Serie A',
		'soccer/ger.1'            => 'Bundesliga',
		'soccer/fra.1'            => 'Ligue 1',
		'soccer/uefa.champions'   => 'Champions League',
		'soccer/uefa.europa'      => 'Europa League',
		'soccer/usa.1'            => 'MLS',
		// Motorsport & combat.
		'racing/f1'               => 'Formula 1',
		'mma/ufc'                 => 'UFC',
		// North American major leagues.
		'football/nfl'            => 'NFL',
		'basketball/nba'          => 'NBA',
		'baseball/mlb'            => 'MLB',
		'hockey/nhl'              => 'NHL',
		// Rugby, tennis, golf, Aussie rules. ESPN omits broadcaster names for
		// some of these; the mapping falls back to the competition label.
		'rugby/270557'            => 'URC Rugby',
		'tennis/atp'              => 'ATP Tennis',
		'tennis/wta'              => 'WTA Tennis',
		'golf/pga'                => 'PGA Tour',
		'australian-football/afl' => 'AFL',
	);
	$range  = gmdate( 'Ymd' ) . '-' . gmdate( 'Ymd', time() + 7 * DAY_IN_SECONDS );
	$events = array();

	foreach ( $leagues as $path => $label ) {
		$response = wp_remote_get(
			'https://site.api.espn.com/apis/site/v2/sports/' . $path . '/scoreboard?dates=' . $range,
			array( 'timeout' => 8 )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			continue;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['events'] ) ) {
			continue;
		}
		$count = 0;
		foreach ( $json['events'] as $event ) {
			if ( $count >= 2 ) {
				break;
			}
			$state = isset( $event['status']['type']['state'] ) ? $event['status']['type']['state'] : 'pre';
			if ( 'post' === $state ) {
				continue;
			}
			$name = ! empty( $event['name'] ) ? $event['name'] : ( isset( $event['shortName'] ) ? $event['shortName'] : '' );
			if ( '' === $name ) {
				continue;
			}
			$channel  = isset( $event['competitions'][0]['broadcasts'][0]['names'][0] ) ? $event['competitions'][0]['broadcasts'][0]['names'][0] : '';
			$events[] = array(
				'comp' => $label,
				'fx'   => str_replace( ' at ', ' vs ', $name ),
				'iso'  => isset( $event['date'] ) ? $event['date'] : '',
				'time' => 'in' === $state ? 'LIVE now' : '',
				'ch'   => '' !== $channel ? $channel : $label,
				'live' => 'in' === $state,
			);
			$count++;
		}
	}

	usort(
		$events,
		function ( $a, $b ) {
			if ( $a['live'] !== $b['live'] ) {
				return $a['live'] ? -1 : 1;
			}
			return strcmp( $a['iso'], $b['iso'] );
		}
	);
	return array_slice( $events, 0, 8 );
}

function afristream_portal_sport_cached() {
	$sport = get_transient( 'afristream_portal_sport' );
	if ( false === $sport ) {
		$sport = afristream_portal_sport_events();
		set_transient( 'afristream_portal_sport', $sport, 2 * HOUR_IN_SECONDS );
	}
	return is_array( $sport ) ? $sport : array();
}

function afristream_portal_watch_data() {
	$catalog = afristream_portal_tmdb_catalog();
	$sport   = afristream_portal_sport_cached();

	if ( ! $catalog && ! $sport ) {
		return rest_ensure_response(
			array(
				'source' => 'fallback',
				'reason' => 'no-live-data',
			)
		);
	}

	$data = array(
		'source'  => 'live',
		'updated' => gmdate( 'c' ),
		'tmdb'    => (bool) $catalog,
	);
	if ( $catalog ) {
		$data = array_merge( $data, $catalog );
	}
	if ( $sport ) {
		$data['sport'] = $sport;
	}
	return rest_ensure_response( $data );
}

/**
 * Editor Picks — curated from a public IMDb watchlist. The watchlist URL comes
 * from the afristream_imdb_watchlist_url option (default: the provided list).
 * We scrape the page's embedded JSON for IMDb ids, resolve each via TMDB for
 * consistent artwork, and keep a last-good copy so an IMDb hiccup never blanks
 * the page.
 */
function afristream_portal_imdb_watchlist_url() {
	return get_option(
		'afristream_imdb_watchlist_url',
		'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/'
	);
}

function afristream_portal_imdb_walk( $node, &$out, &$seen, &$budget, $depth = 0 ) {
	// Depth + node caps defend against hostile/huge JSON (mirrors the preview
	// server's IMDB_MAX_DEPTH / IMDB_MAX_NODES).
	if ( $budget <= 0 || $depth > 200 || ! is_array( $node ) ) {
		return;
	}
	$budget--;
	foreach ( array( 'titleId', 'constId', 'id' ) as $key ) {
		if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && preg_match( '/^tt\d+$/', $node[ $key ] ) && ! isset( $seen[ $node[ $key ] ] ) ) {
			$seen[ $node[ $key ] ] = true;
			$title                 = '';
			if ( isset( $node['titleText']['text'] ) ) {
				$title = $node['titleText']['text'];
			} elseif ( isset( $node['originalTitleText']['text'] ) ) {
				$title = $node['originalTitleText']['text'];
			} elseif ( isset( $node['title'] ) && is_string( $node['title'] ) ) {
				$title = $node['title'];
			}
			$out[] = array( 'id' => $node[ $key ], 'title' => $title );
			break;
		}
	}
	foreach ( $node as $child ) {
		if ( is_array( $child ) ) {
			afristream_portal_imdb_walk( $child, $out, $seen, $budget, $depth + 1 );
		}
	}
}

function afristream_portal_imdb_entries( $html ) {
	// Cap the body before parsing untrusted remote HTML (mirrors IMDB_MAX_BYTES).
	$html = substr( (string) $html, 0, 5000000 );
	if ( ! preg_match( '#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $m ) ) {
		return array();
	}
	$data = json_decode( $m[1], true );
	if ( ! is_array( $data ) ) {
		return array();
	}
	$out    = array();
	$seen   = array();
	$budget = 200000;
	afristream_portal_imdb_walk( $data, $out, $seen, $budget );
	return $out;
}

function afristream_portal_resolve_pick( $imdb_id, $fallback_title, $rank, $movie_genres, $tv_genres ) {
	$json  = afristream_portal_tmdb_get( '/find/' . $imdb_id, array( 'external_source' => 'imdb_id' ) );
	$movie = ! empty( $json['movie_results'] ) ? $json['movie_results'][0] : null;
	$tv    = ! empty( $json['tv_results'] ) ? $json['tv_results'][0] : null;
	$hit   = $movie ? $movie : $tv;
	if ( ! $hit ) {
		if ( '' === $fallback_title ) {
			return null;
		}
		return array(
			't' => $fallback_title, 'genre' => 'Film', 'platform' => 'IMDb',
			'meta' => '', 'poster' => null, 'type' => 'Movies', 'country' => '', 'rank' => $rank,
		);
	}
	$type      = $movie ? 'Movies' : 'Series';
	$genres    = $movie ? $movie_genres : $tv_genres;
	$date      = isset( $hit['release_date'] ) ? $hit['release_date'] : ( isset( $hit['first_air_date'] ) ? $hit['first_air_date'] : '' );
	$year      = substr( (string) $date, 0, 4 );
	$genre_id  = ! empty( $hit['genre_ids'] ) ? $hit['genre_ids'][0] : 0;
	$rating    = isset( $hit['vote_average'] ) ? (float) $hit['vote_average'] : 0;
	$countries = afristream_portal_countries();
	$cc        = ! empty( $hit['origin_country'][0] ) ? $hit['origin_country'][0] : '';
	$title     = isset( $hit['title'] ) ? $hit['title'] : ( isset( $hit['name'] ) ? $hit['name'] : $fallback_title );
	return array(
		't'        => $title,
		'genre'    => isset( $genres[ $genre_id ] ) ? $genres[ $genre_id ] : $type,
		'platform' => $rating > 0 ? '★ ' . number_format( $rating, 1 ) : 'IMDb',
		'meta'     => ( 'Series' === $type ) ? trim( 'TV · ' . $year, ' ·' ) : $year,
		'poster'   => ! empty( $hit['poster_path'] ) ? 'https://image.tmdb.org/t/p/w342' . $hit['poster_path'] : null,
		'type'     => $type,
		'country'  => isset( $countries[ $cc ] ) ? $countries[ $cc ] : '',
		'rank'     => $rank,
	);
}

function afristream_portal_editor_picks() {
	$cached = get_transient( 'afristream_portal_editor' );
	if ( false !== $cached ) {
		return $cached;
	}
	if ( ! afristream_portal_tmdb_key() ) {
		return array( 'source' => 'fallback', 'reason' => 'no-key' );
	}

	$response = wp_remote_get(
		afristream_portal_imdb_watchlist_url(),
		array( 'timeout' => 10, 'user-agent' => 'Mozilla/5.0 (AfriStream portal)' )
	);
	$last_good = get_option( 'afristream_portal_editor_lastgood', null );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'unavailable' );
	}

	$entries = array_slice( afristream_portal_imdb_entries( wp_remote_retrieve_body( $response ) ), 0, 24 );
	if ( empty( $entries ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'no-entries' );
	}

	$movie_genres = afristream_portal_tmdb_genres( 'movie' );
	$tv_genres    = afristream_portal_tmdb_genres( 'tv' );
	$picks        = array();
	$rank         = 1;
	foreach ( $entries as $entry ) {
		$pick = afristream_portal_resolve_pick( $entry['id'], $entry['title'], $rank, $movie_genres, $tv_genres );
		if ( $pick ) {
			$picks[] = $pick;
			$rank++;
		}
	}
	if ( empty( $picks ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'none-resolved' );
	}

	$payload = array( 'source' => 'imdb', 'updated' => gmdate( 'c' ), 'picks' => $picks );
	set_transient( 'afristream_portal_editor', $payload, 12 * HOUR_IN_SECONDS );
	update_option( 'afristream_portal_editor_lastgood', $payload, false );
	return $payload;
}

function afristream_portal_editor_data() {
	return rest_ensure_response( afristream_portal_editor_picks() );
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

	register_rest_route(
		'afristream/v1',
		'/editor-picks',
		array(
			'methods'             => 'GET',
			'callback'            => 'afristream_portal_editor_data',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'afristream_portal_register_rest_routes' );

/**
 * Settings → AfriStream Portal: lets the TMDB key be configured from wp-admin
 * (no wp-config.php access needed). The key is stored in the
 * afristream_tmdb_api_key option; the AFRISTREAM_TMDB_API_KEY constant still
 * takes precedence when defined.
 */
function afristream_portal_register_settings() {
	register_setting(
		'afristream_portal',
		'afristream_tmdb_api_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'afristream_portal_sanitize_tmdb_key',
			'default'           => '',
		)
	);

	add_settings_section(
		'afristream_portal_data',
		__( 'Live data sources', 'afristream-portal' ),
		'afristream_portal_settings_intro',
		'afristream-portal'
	);

	add_settings_field(
		'afristream_tmdb_api_key',
		__( 'TMDB API key', 'afristream-portal' ),
		'afristream_portal_tmdb_key_field',
		'afristream-portal',
		'afristream_portal_data',
		array( 'label_for' => 'afristream_tmdb_api_key' )
	);

	register_setting(
		'afristream_portal',
		'afristream_imdb_watchlist_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'afristream_portal_sanitize_imdb_url',
			'default'           => 'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/',
		)
	);

	add_settings_field(
		'afristream_imdb_watchlist_url',
		__( 'Editor Picks IMDb watchlist', 'afristream-portal' ),
		'afristream_portal_imdb_url_field',
		'afristream-portal',
		'afristream_portal_data',
		array( 'label_for' => 'afristream_imdb_watchlist_url' )
	);
}
add_action( 'admin_init', 'afristream_portal_register_settings' );

function afristream_portal_sanitize_tmdb_key( $value ) {
	// A new (or cleared) key should refetch immediately, not wait out the cache.
	delete_transient( 'afristream_portal_tmdb' );
	return sanitize_text_field( (string) $value );
}

function afristream_portal_sanitize_imdb_url( $value ) {
	delete_transient( 'afristream_portal_editor' );
	return esc_url_raw( trim( (string) $value ) );
}

function afristream_portal_imdb_url_field() {
	printf(
		'<input type="url" class="regular-text code" name="afristream_imdb_watchlist_url" id="afristream_imdb_watchlist_url" value="%s" autocomplete="off">',
		esc_attr( afristream_portal_imdb_watchlist_url() )
	);
	echo '<p class="description">' . esc_html__( 'Public IMDb watchlist URL powering the Editor Picks page. Titles are resolved through TMDB for artwork (needs a TMDB key). Saving refreshes the list immediately.', 'afristream-portal' ) . '</p>';
}

function afristream_portal_settings_intro() {
	echo '<p>' . esc_html__( 'Sport fixtures need no configuration — they come from a free public feed. Trending movies, series and new releases come from TMDB once an API key is saved; without one the portal shows its built-in lists.', 'afristream-portal' ) . '</p>';
}

function afristream_portal_tmdb_key_field() {
	if ( defined( 'AFRISTREAM_TMDB_API_KEY' ) && AFRISTREAM_TMDB_API_KEY ) {
		echo '<p><em>' . esc_html__( 'The key is defined in wp-config.php (AFRISTREAM_TMDB_API_KEY), which takes precedence over this setting.', 'afristream-portal' ) . '</em></p>';
		return;
	}
	printf(
		'<input type="text" class="regular-text code" name="afristream_tmdb_api_key" id="afristream_tmdb_api_key" value="%s" autocomplete="off">',
		esc_attr( get_option( 'afristream_tmdb_api_key', '' ) )
	);
	echo '<p class="description">' . wp_kses(
		__( 'Free API key (v3 auth) from <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer">themoviedb.org</a> (sign up → Settings → API). Saving refreshes the catalog immediately.', 'afristream-portal' ),
		array(
			'a' => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		)
	) . '</p>';
}

function afristream_portal_add_settings_page() {
	add_options_page(
		__( 'AfriStream Portal', 'afristream-portal' ),
		__( 'AfriStream Portal', 'afristream-portal' ),
		'manage_options',
		'afristream-portal',
		'afristream_portal_render_settings_page'
	);
}
add_action( 'admin_menu', 'afristream_portal_add_settings_page' );

function afristream_portal_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$connected = (bool) afristream_portal_tmdb_key();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'AfriStream Portal', 'afristream-portal' ); ?></h1>
		<p>
			<?php if ( $connected ) : ?>
				<strong style="color:#00a32a">&#9679;</strong>
				<?php esc_html_e( 'TMDB connected — trending movies, series and new releases are live.', 'afristream-portal' ); ?>
			<?php else : ?>
				<strong style="color:#d63638">&#9679;</strong>
				<?php esc_html_e( 'No TMDB key saved — the movie and series rows are showing the built-in lists.', 'afristream-portal' ); ?>
			<?php endif; ?>
		</p>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'afristream_portal' );
			do_settings_sections( 'afristream-portal' );
			submit_button();
			?>
		</form>
		<hr>
		<p>
			<?php
			printf(
				/* translators: %s: shortcode example. */
				esc_html__( 'Show the portal on any page with the shortcode %s (optional attributes: default_tab="profile|watch|tips|help", show_sport="true|false").', 'afristream-portal' ),
				'<code>[afristream_portal]</code>'
			);
			?>
		</p>
	</div>
	<?php
}

function afristream_portal_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'options-general.php?page=afristream-portal' ) ) . '">' . esc_html__( 'Settings', 'afristream-portal' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'afristream_portal_action_links' );
