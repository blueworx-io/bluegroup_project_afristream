// Tiny dependency-free static server for previewing the portal locally:
//   npm run preview  →  http://localhost:4173
// Serves the repo root; "/" maps to preview/index.html, which loads the same
// assets the WordPress plugin enqueues.
//
// /api/watch mirrors the plugin's WP REST endpoint (afristream/v1/watch):
// with a TMDB_API_KEY env var it proxies live TMDB data (cached in memory);
// without one it returns source:"fallback" so the portal keeps its built-in
// lists. /api/watch?fixture=1 always returns a small deterministic payload
// for the Playwright tests.

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const PORT = Number(process.env.PORT) || 4173;

const FIXTURE = {
  source: 'tmdb',
  updated: 'fixture',
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
};

const TMDB = 'https://api.themoviedb.org/3';
let watchCache = null;
let watchCacheAt = 0;

async function tmdbGet(path, params = {}) {
  const url = new URL(TMDB + path);
  url.searchParams.set('api_key', process.env.TMDB_API_KEY);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url);
  if (!res.ok) return null;
  return res.json();
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

async function watchPayload() {
  if (!process.env.TMDB_API_KEY) return { source: 'fallback', reason: 'no-key' };
  if (watchCache && Date.now() - watchCacheAt < 12 * 60 * 60 * 1000) return watchCache;

  const day = 24 * 60 * 60 * 1000;
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
        'release_date.gte': iso(Date.now() - 21 * day),
        'release_date.lte': iso(Date.now()),
      }),
      tmdbGet('/tv/on_the_air'),
    ]);
    if (!trendingMovies && !trendingTv) return { source: 'fallback', reason: 'tmdb-unreachable' };

    watchCache = {
      source: 'tmdb',
      updated: new Date().toISOString(),
      movies: mapItems(trendingMovies, movieGenres, 'Movies', 10),
      series: mapItems(trendingTv, tvGenres, 'Series', 10),
      newWeek: [
        ...mapItems(newMovies, movieGenres, 'Movies', 4, 'New release'),
        ...mapItems(onAir, tvGenres, 'Series', 4, 'New episodes'),
      ],
    };
    watchCacheAt = Date.now();
    return watchCache;
  } catch {
    return { source: 'fallback', reason: 'tmdb-unreachable' };
  }
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
