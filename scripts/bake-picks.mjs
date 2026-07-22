// Resolves the Editor Picks watchlist through TMDB and writes the finished list
// to data/editor-picks.json — the file the plugin serves.
//
// Resolving at build time is the point: TMDB needs one /find round-trip per
// title, and doing that from WordPress meant a cold cache spent the best part of
// a minute resolving 130 titles while the visitor watched the page fill in.
// Baked, the endpoint is a file read. The runtime resolver stays in the plugin
// for the WP admin override box, which can carry IDs this file has never seen.
//
// Reads data/editor-picks-ids.txt (written by `npm run sync-watchlist`), so it
// can be re-run on its own whenever artwork or ratings need refreshing without
// re-scraping IMDb:
//
//   npm run bake-picks
//
// Needs TMDB_API_KEY in the environment. On any failure it leaves the existing
// data/editor-picks.json untouched and exits non-zero.

import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import process from 'node:process';

const ROOT = process.cwd();
const IDS = join(ROOT, 'data', 'editor-picks-ids.txt');
const OUT = join(ROOT, 'data', 'editor-picks.json');
const TMDB = 'https://api.themoviedb.org/3';

// Origin countries — same map the plugin and the preview server carry.
const COUNTRIES = {
  US: 'United States', GB: 'United Kingdom', ZA: 'South Africa',
  NG: 'Nigeria', KE: 'Kenya', IN: 'India', FR: 'France',
  ES: 'Spain', KR: 'South Korea', JP: 'Japan', BR: 'Brazil',
  DE: 'Germany', AU: 'Australia', EG: 'Egypt',
};

// "<tt-id> <imdb-rating>" per line, rating optional, # comments ignored.
// Mirrors afristream_portal_editor_ids() in the plugin.
export function parseEntries(raw) {
  const out = new Map();
  for (const line of String(raw).split(/[\r\n,]+/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const m = trimmed.match(/(tt\d+)(?:\D+(\d+(?:\.\d+)?))?/);
    if (!m || out.has(m[1])) continue;
    // Guard the rating: a stray number on the line (a year pasted alongside the
    // ID, say) must not masquerade as a 0-10 score.
    const rating = m[2] !== undefined && Number(m[2]) >= 0 && Number(m[2]) <= 10 ? Number(m[2]) : null;
    out.set(m[1], rating);
  }
  return [...out.entries()].map(([id, rating]) => ({ id, rating }));
}

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

// One IMDb ID -> the pick shape portal.js renders. Mirrors
// afristream_portal_resolve_pick() in the plugin and resolvePick() in the
// preview server; the three must stay in step.
async function resolvePick(imdbId, movieGenres, tvGenres) {
  const json = await tmdbGet('/find/' + imdbId, { external_source: 'imdb_id' });
  const movie = json?.movie_results?.[0];
  const tv = json?.tv_results?.[0];
  const hit = movie || tv;
  if (!hit) return null;
  const type = movie ? 'Movies' : 'Series';
  const genres = movie ? movieGenres : tvGenres;
  const year = String(hit.release_date || hit.first_air_date || '').slice(0, 4);
  const rating = Number(hit.vote_average) || 0;
  return {
    t: hit.title || hit.name || '',
    id: Number(hit.id) || 0,
    genre: genres[hit.genre_ids?.[0]] || type,
    // TMDB's score — overridden below by the IMDb rating when the list has one.
    rating: rating > 0 ? Math.round(rating * 10) / 10 : null,
    platform: rating > 0 ? `★ ${rating.toFixed(1)}` : 'IMDb',
    meta: type === 'Series' ? (year ? `TV · ${year}` : 'TV') : year,
    poster: hit.poster_path ? `https://image.tmdb.org/t/p/w342${hit.poster_path}` : null,
    type,
    country: COUNTRIES[hit.origin_country?.[0]] || '',
    rank: 0,
  };
}

// Resolve the whole list and write data/editor-picks.json. Runs in small batches:
// TMDB's rate limit is generous but a few hundred simultaneous requests is a good
// way to start collecting 429s, and this is a build step — it can afford to wait.
export async function bakePicks(entries) {
  if (!process.env.TMDB_API_KEY) {
    console.error('TMDB_API_KEY is not set — cannot resolve the list.');
    process.exitCode = 1;
    return null;
  }
  const genreList = async (type) =>
    Object.fromEntries(((await tmdbGet(`/genre/${type}/list`))?.genres ?? []).map((g) => [g.id, g.name]));
  const [movieGenres, tvGenres] = await Promise.all([genreList('movie'), genreList('tv')]);
  if (!Object.keys(movieGenres).length && !Object.keys(tvGenres).length) {
    console.error('TMDB returned no genres — key rejected or the API is down. Leaving data/editor-picks.json untouched.');
    process.exitCode = 1;
    return null;
  }

  const picks = [];
  const BATCH = 8;
  for (let i = 0; i < entries.length; i += BATCH) {
    const slice = entries.slice(i, i + BATCH);
    const resolved = await Promise.all(slice.map((e) => resolvePick(e.id, movieGenres, tvGenres)));
    resolved.forEach((pick, j) => {
      if (!pick) return;
      // The IMDb community rating from the watchlist beats TMDB's own score.
      const rating = slice[j].rating;
      if (rating !== null) {
        pick.rating = rating;
        pick.platform = `★ ${rating.toFixed(1)}`;
      }
      picks.push(pick);
    });
    process.stdout.write(`\r  resolved ${picks.length}/${entries.length}`);
  }
  process.stdout.write('\n');

  if (!picks.length) {
    console.error('Nothing resolved through TMDB — leaving data/editor-picks.json untouched.');
    process.exitCode = 1;
    return null;
  }
  // Ranks are re-sequenced gap-free over what actually resolved, matching both
  // the PHP and preview-server resolvers.
  picks.forEach((p, i) => { p.rank = i + 1; });

  const payload = { source: 'imdb', updated: new Date().toISOString(), picks };
  writeFileSync(OUT, JSON.stringify(payload, null, 2) + '\n');
  const missed = entries.length - picks.length;
  console.log(
    `Wrote ${picks.length} resolved picks to data/editor-picks.json` +
      `${missed ? ` (${missed} not found on TMDB)` : ''}`
  );
  return payload;
}

// Run directly (`npm run bake-picks`) — resolve whatever is in the IDs file.
// pathToFileURL rather than string-building the URL: on Windows argv[1] is a
// drive path, and a hand-rolled file:// prefix never matches import.meta.url.
if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  const entries = parseEntries(readFileSync(IDS, 'utf8'));
  if (!entries.length) {
    console.error(`No title IDs in ${IDS}.`);
    process.exitCode = 1;
  } else {
    console.log(`Resolving ${entries.length} titles through TMDB…`);
    await bakePicks(entries);
  }
}
