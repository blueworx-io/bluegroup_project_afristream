<?php
/**
 * Plugin Name: BlueGroup | AfriStream Portal
 * Plugin URI:  https://github.com/blueworx-io/bluegroup_project_afristream
 * Description: Customer portal for AfriStream subscribers — app profile credentials, what to watch, tips & tricks, and troubleshooting guides. Rendered via the [afristream_portal] shortcode.
 * Version:     0.21.0
 * Author:      BlueWorx
 * License:     GPL-2.0-or-later
 * Text Domain: bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFRISTREAM_PORTAL_VERSION', '0.21.0' );

/**
 * Most Editor Picks to resolve. One TMDB round-trip per pick on a cold cache,
 * so this is deliberately generous rather than unlimited; the resolve loop is
 * additionally time-budgeted (see afristream_portal_pick_budget).
 */
define( 'AFRISTREAM_PORTAL_PICK_CAP', 300 );

require_once plugin_dir_path( __FILE__ ) . 'includes/fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/license-log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/license-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/licenses.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/affiliates.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/auto-assign.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/configurations.php';

/**
 * Register (but don't enqueue) the portal assets — they only load on pages
 * that actually render the shortcode.
 */
function afristream_portal_register_assets() {
	wp_register_style(
		'bluegroup-project-afristream-fonts',
		'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap',
		array(),
		null
	);
	wp_register_style(
		'bluegroup-project-afristream',
		plugins_url( 'assets/portal.css', __FILE__ ),
		array( 'bluegroup-project-afristream-fonts' ),
		AFRISTREAM_PORTAL_VERSION
	);
	wp_register_script(
		'bluegroup-project-afristream',
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
 * default_tab: profile | setup | watch | apps | editor | download | affiliate | tips | help
 *
 * 'tips' and 'help' are hidden from the portal nav but remain valid entry points.
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

	wp_enqueue_style( 'bluegroup-project-afristream' );
	wp_enqueue_script( 'bluegroup-project-afristream' );

	// Page-scoped host-theme fix: drop the right dashboard column's padding so the
	// portal sits flush. Attached to the portal handle, which only prints on pages
	// that actually render this shortcode — so no other page is affected.
	wp_add_inline_style( 'bluegroup-project-afristream', '.dashboard-right{padding:0 !important;}' );

	return sprintf(
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s" data-endpoint="%s" data-editor-endpoint="%s" data-detail-endpoint="%s" data-credentials-endpoint="%s" data-affiliate-endpoint="%s" data-apps-url="%s" data-rest-nonce="%s" data-home-url="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] ),
		esc_url( rest_url( 'afristream/v1/watch' ) ),
		esc_url( rest_url( 'afristream/v1/editor-picks' ) ),
		esc_url( rest_url( 'afristream/v1/detail' ) ),
		esc_url( rest_url( 'afristream/v1/credentials' ) ),
		esc_url( rest_url( 'afristream/v1/affiliate' ) ),
		esc_url( add_query_arg( 'ver', AFRISTREAM_PORTAL_VERSION, plugins_url( 'data/apps.json', __FILE__ ) ) ),
		esc_attr( wp_create_nonce( 'wp_rest' ) ),
		esc_url( home_url( '/' ) )
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
			'id'       => isset( $row['id'] ) ? (int) $row['id'] : 0,
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
			'id'       => isset( $row['id'] ) ? (int) $row['id'] : 0,
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
	// Each league maps to array( label, code, country ): `code` is the sporting
	// code (drives the "Sport Type" filter) and `country` the host nation (or
	// 'International' for global competitions). Keep in sync with the preview
	// server's ESPN_LEAGUES.
	$leagues = array(
		// Soccer — mostly European seasons, so quiet over the summer.
		'soccer/fifa.world'       => array( 'FIFA World Cup', 'Soccer', 'International' ),
		'soccer/eng.1'            => array( 'Premier League', 'Soccer', 'England' ),
		'soccer/esp.1'            => array( 'LaLiga', 'Soccer', 'Spain' ),
		'soccer/ita.1'            => array( 'Serie A', 'Soccer', 'Italy' ),
		'soccer/ger.1'            => array( 'Bundesliga', 'Soccer', 'Germany' ),
		'soccer/fra.1'            => array( 'Ligue 1', 'Soccer', 'France' ),
		'soccer/uefa.champions'   => array( 'Champions League', 'Soccer', 'International' ),
		'soccer/uefa.europa'      => array( 'Europa League', 'Soccer', 'International' ),
		'soccer/usa.1'            => array( 'MLS', 'Soccer', 'United States' ),
		// Motorsport & combat.
		'racing/f1'               => array( 'Formula 1', 'Motorsport', 'International' ),
		'mma/ufc'                 => array( 'UFC', 'MMA', 'International' ),
		// North American major leagues.
		'football/nfl'            => array( 'NFL', 'American Football', 'United States' ),
		'basketball/nba'          => array( 'NBA', 'Basketball', 'United States' ),
		'baseball/mlb'            => array( 'MLB', 'Baseball', 'United States' ),
		'hockey/nhl'              => array( 'NHL', 'Ice Hockey', 'United States' ),
		// Rugby, cricket, tennis, golf, Aussie rules. ESPN omits broadcaster names for
		// some of these; the mapping falls back to the competition label.
		'rugby/270557'            => array( 'URC Rugby', 'Rugby', 'International' ),
			'cricket/8039'            => array( 'ICC World Cup', 'Cricket', 'International' ),
			'cricket/8048'            => array( 'ICC T20 World Cup', 'Cricket', 'International' ),
			'cricket/8044'            => array( 'ICC Champions Trophy', 'Cricket', 'International' ),
		'tennis/atp'              => array( 'ATP Tennis', 'Tennis', 'International' ),
		'tennis/wta'              => array( 'WTA Tennis', 'Tennis', 'International' ),
		'golf/pga'                => array( 'PGA Tour', 'Golf', 'United States' ),
		'australian-football/afl' => array( 'AFL', 'Aussie Rules', 'Australia' ),
	);
	$range  = gmdate( 'Ymd' ) . '-' . gmdate( 'Ymd', time() + 7 * DAY_IN_SECONDS );
	$events = array();

	foreach ( $leagues as $path => $meta ) {
		list( $label, $code, $country ) = $meta;
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
				'comp'      => $label,
				'code'      => $code,
				'country'   => $country,
				'fx'        => str_replace( ' at ', ' vs ', $name ),
				'iso'       => isset( $event['date'] ) ? $event['date'] : '',
				'time'      => 'in' === $state ? 'LIVE now' : '',
				'ch'        => '' !== $channel ? $channel : $label,
				// ESPN's scoreboard only carries US networks, so a named
				// broadcaster here is always a US one.
				'chCountry' => '' !== $channel ? 'United States' : '',
				'live'      => 'in' === $state,
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

/**
 * Sports TV listings from TheSportsDB's free tier — keyless (the documented
 * public key "3"/"123" needs no registration) and permanently free, unlike the
 * trial-only sports feeds. This is the only free source that names *global*
 * broadcasters: ESPN's scoreboard only carries US networks, so a UK, South
 * African or Nigerian viewer gets nothing useful from it.
 *
 * The catch is the free-tier row cap — `eventstv.php` returns a single row per
 * query however it is filtered, with no pagination. So rather than one big
 * call we fan out one query per sport per day and stitch the single rows
 * together; roughly a dozen requests buy a dozen genuine "sport · fixture ·
 * time · channel" listings from around the world.
 */
function afristream_portal_sportsdb_events() {
	// TheSportsDB sport name => the portal's sporting code (drives the "Sport
	// Type" filter). Names must match TheSportsDB's spelling exactly.
	$sports = array(
		'Soccer'             => 'Soccer',
		'Cricket'            => 'Cricket',
		'Rugby'              => 'Rugby',
		'Motorsport'         => 'Motorsport',
		'Golf'               => 'Golf',
		'Tennis'             => 'Tennis',
		'Fighting'           => 'MMA',
		'Basketball'         => 'Basketball',
		'American Football'  => 'American Football',
		'Australian Football' => 'Aussie Rules',
	);
	$dates   = array( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
	$started = microtime( true );
	$budget  = afristream_portal_pick_budget();
	$events  = array();

	foreach ( $dates as $date ) {
		foreach ( $sports as $sport => $code ) {
			// Same guard as the pick loop: stop short rather than risk blowing
			// max_execution_time on a cold cache. Whatever came back is served.
			if ( microtime( true ) - $started > $budget ) {
				break 2;
			}
			$response = wp_remote_get(
				'https://www.thesportsdb.com/api/v1/json/123/eventstv.php?d=' . rawurlencode( $date ) . '&s=' . rawurlencode( $sport ),
				array( 'timeout' => 6 )
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $json ) || empty( $json['tvevents'] ) || ! is_array( $json['tvevents'] ) ) {
				continue;
			}
			foreach ( $json['tvevents'] as $row ) {
				if ( empty( $row['strEvent'] ) || empty( $row['strChannel'] ) ) {
					continue;
				}
				// Times are UTC; the front-end renders `iso` in the viewer's zone.
				$day  = ! empty( $row['dateEvent'] ) ? $row['dateEvent'] : $date;
				$time = ! empty( $row['strTime'] ) ? $row['strTime'] : '00:00:00';
				$events[] = array(
					'comp'      => ! empty( $row['strSeason'] ) ? $sport . ' · ' . $row['strSeason'] : $sport,
					'code'      => $code,
					'country'   => ! empty( $row['strEventCountry'] ) ? $row['strEventCountry'] : 'International',
					'fx'        => $row['strEvent'],
					'iso'       => $day . 'T' . $time . '+00:00',
					'time'      => '',
					'ch'        => $row['strChannel'],
					'chCountry' => ! empty( $row['strCountry'] ) ? $row['strCountry'] : '',
					'live'      => false,
				);
			}
		}
	}
	return $events;
}

/**
 * Days a baked listings file stays usable. It is a snapshot of a published TV
 * guide, so once the last grabbed day has passed there is nothing left in it;
 * this is the belt-and-braces check on top of dropping finished programmes.
 */
define( 'AFRISTREAM_PORTAL_LISTINGS_MAX_AGE', 10 * DAY_IN_SECONDS );

/**
 * Most rows the baked guide may contribute to the merged sport row. Three days
 * of SuperSport and Sky Sports is hundreds of programmes, all of them sooner
 * than most of the fixture feeds' entries — without a cap they would sort to
 * the top and the row would become one channel's schedule instead of a spread
 * of what is on around the world.
 */
define( 'AFRISTREAM_PORTAL_LISTINGS_CAP', 6 );

/**
 * The sports TV guide baked in at build time by `npm run sync-listings` —
 * SuperSport across South Africa, Nigeria and Kenya plus Sky Sports in the UK,
 * read from the broadcasters' own published EPG via iptv-org/epg.
 *
 * This is the channel-first half of the picture: the fixture feeds answer
 * "who is playing and where can I watch it", this answers "what is actually on
 * SuperSport Cricket at 6pm". Anything already finished is dropped, and a file
 * older than AFRISTREAM_PORTAL_LISTINGS_MAX_AGE is ignored entirely rather
 * than shown as if it were current.
 */
function afristream_portal_listings_events() {
	$path = plugin_dir_path( __FILE__ ) . 'data/sports-listings.json';
	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		return array();
	}
	$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin file, not a remote fetch.
	if ( ! is_array( $json ) || empty( $json['listings'] ) || ! is_array( $json['listings'] ) ) {
		return array();
	}
	$generated = ! empty( $json['generated'] ) ? strtotime( $json['generated'] ) : 0;
	if ( ! $generated || ( time() - $generated ) > AFRISTREAM_PORTAL_LISTINGS_MAX_AGE ) {
		return array();
	}

	$now    = time();
	$events = array();
	foreach ( $json['listings'] as $row ) {
		if ( empty( $row['fx'] ) || empty( $row['iso'] ) || empty( $row['ch'] ) ) {
			continue;
		}
		$start = strtotime( $row['iso'] );
		$end   = ! empty( $row['endIso'] ) ? strtotime( $row['endIso'] ) : 0;
		if ( ! $start || ( $end && $end < $now ) ) {
			continue;
		}
		$events[] = array(
			'comp'      => ! empty( $row['comp'] ) ? $row['comp'] : $row['ch'],
			'code'      => ! empty( $row['code'] ) ? $row['code'] : 'Sport',
			'country'   => ! empty( $row['country'] ) ? $row['country'] : 'International',
			'fx'        => $row['fx'],
			'iso'       => $row['iso'],
			'time'      => '',
			'ch'        => $row['ch'],
			'chCountry' => ! empty( $row['chCountry'] ) ? $row['chCountry'] : '',
			'live'      => $start <= $now && ( ! $end || $end > $now ),
		);
	}

	// Soonest first, then trimmed — see AFRISTREAM_PORTAL_LISTINGS_CAP.
	usort(
		$events,
		function ( $a, $b ) {
			if ( $a['live'] !== $b['live'] ) {
				return $a['live'] ? -1 : 1;
			}
			return strcmp( $a['iso'], $b['iso'] );
		}
	);
	return array_slice( $events, 0, AFRISTREAM_PORTAL_LISTINGS_CAP );
}

/**
 * Normalised key for de-duplicating the same fixture arriving from more than
 * one feed ("Arsenal at Everton" from ESPN vs "Everton vs Arsenal" from
 * TheSportsDB). Team order is discarded so either phrasing collapses to one key.
 */
function afristream_portal_sport_key( $event ) {
	$name  = strtolower( isset( $event['fx'] ) ? $event['fx'] : '' );
	$parts = preg_split( '/\s+(?:vs?\.?|at|v)\s+/', $name );
	$parts = array_map(
		function ( $part ) {
			return preg_replace( '/[^a-z0-9]/', '', $part );
		},
		is_array( $parts ) ? $parts : array( $name )
	);
	$parts = array_filter( $parts );
	sort( $parts );
	return implode( '|', $parts );
}

/**
 * All three free feeds, merged. ESPN supplies the fixture list and US networks;
 * TheSportsDB supplies broadcasters for the rest of the world; the baked
 * iptv-org guide supplies what is actually on SuperSport and Sky Sports. Where
 * the same fixture appears in more than one, the row that actually names a
 * broadcaster wins — ESPN falls back to the competition label when it has no
 * broadcast data, and a real channel name is always the more useful answer.
 *
 * Feed order is deliberate: the fixture feeds go first so their cleaner event
 * names and competition labels are what a merged row keeps.
 */
function afristream_portal_sport_merged() {
	$merged = array();

	foreach ( array( afristream_portal_sport_events(), afristream_portal_sportsdb_events(), afristream_portal_listings_events() ) as $feed ) {
		foreach ( $feed as $event ) {
			$key = afristream_portal_sport_key( $event );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $event;
				continue;
			}
			$existing = $merged[ $key ];
			// Keep whichever row names a channel; prefer the one that also
			// knows which country that channel broadcasts in.
			$has_channel = ! empty( $event['ch'] ) && $event['ch'] !== $event['comp'];
			$had_channel = ! empty( $existing['ch'] ) && $existing['ch'] !== $existing['comp'];
			if ( $has_channel && ! $had_channel ) {
				// Keep the richer fixture metadata, take the better channel.
				$existing['ch']        = $event['ch'];
				$existing['chCountry'] = isset( $event['chCountry'] ) ? $event['chCountry'] : '';
			}
			$merged[ $key ] = $existing;
		}
	}

	$events = array_values( $merged );
	usort(
		$events,
		function ( $a, $b ) {
			if ( $a['live'] !== $b['live'] ) {
				return $a['live'] ? -1 : 1;
			}
			return strcmp( $a['iso'], $b['iso'] );
		}
	);
	return array_slice( $events, 0, 12 );
}

function afristream_portal_sport_cached() {
	$sport = get_transient( 'afristream_portal_sport' );
	if ( false === $sport ) {
		$sport = afristream_portal_sport_merged();
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
 * Editor Picks — a list of IMDb title IDs. IMDb's watchlist page is behind AWS
 * WAF and can't be fetched server-side, so the IDs are pulled at build/deploy
 * time by `npm run sync-watchlist` (a real browser) into the bundled
 * data/editor-picks-ids.txt. That file is the default; the
 * afristream_editor_picks_ids option overrides it when set. Each ID is resolved
 * via TMDB for consistent artwork, and a last-good copy is kept so a TMDB hiccup
 * never blanks the page.
 *
 * Returns an ordered map of IMDb ID => IMDb rating (a float, or null when the
 * line carries no rating — always the case for hand-typed admin entries).
 */
function afristream_portal_editor_ids() {
	// The admin box wins; otherwise fall back to the synced watchlist file.
	$raw = (string) get_option( 'afristream_editor_picks_ids', '' );
	if ( '' === trim( $raw ) ) {
		$file = plugin_dir_path( __FILE__ ) . 'data/editor-picks-ids.txt';
		if ( is_readable( $file ) ) {
			$raw = (string) file_get_contents( $file );
		}
	}
	// Accept any tt-id, whether bare, comma/newline separated, or inside a full
	// IMDb title URL, optionally followed by the IMDb rating the sync recorded
	// ("tt0099348 8.0"). Order preserved, deduped, capped at the pick cap.
	$ids = array();
	foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		if ( ! preg_match( '/(tt\d+)(?:\D+(\d+(?:\.\d+)?))?/', $line, $m ) ) {
			continue;
		}
		if ( isset( $ids[ $m[1] ] ) ) {
			continue;
		}
		// Guard the rating: a stray number on the line (a year pasted alongside
		// the ID, say) must not masquerade as a 0–10 score.
		$rating              = ( isset( $m[2] ) && $m[2] >= 0 && $m[2] <= 10 ) ? (float) $m[2] : null;
		$ids[ $m[1] ] = $rating;
		if ( count( $ids ) >= AFRISTREAM_PORTAL_PICK_CAP ) {
			break;
		}
	}
	return $ids;
}

/**
 * Resolve one IMDb ID through TMDB, memoised per ID for a week.
 *
 * Each pick costs a TMDB /find round-trip, so at full cap a cold build is
 * hundreds of sequential HTTP calls. The per-ID cache means that cost is paid
 * once per title rather than once per rebuild, and lets a run that ran out of
 * time (see afristream_portal_editor_picks) resume cheaply on the next request.
 * Rank is positional, so it is applied by the caller and never cached.
 */
function afristream_portal_resolve_pick_cached( $imdb_id, $movie_genres, $tv_genres ) {
	$key    = 'afristream_pick_' . $imdb_id;
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}
	$pick = afristream_portal_resolve_pick( $imdb_id, '', 0, $movie_genres, $tv_genres );
	// Cache misses too (as an empty array), so an ID TMDB doesn't know isn't
	// re-requested on every rebuild — but for a shorter window, in case TMDB
	// gains the title later.
	set_transient( $key, $pick ? $pick : array(), $pick ? WEEK_IN_SECONDS : DAY_IN_SECONDS );
	return $pick;
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
			't' => $fallback_title, 'genre' => 'Film', 'platform' => 'IMDb', 'rating' => null,
			'meta' => '', 'poster' => null, 'type' => 'Movies', 'country' => '', 'rank' => $rank, 'id' => 0,
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
		'id'       => isset( $hit['id'] ) ? (int) $hit['id'] : 0,
		'genre'    => isset( $genres[ $genre_id ] ) ? $genres[ $genre_id ] : $type,
		// TMDB's score, used for the badge and the rating filter only when the
		// synced list carries no IMDb rating for this title.
		'rating'   => $rating > 0 ? round( $rating, 1 ) : null,
		'platform' => $rating > 0 ? '★ ' . number_format( $rating, 1 ) : 'IMDb',
		'meta'     => ( 'Series' === $type ) ? trim( 'TV · ' . $year, ' ·' ) : $year,
		'poster'   => ! empty( $hit['poster_path'] ) ? 'https://image.tmdb.org/t/p/w342' . $hit['poster_path'] : null,
		'type'     => $type,
		'country'  => isset( $countries[ $cc ] ) ? $countries[ $cc ] : '',
		'rank'     => $rank,
	);
}

/**
 * The watchlist already resolved through TMDB at build time — data/editor-picks.json,
 * written by `npm run sync-watchlist` and shipped inside the plugin.
 *
 * Serving this is a single file read. Resolving the same list from here costs one
 * TMDB round-trip per title, which is why a cold cache used to spend the best part
 * of a minute filling the page in. Only the default list is baked: if the admin
 * override box holds hand-entered IDs there is no baked copy of them, so that path
 * still goes through the live resolver below.
 */
function afristream_portal_baked_picks() {
	static $payload = null;
	if ( null !== $payload ) {
		return $payload;
	}
	$payload = false;
	if ( '' !== trim( (string) get_option( 'afristream_editor_picks_ids', '' ) ) ) {
		return $payload;
	}
	$file = plugin_dir_path( __FILE__ ) . 'data/editor-picks.json';
	if ( ! is_readable( $file ) ) {
		return $payload;
	}
	$json = json_decode( (string) file_get_contents( $file ), true );
	if ( is_array( $json ) && ! empty( $json['picks'] ) ) {
		$payload = $json;
	}
	return $payload;
}

function afristream_portal_editor_picks() {
	$baked = afristream_portal_baked_picks();
	if ( $baked ) {
		return $baked;
	}

	$cached = get_transient( 'afristream_portal_editor' );
	if ( false !== $cached && empty( $cached['partial'] ) ) {
		return $cached;
	}
	// A partial payload means an earlier request ran out of time part-way through
	// the list. Hand it straight back only while another request is already
	// resolving the rest; otherwise this request picks up where that one stopped,
	// warm from the per-title cache. Without that, the short list is simply
	// re-served until the transient expires and the visitor never sees the rest.
	if ( false !== $cached && get_transient( 'afristream_portal_editor_lock' ) ) {
		return $cached;
	}
	if ( ! afristream_portal_tmdb_key() ) {
		return array( 'source' => 'fallback', 'reason' => 'no-key' );
	}

	$ids       = afristream_portal_editor_ids();
	$last_good = get_option( 'afristream_portal_editor_lastgood', null );
	if ( empty( $ids ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'no-ids' );
	}

	$movie_genres = afristream_portal_tmdb_genres( 'movie' );
	$tv_genres    = afristream_portal_tmdb_genres( 'tv' );
	$picks        = array();
	$rank         = 1;
	$started      = microtime( true );
	$budget       = afristream_portal_pick_budget();
	$partial      = false;
	// Held for the length of one resolve pass so concurrent visitors read the
	// partial payload above instead of all stampeding TMDB with the same work.
	set_transient( 'afristream_portal_editor_lock', 1, (int) ceil( $budget ) + 10 );
	foreach ( $ids as $imdb_id => $imdb_rating ) {
		// Stop short rather than let a cold cache blow PHP's max execution time
		// and 500 the request. Whatever resolved is served now and the rest is
		// picked up on a later request, warm from the per-ID cache.
		if ( microtime( true ) - $started > $budget ) {
			$partial = true;
			break;
		}
		$pick = afristream_portal_resolve_pick_cached( $imdb_id, $movie_genres, $tv_genres );
		if ( $pick ) {
			$pick['rank'] = $rank;
			// The IMDb rating comes from the synced list, not TMDB, so it is
			// applied here rather than inside the (TMDB-only) per-ID cache.
			if ( null !== $imdb_rating ) {
				$pick['rating']   = $imdb_rating;
				$pick['platform'] = '★ ' . number_format( $imdb_rating, 1 );
			}
			$picks[] = $pick;
			$rank++;
		}
	}
	delete_transient( 'afristream_portal_editor_lock' );
	if ( empty( $picks ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'none-resolved' );
	}

	$payload = array( 'source' => 'imdb', 'updated' => gmdate( 'c' ), 'picks' => $picks );
	if ( $partial ) {
		$payload['partial'] = true;
	}
	// A partial run is kept only as the answer to serve while the next pass runs —
	// the front end is polling for the rest, so a long TTL here would just stall it.
	set_transient( 'afristream_portal_editor', $payload, $partial ? 5 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	// Only a complete run becomes the last-good copy — a truncated list should
	// never replace a full one as the TMDB-outage fallback.
	if ( ! $partial ) {
		update_option( 'afristream_portal_editor_lastgood', $payload, false );
	}
	return $payload;
}

/**
 * Seconds the pick-resolving loop may spend before saving what it has. Sized to
 * leave headroom under PHP's max_execution_time (0 / unlimited on CLI or some
 * hosts, in which case a flat ceiling keeps the request responsive).
 */
function afristream_portal_pick_budget() {
	$max = (int) ini_get( 'max_execution_time' );
	if ( $max <= 0 ) {
		return 20.0;
	}
	return max( 5.0, min( 20.0, $max * 0.5 ) );
}

function afristream_portal_editor_data() {
	return rest_ensure_response( afristream_portal_editor_picks() );
}

/**
 * Single-item synopsis for the card detail panel. Given a TMDB id and kind
 * (movie|tv), returns { overview }. Cached per id+kind for 24h; an empty
 * string whenever no key is set or TMDB is unreachable.
 */
function afristream_portal_detail_data( $request ) {
	$id   = absint( $request->get_param( 'id' ) );
	$type = 'tv' === $request->get_param( 'type' ) ? 'tv' : 'movie';
	if ( ! $id ) {
		return rest_ensure_response( array( 'overview' => '' ) );
	}
	$cache_key = 'afristream_portal_detail_' . $type . '_' . $id;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return rest_ensure_response( array( 'overview' => $cached ) );
	}
	if ( ! afristream_portal_tmdb_key() ) {
		return rest_ensure_response( array( 'overview' => '' ) );
	}
	$json     = afristream_portal_tmdb_get( '/' . $type . '/' . $id );
	$overview = ( $json && ! empty( $json['overview'] ) ) ? (string) $json['overview'] : '';
	if ( null !== $json ) {
		set_transient( $cache_key, $overview, 24 * HOUR_IN_SECONDS );
	}
	return rest_ensure_response( array( 'overview' => $overview ) );
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

	register_rest_route(
		'afristream/v1',
		'/detail',
		array(
			'methods'             => 'GET',
			'callback'            => 'afristream_portal_detail_data',
			'permission_callback' => '__return_true',
		)
	);

	// Credentials are private to the logged-in user, so this route (unlike the
	// others) requires authentication — the portal fetches it with the REST
	// nonce and same-origin cookies.
	register_rest_route(
		'afristream/v1',
		'/credentials',
		array(
			'methods'             => 'GET',
			'callback'            => 'afristream_portal_credentials_data',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		)
	);
}

/**
 * The current user's app credentials, one entry per assigned licence, for the
 * portal's Profile tab. Returns source:"fallback" with an empty list when no
 * licence is assigned, so the front-end can show a clear "no profile assigned"
 * state instead of stale placeholders.
 */
function afristream_portal_credentials_data() {
	$profiles = afristream_portal_user_credentials();
	return rest_ensure_response(
		array(
			'source'   => $profiles ? 'assigned' : 'fallback',
			'profiles' => $profiles,
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
		__( 'Live data sources', 'bluegroup-project-afristream' ),
		'afristream_portal_settings_intro',
		'bluegroup-project-afristream'
	);

	add_settings_field(
		'afristream_tmdb_api_key',
		__( 'TMDB API key', 'bluegroup-project-afristream' ),
		'afristream_portal_tmdb_key_field',
		'bluegroup-project-afristream',
		'afristream_portal_data',
		array( 'label_for' => 'afristream_tmdb_api_key' )
	);

	register_setting(
		'afristream_portal',
		'afristream_editor_picks_ids',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'afristream_portal_sanitize_editor_ids',
			'default'           => '',
		)
	);

	add_settings_field(
		'afristream_editor_picks_ids',
		__( 'Editor Picks (IMDb IDs)', 'bluegroup-project-afristream' ),
		'afristream_portal_editor_ids_field',
		'bluegroup-project-afristream',
		'afristream_portal_data',
		array( 'label_for' => 'afristream_editor_picks_ids' )
	);
}
add_action( 'admin_init', 'afristream_portal_register_settings' );

function afristream_portal_sanitize_tmdb_key( $value ) {
	// A new (or cleared) key should refetch immediately, not wait out the cache.
	delete_transient( 'afristream_portal_tmdb' );
	return sanitize_text_field( (string) $value );
}

function afristream_portal_sanitize_editor_ids( $value ) {
	// A changed list should refetch immediately, not wait out the cache.
	delete_transient( 'afristream_portal_editor' );
	return sanitize_textarea_field( (string) $value );
}

function afristream_portal_editor_ids_field() {
	printf(
		'<textarea class="large-text code" rows="6" name="afristream_editor_picks_ids" id="afristream_editor_picks_ids" autocomplete="off" placeholder="tt0111161&#10;tt0068646&#10;https://www.imdb.com/title/tt0468569/">%s</textarea>',
		esc_textarea( (string) get_option( 'afristream_editor_picks_ids', '' ) )
	);
	echo '<p class="description">' . wp_kses(
		__( 'The IMDb title IDs for the Editor Picks page, in order (top of the list becomes the featured pick). Leave this empty to use the watchlist synced at build time (via <code>npm run sync-watchlist</code>, bundled in <code>data/editor-picks-ids.txt</code>). To override, paste <code>tt…</code> IDs here — one per line, or full title URLs; IDs are extracted automatically and resolved through TMDB for artwork (needs a TMDB key). Up to 300; a long list fills in over the first few minutes after saving.', 'bluegroup-project-afristream' ),
		array( 'code' => array() )
	) . '</p>';
}

function afristream_portal_settings_intro() {
	echo '<p>' . esc_html__( 'Sport fixtures and TV channels need no configuration — they come from two keyless, permanently free public feeds (ESPN for fixtures and US networks, TheSportsDB for broadcasters elsewhere in the world). Trending movies, series and new releases come from TMDB once an API key is saved; without one the portal shows its built-in lists.', 'bluegroup-project-afristream' ) . '</p>';
}

function afristream_portal_tmdb_key_field() {
	if ( defined( 'AFRISTREAM_TMDB_API_KEY' ) && AFRISTREAM_TMDB_API_KEY ) {
		echo '<p><em>' . esc_html__( 'The key is defined in wp-config.php (AFRISTREAM_TMDB_API_KEY), which takes precedence over this setting.', 'bluegroup-project-afristream' ) . '</em></p>';
		return;
	}
	printf(
		'<input type="text" class="regular-text code" name="afristream_tmdb_api_key" id="afristream_tmdb_api_key" value="%s" autocomplete="off">',
		esc_attr( get_option( 'afristream_tmdb_api_key', '' ) )
	);
	echo '<p class="description">' . wp_kses(
		__( 'Free API key (v3 auth) from <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer">themoviedb.org</a> (sign up → Settings → API). Saving refreshes the catalog immediately.', 'bluegroup-project-afristream' ),
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
		__( 'AfriStream Portal', 'bluegroup-project-afristream' ),
		__( 'AfriStream Portal', 'bluegroup-project-afristream' ),
		'manage_options',
		'bluegroup-project-afristream',
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
		<h1><?php esc_html_e( 'AfriStream Portal', 'bluegroup-project-afristream' ); ?></h1>
		<p>
			<?php if ( $connected ) : ?>
				<strong style="color:#00a32a">&#9679;</strong>
				<?php esc_html_e( 'TMDB connected — trending movies, series and new releases are live.', 'bluegroup-project-afristream' ); ?>
			<?php else : ?>
				<strong style="color:#d63638">&#9679;</strong>
				<?php esc_html_e( 'No TMDB key saved — the movie and series rows are showing the built-in lists.', 'bluegroup-project-afristream' ); ?>
			<?php endif; ?>
		</p>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'afristream_portal' );
			do_settings_sections( 'bluegroup-project-afristream' );
			submit_button();
			?>
		</form>
		<hr>
		<p>
			<?php
			printf(
				/* translators: %s: shortcode example. */
				esc_html__( 'Show the portal on any page with the shortcode %s (optional attributes: default_tab="profile|setup|watch|apps|editor|download", show_sport="true|false").', 'bluegroup-project-afristream' ),
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
		'<a href="' . esc_url( admin_url( 'options-general.php?page=bluegroup-project-afristream' ) ) . '">' . esc_html__( 'Settings', 'bluegroup-project-afristream' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'afristream_portal_action_links' );

/**
 * Declare the portal's own surface on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_portal_registry( $items ) {
	$items[] = array(
		'group'  => 'Portal',
		'name'   => 'Portal shortcode',
		'type'   => 'shortcode',
		'handle' => 'afristream_portal',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Attributes: default_tab, show_sport', 'bluegroup-project-afristream' ),
		),
	);

	$routes = array(
		'afristream/v1/watch'        => 'What to Watch data',
		'afristream/v1/editor-picks' => 'Editor Picks data',
		'afristream/v1/detail'       => 'Title detail lookup',
		'afristream/v1/credentials'  => 'Customer app credentials',
		'afristream/v1/affiliate'    => 'Affiliate status and rates',
	);
	foreach ( $routes as $handle => $name ) {
		$items[] = array(
			'group'  => 'Portal',
			'name'   => $name,
			'type'   => 'rest',
			'handle' => $handle,
			'file'   => 'bluegroup-project-afristream.php',
		);
	}

	$has_key = '' !== (string) afristream_portal_tmdb_key();
	$items[] = array(
		'group'  => 'Content sources',
		'name'   => 'TMDB catalogue',
		'type'   => 'setting',
		'handle' => 'afristream_tmdb_api_key',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => $has_key
			? array( 'state' => 'ok', 'label' => __( 'Connected — trending titles are live', 'bluegroup-project-afristream' ) )
			: array( 'state' => 'warn', 'label' => __( 'No key — the built-in lists are showing', 'bluegroup-project-afristream' ) ),
	);

	$picks = count( afristream_portal_editor_ids() );
	$items[] = array(
		'group'  => 'Content sources',
		'name'   => 'Editor Picks list',
		'type'   => 'setting',
		'handle' => 'afristream_editor_picks_ids',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => array(
			'state' => $picks ? 'ok' : 'warn',
			/* translators: %d: number of curated titles. */
			'label' => sprintf( _n( '%d curated title', '%d curated titles', $picks, 'bluegroup-project-afristream' ), $picks ),
		),
	);

	$items[] = array(
		'group'  => 'Content sources',
		'name'   => 'Sport fixtures and broadcasters',
		'type'   => 'integration',
		'handle' => 'ESPN + TheSportsDB + baked listings',
		'file'   => 'bluegroup-project-afristream.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Keyless public feeds — no configuration', 'bluegroup-project-afristream' ),
		),
	);

	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Settings page',
		'type'   => 'page',
		'handle' => 'options-general.php?page=bluegroup-project-afristream',
		'file'   => 'bluegroup-project-afristream.php',
	);

	$items[] = array(
		'group'  => 'Admin UI',
		'name'   => 'Configurations page',
		'type'   => 'page',
		'handle' => 'admin.php?page=afristream-configurations',
		'file'   => 'includes/configurations.php',
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_portal_registry' );
