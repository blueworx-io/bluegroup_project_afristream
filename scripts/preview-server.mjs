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
import { readFileSync } from 'node:fs';
import { extname, join, normalize, sep } from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const PORT = Number(process.env.PORT) || 4173;

const FIXTURE = {
  source: 'live',
  updated: 'fixture',
  tmdb: true,
  sport: [
    { comp: 'Fixture League', code: 'Soccer', country: 'England', fx: 'Fixture FC vs Test United', time: 'Today · 20:00', ch: 'Fixture Sports', chCountry: 'United Kingdom', live: true },
    { comp: 'Fixture Open', code: 'Tennis', country: 'Australia', fx: 'A. Player vs B. Player', time: 'Tomorrow · 10:00', ch: 'Fixture Tennis', chCountry: 'Australia', live: false },
  ],
  movies: [
    { t: 'Fixture Movie One', genre: 'Drama', platform: '★ 8.1', meta: '2026', poster: null, type: 'Movies', id: 101 },
    { t: 'Fixture Movie Two', genre: 'Action', platform: '★ 7.4', meta: '2025', poster: null, type: 'Movies', id: 102 },
  ],
  series: [
    { t: 'Fixture Series One', genre: 'Crime', platform: '★ 8.6', meta: 'TV · 2026', poster: null, type: 'Series', id: 201 },
  ],
  newWeek: [
    { t: 'Fixture New Arrival', genre: 'Comedy', platform: '★ 7.0', meta: 'New episodes', poster: null, type: 'Series', id: 301 },
  ],
  catalog: [
    { t: 'Jozi Heat', genre: 'Crime', platform: '★ 7.8', meta: '2023', poster: null, type: 'Movies', country: 'South Africa', id: 401 },
    { t: 'Lagos Lights', genre: 'Drama', platform: '★ 8.0', meta: '2019', poster: null, type: 'Movies', country: 'Nigeria', id: 402 },
    { t: 'Seoul Signal', genre: 'Thriller', platform: '★ 8.4', meta: 'TV · 2021', poster: null, type: 'Series', country: 'South Korea', id: 403 },
    { t: 'London Fog', genre: 'Mystery', platform: '★ 7.2', meta: '2008', poster: null, type: 'Movies', country: 'United Kingdom', id: 404 },
    { t: 'Nairobi Nights', genre: 'Drama', platform: '★ 7.5', meta: 'TV · 1998', poster: null, type: 'Series', country: 'Kenya', id: 405 },
    // Shares a title with a trending row (Fixture Movie One) to exercise the
    // country back-fill: the deduped trending copy should inherit this country.
    { t: 'Fixture Movie One', genre: 'Drama', platform: '★ 8.1', meta: '2026', poster: null, type: 'Movies', country: 'United States', id: 406 },
  ],
};

// Editor Picks are driven by a hand-curated list of IMDb title IDs (IMDb's
// watchlist page itself is behind AWS WAF and can't be scraped server-side).
// Locally the IDs come from the EDITOR_PICKS_IDS env var; in the plugin they
// come from the afristream_editor_picks_ids option. Any tt-id (or a pasted
// IMDb URL containing one) is accepted, optionally followed by the IMDb rating
// the sync recorded ("tt0099348 8.0"); order is preserved, capped at PICK_CAP.
// Mirrors afristream_portal_editor_ids() in the plugin — keep the two in step.
const PICK_CAP = 300;

function parseEditorIds(raw) {
  const out = new Map();
  for (const line of String(raw || '').split(/[\r\n,]+/)) {
    const s = line.trim();
    if (!s || s[0] === '#') continue;
    const m = s.match(/(tt\d+)(?:\D+(\d+(?:\.\d+)?))?/);
    if (!m || out.has(m[1])) continue;
    // Guard against a stray number on the line (a pasted year, say) being read
    // as a 0–10 score.
    const rating = m[2] !== undefined && +m[2] >= 0 && +m[2] <= 10 ? +m[2] : null;
    out.set(m[1], rating);
    if (out.size >= PICK_CAP) break;
  }
  return [...out.entries()].map(([id, rating]) => ({ id, rating }));
}

// The EDITOR_PICKS_IDS env wins (mirrors the WP admin box); otherwise fall back
// to the synced watchlist file that ships with the plugin.
function editorIdsSource() {
  if (process.env.EDITOR_PICKS_IDS) return process.env.EDITOR_PICKS_IDS;
  try {
    return readFileSync(join(ROOT, 'data', 'editor-picks-ids.txt'), 'utf8');
  } catch {
    return '';
  }
}

const EDITOR_FIXTURE = {
  source: 'imdb',
  picks: [
    { t: 'Fixture Pick One', genre: 'Drama', platform: '★ 8.5', rating: 8.5, meta: '2024', poster: null, type: 'Movies', country: 'South Africa', rank: 1, id: 501 },
    { t: 'Fixture Pick Two', genre: 'Thriller', platform: '★ 8.1', rating: 8.1, meta: 'TV · 2023', poster: null, type: 'Series', country: 'Nigeria', rank: 2, id: 502 },
    { t: 'Fixture Pick Three', genre: 'Comedy', platform: '★ 7.6', rating: 7.6, meta: '2022', poster: null, type: 'Movies', country: 'Kenya', rank: 3, id: 503 },
    { t: 'Fixture Pick Four', genre: 'Documentary', platform: '★ 9.1', rating: 9.1, meta: '2021', poster: null, type: 'Movies', country: 'Kenya', rank: 4, id: 504 },
  ],
};

// Deterministic credentials fixture for the Profile tab (mirrors the plugin's
// afristream/v1/credentials, which is ACF-backed and per-user in production).
const CREDENTIALS_FIXTURE = {
  source: 'acf',
  profiles: [
    { label: 'Profile 1', user: 'afri_fixture', pass: 'Fx9Kp2Lm' },
    { label: 'Profile 2', user: 'afri_fixture_tv', pass: 'Tv4Qr8Zn' },
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
      ? { t: fallbackTitle, genre: 'Film', platform: 'IMDb', rating: null, meta: '', poster: null, type: 'Movies', country: '', rank, id: 0 }
      : null;
  }
  const type = movie ? 'Movies' : 'Series';
  const genres = movie ? movieGenres : tvGenres;
  const year = String(hit.release_date || hit.first_air_date || '').slice(0, 4);
  const rating = Number(hit.vote_average) || 0;
  return {
    t: hit.title || hit.name || fallbackTitle || '',
    id: Number(hit.id) || 0,
    genre: genres[hit.genre_ids?.[0]] || type,
    // TMDB's score — overridden by the synced IMDb rating when the list has one.
    rating: rating > 0 ? Math.round(rating * 10) / 10 : null,
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
  const ids = parseEditorIds(editorIdsSource());
  if (!ids.length) return editorCache || { source: 'fallback', reason: 'no-ids' };
  try {
    const genreList = async (type) =>
      Object.fromEntries(((await tmdbGet(`/genre/${type}/list`))?.genres ?? []).map((g) => [g.id, g.name]));
    const [movieGenres, tvGenres] = await Promise.all([genreList('movie'), genreList('tv')]);
    const resolved = await Promise.all(
      ids.map(async ({ id, rating }, i) => {
        const pick = await resolvePick(id, '', i + 1, movieGenres, tvGenres);
        // The IMDb rating from the synced list beats TMDB's own score.
        if (pick && rating !== null) {
          pick.rating = rating;
          pick.platform = `★ ${rating.toFixed(1)}`;
        }
        return pick;
      })
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
      id: Number(row.id) || 0,
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
      id: Number(row.id) || 0,
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
// Each league maps to { label, code, country }: `code` is the sporting code
// (drives the "Sport Type" filter) and `country` the host nation (or
// 'International' for global competitions). Keep in sync with the PHP $leagues.
const ESPN_LEAGUES = {
  // Soccer — mostly European seasons, so quiet over the summer.
  'soccer/fifa.world': { label: 'FIFA World Cup', code: 'Soccer', country: 'International' },
  'soccer/eng.1': { label: 'Premier League', code: 'Soccer', country: 'England' },
  'soccer/esp.1': { label: 'LaLiga', code: 'Soccer', country: 'Spain' },
  'soccer/ita.1': { label: 'Serie A', code: 'Soccer', country: 'Italy' },
  'soccer/ger.1': { label: 'Bundesliga', code: 'Soccer', country: 'Germany' },
  'soccer/fra.1': { label: 'Ligue 1', code: 'Soccer', country: 'France' },
  'soccer/uefa.champions': { label: 'Champions League', code: 'Soccer', country: 'International' },
  'soccer/uefa.europa': { label: 'Europa League', code: 'Soccer', country: 'International' },
  'soccer/usa.1': { label: 'MLS', code: 'Soccer', country: 'United States' },
  // Motorsport & combat.
  'racing/f1': { label: 'Formula 1', code: 'Motorsport', country: 'International' },
  'mma/ufc': { label: 'UFC', code: 'MMA', country: 'International' },
  // North American major leagues.
  'football/nfl': { label: 'NFL', code: 'American Football', country: 'United States' },
  'basketball/nba': { label: 'NBA', code: 'Basketball', country: 'United States' },
  'baseball/mlb': { label: 'MLB', code: 'Baseball', country: 'United States' },
  'hockey/nhl': { label: 'NHL', code: 'Ice Hockey', country: 'United States' },
  // Rugby, cricket, tennis, golf, Aussie rules. ESPN omits broadcaster names
  // for some of these; the mapping falls back to the competition label.
  'rugby/270557': { label: 'URC Rugby', code: 'Rugby', country: 'International' },
  'cricket/8039': { label: 'ICC World Cup', code: 'Cricket', country: 'International' },
  'cricket/8048': { label: 'ICC T20 World Cup', code: 'Cricket', country: 'International' },
  'cricket/8044': { label: 'ICC Champions Trophy', code: 'Cricket', country: 'International' },
  'tennis/atp': { label: 'ATP Tennis', code: 'Tennis', country: 'International' },
  'tennis/wta': { label: 'WTA Tennis', code: 'Tennis', country: 'International' },
  'golf/pga': { label: 'PGA Tour', code: 'Golf', country: 'United States' },
  'australian-football/afl': { label: 'AFL', code: 'Aussie Rules', country: 'Australia' },
};

async function espnSport() {
  const fmt = (ms) => new Date(ms).toISOString().slice(0, 10).replace(/-/g, '');
  const range = `${fmt(Date.now())}-${fmt(Date.now() + 7 * DAY)}`;
  const events = [];

  await Promise.all(Object.entries(ESPN_LEAGUES).map(async ([path, meta]) => {
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
        const channel = ev?.competitions?.[0]?.broadcasts?.[0]?.names?.[0] || '';
        events.push({
          comp: meta.label,
          code: meta.code,
          country: meta.country,
          fx: name.replace(' at ', ' vs '),
          iso: ev.date || '',
          time: state === 'in' ? 'LIVE now' : '',
          ch: channel || meta.label,
          // ESPN's scoreboard only carries US networks, so a named broadcaster
          // here is always a US one.
          chCountry: channel ? 'United States' : '',
          live: state === 'in',
        });
        count++;
      }
    } catch { /* league unavailable — skip */ }
  }));

  events.sort((a, b) => (a.live !== b.live ? (a.live ? -1 : 1) : a.iso.localeCompare(b.iso)));
  return events.slice(0, 8);
}

// TheSportsDB sport name => the portal's sporting code. Keep in sync with the
// plugin's afristream_portal_sportsdb_events().
const SPORTSDB_SPORTS = {
  Soccer: 'Soccer',
  Cricket: 'Cricket',
  Rugby: 'Rugby',
  Motorsport: 'Motorsport',
  Golf: 'Golf',
  Tennis: 'Tennis',
  Fighting: 'MMA',
  Basketball: 'Basketball',
  'American Football': 'American Football',
  'Australian Football': 'Aussie Rules',
};

/**
 * Sports TV listings from TheSportsDB's free tier — keyless (public key "123",
 * no registration) and permanently free. It is the only free source that names
 * broadcasters outside the US, which ESPN's scoreboard never does.
 *
 * Free tier returns a single row per `eventstv` query however it is filtered,
 * with no pagination — hence one query per sport per day, stitched together.
 */
async function sportsdbSport() {
  const day = (ms) => new Date(ms).toISOString().slice(0, 10);
  const dates = [day(Date.now()), day(Date.now() + DAY)];
  const events = [];

  await Promise.all(dates.flatMap((date) => Object.entries(SPORTSDB_SPORTS).map(async ([sport, code]) => {
    try {
      const res = await fetch(
        `https://www.thesportsdb.com/api/v1/json/123/eventstv.php?d=${encodeURIComponent(date)}&s=${encodeURIComponent(sport)}`,
        { signal: AbortSignal.timeout(6000) }
      );
      if (!res.ok) return;
      const json = await res.json();
      for (const row of json?.tvevents ?? []) {
        if (!row?.strEvent || !row?.strChannel) continue;
        // Times are UTC; the front-end renders `iso` in the viewer's zone.
        events.push({
          comp: row.strSeason ? `${sport} · ${row.strSeason}` : sport,
          code,
          country: row.strEventCountry || 'International',
          fx: row.strEvent,
          iso: `${row.dateEvent || date}T${row.strTime || '00:00:00'}+00:00`,
          time: '',
          ch: row.strChannel,
          chCountry: row.strCountry || '',
          live: false,
        });
      }
    } catch { /* sport unavailable — skip */ }
  })));

  return events;
}

/**
 * Normalised key for de-duplicating the same fixture arriving from both feeds
 * ("Arsenal at Everton" vs "Everton vs Arsenal"). Team order is discarded.
 */
function sportKey(event) {
  return String(event.fx || '')
    .toLowerCase()
    .split(/\s+(?:vs?\.?|at|v)\s+/)
    .map((part) => part.replace(/[^a-z0-9]/g, ''))
    .filter(Boolean)
    .sort()
    .join('|');
}

/**
 * Both free feeds merged. ESPN supplies the fixture list and US networks;
 * TheSportsDB supplies broadcasters for the rest of the world. Where a fixture
 * appears in both, the row that actually names a broadcaster wins.
 */
async function mergedSport() {
  const [espn, sportsdb] = await Promise.all([espnSport(), sportsdbSport()]);
  const merged = new Map();

  for (const event of [...espn, ...sportsdb]) {
    const key = sportKey(event);
    if (!key) continue;
    const existing = merged.get(key);
    if (!existing) {
      merged.set(key, event);
      continue;
    }
    const hasChannel = event.ch && event.ch !== event.comp;
    const hadChannel = existing.ch && existing.ch !== existing.comp;
    if (hasChannel && !hadChannel) {
      existing.ch = event.ch;
      existing.chCountry = event.chCountry || '';
    }
  }

  const events = [...merged.values()];
  events.sort((a, b) => (a.live !== b.live ? (a.live ? -1 : 1) : a.iso.localeCompare(b.iso)));
  return events.slice(0, 12);
}

async function watchPayload() {
  if (process.env.WATCH_OFFLINE === '1') return { source: 'fallback', reason: 'offline' };
  if (watchCache && Date.now() - watchCacheAt < 2 * 60 * 60 * 1000) return watchCache;

  const [catalog, sport] = await Promise.all([tmdbCatalog(), mergedSport()]);
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

async function detailPayload(id, type) {
  const kind = type === 'tv' ? 'tv' : 'movie';
  if (process.env.WATCH_OFFLINE === '1' || !id) return { overview: '' };
  if (!process.env.TMDB_API_KEY) return { overview: '' };
  const safeId = Number(id) || 0;
  const json = await tmdbGet(`/${kind}/${safeId}`);
  return { overview: (json && json.overview) || '' };
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
    if (path === '/api/credentials') {
      const payload = url.searchParams.get('fixture') === '1'
        ? CREDENTIALS_FIXTURE
        : { source: 'fallback', profiles: [] };
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(payload));
      return;
    }
    if (path === '/api/detail') {
      const id = url.searchParams.get('id') || '';
      const type = url.searchParams.get('type') || 'movie';
      const payload = url.searchParams.get('fixture') === '1'
        ? { overview: `Fixture synopsis for ${id}.` }
        : await detailPayload(id, type);
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
