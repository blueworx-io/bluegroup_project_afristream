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
// As of July 2026 IMDb escalates to an INTERACTIVE "Human Verification" page
// (HTTP 202) that no user-agent or headed browser clears on its own — it waits
// for a person. When that happens the run fails with instructions to use:
//
//   npm run sync-watchlist -- --supervised
//
// which opens a real window, gives you five minutes to click through the check,
// then continues unattended and saves the cleared session to .imdb-session.json
// (git-ignored) so later plain runs usually need no human at all.
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
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { bakePicks } from './bake-picks.mjs';
import { mergeEntries, parseEntries } from './picks-list.mjs';

const DEFAULT_URL = 'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/?ref_=ext_shr_lnk';
const WATCHLIST_URL = process.env.IMDB_WATCHLIST_URL || DEFAULT_URL;
const OUT = join(process.cwd(), 'data', 'editor-picks-ids.txt');
// 250 rows a page, so this is a 10,000-title ceiling — a backstop against a
// pagination loop that never terminates, not a real limit on the watchlist.
const MAX_PAGES = 40;

// IMDb now escalates to an interactive "Human Verification" page that no
// user-agent gets past — it waits for a person to click through. --supervised
// opens a real window so that person can, then carries on unattended.
const SUPERVISED = process.argv.includes('--supervised');
const CHALLENGE_TIMEOUT = 5 * 60 * 1000;
// Where the cleared challenge is kept. Git-ignored: it is a session cookie for
// an account, not source.
const STATE = join(process.cwd(), '.imdb-session.json');

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
# The sync only ever ADDS: merging means a failed or partial scrape can never
# shrink the list. To drop a title, delete its line here by hand and re-run
# \`npm run bake-picks -- --refresh\`.
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
  if (SUPERVISED) {
    console.log('Supervised run: a browser window will open. Complete IMDb\'s');
    console.log('"Human Verification" check in it; the scrape continues by itself.');
  }
  const browser = await chromium.launch({ headless: !SUPERVISED });
  try {
    // A realistic user-agent/locale/viewport is what gets us past IMDb's WAF —
    // the default headless "HeadlessChrome" UA is challenged and never resolves.
    // When IMDb escalates to its interactive challenge no user-agent helps: the
    // page waits for a human, which is what --supervised is for.
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
      locale: 'en-US',
      viewport: { width: 1280, height: 900 },
      storageState: existsSync(STATE) ? STATE : undefined,
    });
    const page = await context.newPage();
    // IMDb renders at most 250 rows per page and paginates the rest behind
    // ?page=N, so walk the pages until the list total is covered. Within a page
    // the rows lazy-load in blocks of 25 as you approach the bottom.
    const entries = [];
    const seen = new Set();
    let total = 0;

    for (let pageNo = 1; pageNo <= MAX_PAGES; pageNo++) {
      const url = new URL(WATCHLIST_URL);
      if (pageNo > 1) url.searchParams.set('page', String(pageNo));
      await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 60000 });
      // Wait for the WAF challenge to clear and the list to start rendering.
      // Supervised runs wait far longer on the first page, because that is the
      // one a human may have to click through.
      const settle = SUPERVISED && pageNo === 1 ? CHALLENGE_TIMEOUT : 60000;
      try {
        await page.waitForFunction(
          () => /Watchlist/i.test(document.title) &&
            document.querySelectorAll('li.ipc-metadata-list-summary-item').length > 0,
          null,
          { timeout: settle }
        );
      } catch {
        if (pageNo === 1) {
          const title = await page.title().catch(() => '');
          if (/verification|robot|captcha/i.test(title)) {
            throw new Error(
              `IMDb served its interactive challenge ("${title}") and it was not cleared.\n` +
              '  This one needs a person: re-run as `npm run sync-watchlist -- --supervised`,\n' +
              '  click through the check in the window that opens, and the scrape carries on.'
            );
          }
          throw new Error('The watchlist never rendered any rows.');
        }
        break; // past the last page
      }

      // Keep the cleared challenge, so later runs start already trusted and
      // usually need no human at all.
      if (pageNo === 1) {
        await context.storageState({ path: STATE }).catch(() => {});
      }

      // Scroll this page until its rows stop arriving. Two things a simpler loop
      // got wrong and why they matter:
      //   - jumping straight to scrollHeight can overshoot the lazy-load
      //     sentinel, so step to just above the bottom and nudge;
      //   - IMDb sometimes swaps infinite scroll for an explicit "more" button.
      // Tolerance is generous because a stall is usually network lag, not the end.
      let rows = 0;
      let prev = -1;
      let stalls = 0;
      for (let i = 0; i < 120; i++) {
        ({ rows, total } = await page.evaluate(() => {
          // This label has changed shape twice, and BOTH failures were silent
          // under-counts rather than errors, so it is parsed defensively.
          //   - a two-item inline list, "1 - 250" then "302 titles", whose plain
          //     textContent runs together as "1 - 250302 titles" (a six-figure
          //     total);
          //   - one node reading "1-250of 333", where taking the first number
          //     yields a total of 1, and the caller's "collected >= total" test
          //     then stops after page one and reports success.
          // So: prefer the count the label names as the total, and never fall
          // back to the first number in a range.
          const totalEl = document.querySelector('[data-testid="list-page-mc-total-items"]');
          const items = totalEl ? [...totalEl.querySelectorAll('li')].map((li) => li.textContent.trim()) : [];
          const label = String(items.length ? items[items.length - 1] : (totalEl ? totalEl.textContent : ''));
          const num = (m) => (m ? Number(m[1].replace(/,/g, '')) : 0);
          const total = num(label.match(/\bof\s*([\d,]+)/i)) ||
            num(label.match(/([\d,]+)\s*titles?\b/i)) ||
            // Last resort: the largest number present, which beats the first in
            // every layout seen so far.
            (label.match(/[\d,]+/g) || []).reduce((a, s) => Math.max(a, Number(s.replace(/,/g, ''))), 0);
          return {
            rows: document.querySelectorAll('li.ipc-metadata-list-summary-item').length,
            total,
          };
        }));
        stalls = rows === prev ? stalls + 1 : 0;
        if (stalls >= 12) break; // growth on this page has genuinely stopped
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

      const pageEntries = await page.evaluate(extractEntries);
      let fresh = 0;
      for (const e of pageEntries) {
        if (seen.has(e.id)) continue;
        seen.add(e.id);
        entries.push(e);
        fresh += 1;
      }
      console.log(`  page ${pageNo}: ${rows} rows, ${fresh} new (${entries.length}${total ? `/${total}` : ''})`);

      // Nothing new means we are past the end, or IMDb served the same page
      // again — either way there is no more to collect.
      if (!fresh) break;
      if (total && entries.length >= total) break;
    }

    if (!entries.length) {
      console.error('No title IDs found on the page — leaving the existing list untouched.');
      process.exitCode = 1;
      return;
    }
    if (total && entries.length < total) {
      console.warn(`Warning: collected ${entries.length} of ${total} titles — the scrape may be incomplete.`);
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
      `Scraped ${entries.length} titles${total ? ` of ${total}` : ''}: ` +
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
