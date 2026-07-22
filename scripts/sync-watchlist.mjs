// Syncs Editor Picks from an IMDb watchlist into data/editor-picks-ids.txt, then
// hands the list to bake-picks.mjs, which resolves every title through TMDB and
// writes data/editor-picks.json — the file the plugin actually serves.
//
// IMDb's watchlist page is behind AWS WAF and returns an empty challenge to a
// plain server fetch, so this uses a real browser (Playwright) to render it and
// read the ordered title IDs from the page's __NEXT_DATA__. Because the plugin
// is built and zipped for manual deploy, this runs at build/deploy time, not on
// the live WordPress server. Run whenever the watchlist changes:
//
//   npm run sync-watchlist
//
// The watchlist URL comes from the IMDB_WATCHLIST_URL env var, falling back to
// DEFAULT_URL below. On success it overwrites data/editor-picks-ids.txt; if no
// IDs are found it exits non-zero and leaves the existing file untouched.
//
// The list lazy-loads 25 rows at a time, so the scroll loop below pages through
// it. It refuses to overwrite the existing file with a short list: a partial
// scrape (IMDb changing its markup, a slow network) would otherwise silently
// shrink the watchlist, which is exactly how a 130-title list once became 25.

import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { bakePicks } from './bake-picks.mjs';
import { mergeEntries, parseEntries } from './picks-list.mjs';

const DEFAULT_URL = 'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/?ref_=ext_shr_lnk';
const WATCHLIST_URL = process.env.IMDB_WATCHLIST_URL || DEFAULT_URL;
const OUT = join(process.cwd(), 'data', 'editor-picks-ids.txt');

const HEADER = `# Editor Picks — IMDb title IDs, in watchlist order.
#
# ACCUMULATED by \`npm run sync-watchlist\` from the configured IMDb watchlist.
# One entry per line as "<tt-id> <imdb-rating>", the rating omitted when IMDb
# has none yet; lines starting with # are comments. The plugin reads this as the
# default Editor Picks list (the WP admin "Editor Picks (IMDb IDs)" box
# overrides it when set); \`npm run bake-picks\` resolves each id through TMDB
# into data/editor-picks.json, which is what actually gets served. Only the
# first N (see the read-side cap) are used.
#
# The sync only ever ADDS. IMDb shows at most 250 rows of a public watchlist, so
# each scrape is a window onto the list rather than all of it — merging keeps
# titles that have scrolled out of that window and means a failed or partial
# scrape can never shrink the list. To drop a title, delete its line here by
# hand (and its entry in editor-picks.json, or re-run bake-picks --refresh).
#
# Note: this is the public IMDb community rating, not the watchlist owner's
# personal rating — a shared watchlist does not expose the owner's own ratings
# to an anonymous viewer.
`;

// Runs in the browser: ordered, deduped entries of { id, rating }. The rendered
// list carries every item (the SSR __NEXT_DATA__ only holds the first page), so
// read it from the list rows; fall back to __NEXT_DATA__ then any title link if
// the markup ever changes.
function extractEntries() {
  const out = [];
  const seen = new Set();
  const push = (id, rating) => {
    if (!/^tt\d+$/.test(id) || seen.has(id)) return;
    seen.add(id);
    out.push({ id, rating: rating || null });
  };

  const rows = document.querySelectorAll('li.ipc-metadata-list-summary-item');
  for (const li of rows) {
    const a = li.querySelector('a[href*="/title/tt"]');
    const m = a && (a.getAttribute('href') || '').match(/\/title\/(tt\d+)/);
    if (!m) continue;
    // The IMDb community rating, e.g. "8.0 (312K)". Deliberately scoped to the
    // imdb-rating node so the adjacent "Rate" button (the viewer's own, always
    // unrated when signed out) can never be mistaken for a score.
    const el = li.querySelector('[data-testid="ratingGroup--imdb-rating"]');
    const r = el && el.textContent.match(/(\d+(?:\.\d+)?)/);
    push(m[1], r ? r[1] : null);
  }
  if (out.length) return out;

  const nd = document.getElementById('__NEXT_DATA__');
  if (nd) {
    try {
      const data = JSON.parse(nd.textContent);
      const walk = (o, depth) => {
        if (!o || typeof o !== 'object' || depth > 40) return;
        if (Array.isArray(o)) { for (const v of o) walk(v, depth + 1); return; }
        if (typeof o.id === 'string' && /^tt\d+$/.test(o.id) && (o.titleText || o.titleType || o.releaseYear)) {
          const agg = o.ratingsSummary && o.ratingsSummary.aggregateRating;
          push(o.id, typeof agg === 'number' ? String(agg) : null);
        }
        for (const k in o) walk(o[k], depth + 1);
      };
      walk(data, 0);
    } catch { /* streaming/incomplete JSON — fall through */ }
  }
  if (!out.length) {
    for (const a of document.querySelectorAll('a[href*="/title/tt"]')) {
      const m = (a.getAttribute('href') || '').match(/\/title\/(tt\d+)/);
      if (m) push(m[1], null);
    }
  }
  return out;
}

async function main() {
  console.log(`Syncing IMDb watchlist:\n  ${WATCHLIST_URL}`);
  const browser = await chromium.launch();
  try {
    // A realistic user-agent/locale/viewport is what gets us past IMDb's WAF —
    // the default headless "HeadlessChrome" UA is challenged and never resolves.
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
      locale: 'en-US',
      viewport: { width: 1280, height: 900 },
    });
    const page = await context.newPage();
    await page.goto(WATCHLIST_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
    // Wait for the WAF challenge to clear and the list to start rendering.
    await page.waitForFunction(
      () => /Watchlist/i.test(document.title) &&
        document.querySelectorAll('li.ipc-metadata-list-summary-item').length > 0,
      null,
      { timeout: 60000 }
    );
    // The list renders 25 rows at a time and appends more as you approach the
    // bottom, so keep scrolling until the rendered count reaches the list total.
    // Two things the earlier, simpler loop got wrong and why they matter:
    //   - jumping straight to scrollHeight can overshoot the lazy-load sentinel,
    //     so step to just above the bottom and nudge, rather than teleporting;
    //   - IMDb sometimes swaps infinite scroll for an explicit "more" button, so
    //     click one if it appears.
    // Tolerance is generous because a stall is usually network lag, not the end.
    let total = 0;
    let rows = 0;
    let prev = -1;
    let stalls = 0;
    for (let i = 0; i < 120; i++) {
      ({ rows, total } = await page.evaluate(() => {
        const totalEl = document.querySelector('[data-testid="list-page-mc-total-items"]');
        // Advisory only — see the loop below. IMDb has worded this label as
        // "130 titles", as "1 - 25 of 130 titles", and as something that parses
        // to a nonsense six-figure number, so nothing here is load-bearing.
        const text = totalEl ? totalEl.textContent : '';
        const of = text.match(/\bof\s+([\d,]+)/i);
        const first = text.match(/([\d,]+)/);
        const num = (m) => (m ? Number(m[1].replace(/,/g, '')) : 0);
        return {
          rows: document.querySelectorAll('li.ipc-metadata-list-summary-item').length,
          total: num(of) || num(first),
        };
      }));
      // The stall detector is what actually ends this loop. The total label was
      // trusted for that once and it stopped the scrape on its first pass (it
      // read the "1" out of "1 - 25 of 130"), so total is now only believed when
      // it is plausible — otherwise scroll until the row count stops growing.
      const plausible = total > 0 && total < 10000;
      if (plausible && rows >= total) break;
      stalls = rows === prev ? stalls + 1 : 0;
      if (stalls >= 12) break; // growth has genuinely stopped
      prev = rows;
      const clicked = await page.evaluate(() => {
        const btn = [...document.querySelectorAll('button')].find(
          (b) => /\bmore\b/i.test(b.innerText || '') && b.offsetParent !== null
        );
        if (btn) { btn.click(); return true; }
        window.scrollTo(0, document.body.scrollHeight - window.innerHeight - 200);
        window.scrollBy(0, 400);
        return false;
      });
      await page.waitForTimeout(clicked ? 1500 : 900);
    }
    if (total > 0 && total < 10000 && rows < total) {
      console.warn(`Warning: only ${rows} of ${total} rows rendered — the list may be incomplete.`);
    }

    const entries = await page.evaluate(extractEntries);
    if (!entries.length) {
      console.error('No title IDs found on the page — leaving the existing list untouched.');
      process.exitCode = 1;
      return;
    }
    // Merge into the list already on disk rather than replacing it. IMDb caps a
    // public watchlist view at 250 rows, so a scrape is a window onto the list,
    // not the whole of it — overwriting would throw away everything outside that
    // window every time. Accumulating lets the list grow past the cap and makes
    // a bad scrape a no-op instead of data loss.
    let existing = [];
    try {
      existing = parseEntries(readFileSync(OUT, 'utf8'));
    } catch { /* no file yet — first run */ }
    const { entries: merged, added, updated } = mergeEntries(existing, entries);

    const lines = merged.map((e) => (e.rating === null ? e.id : `${e.id} ${e.rating}`));
    writeFileSync(OUT, HEADER + lines.join('\n') + '\n');
    const rated = merged.filter((e) => e.rating !== null).length;
    console.log(
      `Scraped ${entries.length} rows${total ? ` (list reports ${total})` : ''}: ` +
        `${added} new, ${updated} rating${updated === 1 ? '' : 's'} changed.`
    );
    console.log(`data/editor-picks-ids.txt now holds ${merged.length} titles (${rated} with an IMDb rating).`);

    await bakePicks(merged);
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
