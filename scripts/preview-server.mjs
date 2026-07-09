// Tiny dependency-free static server for previewing the portal locally:
//   npm run preview  →  http://localhost:4173
// Serves the repo root; "/" maps to preview/index.html, which loads the same
// assets the WordPress plugin enqueues.
//
// /api/watch mirrors the plugin's WP REST endpoint (afristream/v1/watch):
// ESPN sport fixtures are keyless and always attempted; TMDB movie/series
// data needs a TMDB_API_KEY env var. Results are cached in memory and the
// portal keeps its built-in lists for whatever is unavailable.
// /api/watch?fixture=1 always returns a small deterministic payload for the
// Playwright tests, and WATCH_OFFLINE=1 disables all outbound fetches so the
// test suite stays hermetic (see playwright.config.js).

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const PORT = Number(process.env.PORT) || 4173;

const FIXTURE = {
  source: 'live',
  updated: 'fixture',
  tmdb: true,
  sport: [
    { comp: 'Fixture League', fx: 'Fixture FC vs Test United', time: 'Today · 20:00', ch: 'Fixture Sports', live: true },
  ],
  movies: [
    { t: 'Fixture Movie One', genre: 'Drama', platform: '★ 8.1', meta: '2026', poster: null, type: 'Movies' },
    { t: 'Fixture Movie Two', genre: 'Action', platform: '★ 7.4', meta: '2025', poster: null, type: 'Movies' },
  ],
  series: [
    { t: 'Fixture Series One', genre: 'Crime', platform: '★ 8.6', meta: 'TV · 2026', poster: null, type: 'Series' },
  ],
  newWeek: [
    { t: 'Fixture New Arrival', genre: 'Comedy', platform: '★ 7.0', meta: 'New episodes', poster: null, type: 'Series' },
  ],
  catalog: [
    { t: 'Jozi Heat', genre: 'Crime', platform: '★ 7.8', meta: '2023', poster: null, type: 'Movies', country: 'South Africa' },
    { t: 'Lagos Lights', genre: 'Drama', platform: '★ 8.0', meta: '2019', poster: null, type: 'Movies', country: 'Nigeria' },
    { t: 'Seoul Signal', genre: 'Thriller', platform: '★ 8.4', meta: 'TV · 2021', poster: null, type: 'Series', country: 'South Korea' },
    { t: 'London Fog', genre: 'Mystery', platform: '★ 7.2', meta: '2008', poster: null, type: 'Movies', country: 'United Kingdom' },
    { t: 'Nairobi Nights', genre: 'Drama', platform: '★ 7.5', meta: 'TV · 1998', poster: null, type: 'Series', country: 'Kenya' },
    // Shares a title with a trending row (Fixture Movie One) to exercise the
    // country back-fill: the deduped trending copy should inherit this country.
    { t: 'Fixture Movie One', genre: 'Drama', platform: '★ 8.1', meta: '2026', poster: null, type: 'Movies', country: 'United States' },
  ],
};

// Editor Picks are driven by a hand-curated list of IMDb title IDs (IMDb's
// watchlist page itself is behind AWS WAF and can't be scraped server-side).
// Locally the IDs come from the EDITOR_PICKS_IDS env var; in the plugin they
// come from the afristream_editor_picks_ids option. Any tt-id (or a pasted
// IMDb URL containing one) is accepted; order is preserved, capped at 24.
function parseEditorIds(raw) {
  const ids = String(raw || '').match(/tt\d+/g) || [];
  return [...new Set(ids)].slice(0, 24);
}

const EDITOR_FIXTURE = {
  source: 'imdb',
  picks: [
    { t: 'Fixture Pick One', genre: 'Drama', platform: '★ 8.5', meta: '2024', poster: null, type: 'Movies', country: 'South Africa', rank: 1 },
    { t: 'Fixture Pick Two', genre: 'Thriller', platform: '★ 8.1', meta: 'TV · 2023', poster: null, type: 'Series', country: 'Nigeria', rank: 2 },
    { t: 'Fixture Pick Three', genre: 'Comedy', platform: '★ 7.6', meta: '2022', poster: null, type: 'Movies', country: 'Kenya', rank: 3 },
  ],
};

let editorCache = null;
let editorCacheAt = 0;

async function resolvePick(imdbId, fallbackTitle, rank, movieGenres, tvGenres) {
  const json = await tmdbGet('/find/' + imdbId, { external_source: 'imdb_id' });
  const movie = json?.movie_results?.[0];
  const tv = json?.tv_results?.[0];
  const hit = movie || tv;
  if (!hit) {
    return fallbackTitle
      ? { t: fallbackTitle, genre: 'Film', platform: 'IMDb', meta: '', poster: null, type: 'Movies', country: '', rank }
      : null;
  }
  const type = movie ? 'Movies' : 'Series';
  const genres = movie ? movieGenres : tvGenres;
  const year = String(hit.release_date || hit.first_air_date || '').slice(0, 4);
  const rating = Number(hit.vote_average) || 0;
  return {
    t: hit.title || hit.name || fallbackTitle || '',
    genre: genres[hit.genre_ids?.[0]] || type,
    platform: rating > 0 ? `★ ${rating.toFixed(1)}` : 'IMDb',
    meta: type === 'Series' ? (year ? `TV · ${year}` : 'TV') : year,
    poster: hit.poster_path ? `https://image.tmdb.org/t/p/w342${hit.poster_path}` : null,
    type,
    country: (hit.origin_country && hit.origin_country[0] && COUNTRIES[hit.origin_country[0]]) || '',
    rank,
  };
}

async function editorPicksPayload() {
  if (process.env.WATCH_OFFLINE === '1') return { source: 'fallback', reason: 'offline' };
  if (editorCache && Date.now() - editorCacheAt < 12 * 60 * 60 * 1000) return editorCache;
  const ids = parseEditorIds(process.env.EDITOR_PICKS_IDS);
  if (!ids.length) return editorCache || { source: 'fallback', reason: 'no-ids' };
  try {
    const genreList = async (type) =>
      Object.fromEntries(((await tmdbGet(`/genre/${type}/list`))?.genres ?? []).map((g) => [g.id, g.name]));
    const [movieGenres, tvGenres] = await Promise.all([genreList('movie'), genreList('tv')]);
    const resolved = await Promise.all(
      ids.map((id, i) => resolvePick(id, '', i + 1, movieGenres, tvGenres))
    );
    const picks = resolved.filter(Boolean);
    if (!picks.length) throw new Error('none resolved');
    // Re-sequence ranks gap-free over the resolved set, matching the PHP side
    // (which only increments $rank on a successful pick).
    picks.forEach((p, i) => { p.rank = i + 1; });

    editorCache = { source: 'imdb', updated: new Date().toISOString(), picks };
    editorCacheAt = Date.now();
    return editorCache;
  } catch {
    // Last-good cache survives an IMDb hiccup; otherwise the front-end falls back.
    return editorCache || { source: 'fallback', reason: 'unavailable' };
  }
}

const TMDB = 'https://api.themoviedb.org/3';
let watchCache = null;
let watchCacheAt = 0;

async function tmdbGet(path, params = {}) {
  try {
    const url = new URL(TMDB + path);
    url.searchParams.set('api_key', process.env.TMDB_API_KEY);
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    const res = await fetch(url);
    if (!res.ok) return null;
    return await res.json();
  } catch {
    return null;
  }
}

// Same mapping the plugin's PHP does — keep the two in sync.
function mapItems(json, genres, type, limit, metaLabel = '') {
  const items = [];
  for (const row of json?.results ?? []) {
    if (items.length >= limit) break;
    const title = row.title || row.name || '';
    if (!title) continue;
    const year = String(row.release_date || row.first_air_date || '').slice(0, 4);
    const rating = Number(row.vote_average) || 0;
    items.push({
      t: title,
      genre: genres[row.genre_ids?.[0]] || type,
      platform: rating > 0 ? `★ ${rating.toFixed(1)}` : 'New',
      meta: metaLabel || (type === 'Series' ? `TV · ${year}` : year),
      poster: row.poster_path ? `https://image.tmdb.org/t/p/w342${row.poster_path}` : null,
      type,
    });
  }
  return items;
}

const DAY = 24 * 60 * 60 * 1000;

// Origin countries for the deep, filterable catalog. ISO 3166-1 → display name.
const COUNTRIES = {
  US: 'United States', GB: 'United Kingdom', ZA: 'South Africa', NG: 'Nigeria',
  KE: 'Kenya', IN: 'India', FR: 'France', ES: 'Spain', KR: 'South Korea',
  JP: 'Japan', BR: 'Brazil', DE: 'Germany', AU: 'Australia', EG: 'Egypt',
};

async function discoverCountry(kind, cc, genres) {
  const json = await tmdbGet(`/discover/${kind}`, {
    sort_by: 'popularity.desc',
    with_origin_country: cc,
    'vote_count.gte': 20,
    page: 1,
  });
  const type = kind === 'movie' ? 'Movies' : 'Series';
  return (json?.results ?? []).map((row) => {
    const year = String(row.release_date || row.first_air_date || '').slice(0, 4);
    const rating = Number(row.vote_average) || 0;
    return {
      t: row.title || row.name || '',
      genre: genres[row.genre_ids?.[0]] || type,
      platform: rating > 0 ? `★ ${rating.toFixed(1)}` : 'New',
      meta: type === 'Series' ? (year ? `TV · ${year}` : 'TV') : year,
      poster: row.poster_path ? `https://image.tmdb.org/t/p/w342${row.poster_path}` : null,
      type,
      country: COUNTRIES[cc],
    };
  }).filter((x) => x.t);
}

async function tmdbCatalog() {
  if (!process.env.TMDB_API_KEY) return null;
  const iso = (ms) => new Date(ms).toISOString().slice(0, 10);
  const genreList = async (type) =>
    Object.fromEntries(((await tmdbGet(`/genre/${type}/list`))?.genres ?? []).map((g) => [g.id, g.name]));

  try {
    const [movieGenres, tvGenres] = await Promise.all([genreList('movie'), genreList('tv')]);
    const [trendingMovies, trendingTv, newMovies, onAir] = await Promise.all([
      tmdbGet('/trending/movie/week'),
      tmdbGet('/trending/tv/week'),
      tmdbGet('/discover/movie', {
        sort_by: 'popularity.desc',
        with_release_type: '4|6',
        'release_date.gte': iso(Date.now() - 21 * DAY),
        'release_date.lte': iso(Date.now()),
      }),
      tmdbGet('/tv/on_the_air'),
    ]);
    if (!trendingMovies && !trendingTv) return null;

    const perCountry = await Promise.all(
      Object.keys(COUNTRIES).flatMap((cc) => [
        discoverCountry('movie', cc, movieGenres),
        discoverCountry('tv', cc, tvGenres),
      ])
    );
    const seen = new Set();
    const catalog = [];
    for (const item of perCountry.flat()) {
      if (!seen.has(item.t)) { seen.add(item.t); catalog.push(item); }
    }

    return {
      movies: mapItems(trendingMovies, movieGenres, 'Movies', 10),
      series: mapItems(trendingTv, tvGenres, 'Series', 10),
      newWeek: [
        ...mapItems(newMovies, movieGenres, 'Movies', 4, 'New release'),
        ...mapItems(onAir, tvGenres, 'Series', 4, 'New episodes'),
      ],
      catalog,
    };
  } catch {
    return null;
  }
}

// Major global sporting events from ESPN's public scoreboard API — keyless.
// Same league list and mapping as the plugin's PHP; keep the two in sync.
const ESPN_LEAGUES = {
  // Football (soccer) — mostly European seasons, so quiet over the summer.
  'soccer/fifa.world': 'FIFA World Cup',
  'soccer/eng.1': 'Premier League',
  'soccer/esp.1': 'LaLiga',
  'soccer/ita.1': 'Serie A',
  'soccer/ger.1': 'Bundesliga',
  'soccer/fra.1': 'Ligue 1',
  'soccer/uefa.champions': 'Champions League',
  'soccer/uefa.europa': 'Europa League',
  'soccer/usa.1': 'MLS',
  // Motorsport & combat.
  'racing/f1': 'Formula 1',
  'mma/ufc': 'UFC',
  // North American major leagues.
  'football/nfl': 'NFL',
  'basketball/nba': 'NBA',
  'baseball/mlb': 'MLB',
  'hockey/nhl': 'NHL',
  // Rugby, tennis, golf, Aussie rules. ESPN omits broadcaster names for some
  // of these; the mapping falls back to the competition label.
  'rugby/270557': 'URC Rugby',
  'tennis/atp': 'ATP Tennis',
  'tennis/wta': 'WTA Tennis',
  'golf/pga': 'PGA Tour',
  'australian-football/afl': 'AFL',
};

async function espnSport() {
  const fmt = (ms) => new Date(ms).toISOString().slice(0, 10).replace(/-/g, '');
  const range = `${fmt(Date.now())}-${fmt(Date.now() + 7 * DAY)}`;
  const events = [];

  await Promise.all(Object.entries(ESPN_LEAGUES).map(async ([path, label]) => {
    try {
      const res = await fetch(
        `https://site.api.espn.com/apis/site/v2/sports/${path}/scoreboard?dates=${range}`,
        { signal: AbortSignal.timeout(8000) }
      );
      if (!res.ok) return;
      const json = await res.json();
      let count = 0;
      for (const ev of json?.events ?? []) {
        if (count >= 2) break;
        const state = ev?.status?.type?.state ?? 'pre';
        if (state === 'post') continue;
        const name = ev?.name || ev?.shortName || '';
        if (!name) continue;
        events.push({
          comp: label,
          fx: name.replace(' at ', ' vs '),
          iso: ev.date || '',
          time: state === 'in' ? 'LIVE now' : '',
          ch: ev?.competitions?.[0]?.broadcasts?.[0]?.names?.[0] || label,
          live: state === 'in',
        });
        count++;
      }
    } catch { /* league unavailable — skip */ }
  }));

  events.sort((a, b) => (a.live !== b.live ? (a.live ? -1 : 1) : a.iso.localeCompare(b.iso)));
  return events.slice(0, 8);
}

async function watchPayload() {
  if (process.env.WATCH_OFFLINE === '1') return { source: 'fallback', reason: 'offline' };
  if (watchCache && Date.now() - watchCacheAt < 2 * 60 * 60 * 1000) return watchCache;

  const [catalog, sport] = await Promise.all([tmdbCatalog(), espnSport()]);
  if (!catalog && !sport.length) return { source: 'fallback', reason: 'no-live-data' };

  watchCache = {
    source: 'live',
    updated: new Date().toISOString(),
    tmdb: !!catalog,
    ...(catalog || {}),
    ...(sport.length ? { sport } : {}),
  };
  watchCacheAt = Date.now();
  return watchCache;
}

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
};

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    let path = decodeURIComponent(url.pathname);
    if (path === '/api/watch') {
      const payload = url.searchParams.get('fixture') === '1' ? FIXTURE : await watchPayload();
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(payload));
      return;
    }
    if (path === '/api/editor-picks') {
      const payload = url.searchParams.get('fixture') === '1' ? EDITOR_FIXTURE : await editorPicksPayload();
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(payload));
      return;
    }
    if (path === '/' || path === '/index.html') path = '/preview/index.html';

    const file = normalize(join(ROOT, path));
    if (!file.startsWith(ROOT + sep)) {
      res.writeHead(403).end('Forbidden');
      return;
    }

    const body = await readFile(file);
    res.writeHead(200, { 'Content-Type': TYPES[extname(file).toLowerCase()] || 'application/octet-stream' });
    res.end(body);
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain' }).end('Not found');
  }
});

server.listen(PORT, () => {
  console.log(`AfriStream portal preview running at http://localhost:${PORT}`);
});
