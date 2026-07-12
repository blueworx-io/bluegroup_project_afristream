# Expand Watch Content — Sports, Collections, Editor Picks & Watchlist Sync

**Date:** 2026-07-12
**Branch:** `expand-watch-content`
**Version:** 0.6.0 → **0.7.0** (minor — new features)
**Type:** WordPress plugin (AfriStream Customer Portal). Front-end is a single vanilla-JS
IIFE in `assets/portal.js`; live data comes from TMDB (movies/series) and ESPN (sport),
each with curated fallbacks. Local preview mirrors the WP REST endpoints via
`scripts/preview-server.mjs`.

This spec covers five independent changes shipped together on one branch / one PR.

---

## 1. More sports — Cricket, Golf, Rugby, Soccer

**Current state**
- Live sport comes from `ESPN_LEAGUES` in `scripts/preview-server.mjs` and the mirrored
  `$leagues` array in `afristream-portal.php` (kept in sync by hand).
- Golf (`golf/pga`) and Rugby (`rugby/270557`) **already exist** in the ESPN map but are
  **absent from the curated `SPORT` fallback** in `assets/portal.js`, so they vanish when
  ESPN returns nothing (e.g. off-season).
- Soccer currently uses the category **code `'Football'`** (ESPN's `soccer/*` leagues).
- Cricket is **missing entirely**.

**Changes**
1. **Rename `code: 'Football'` → `code: 'Soccer'`** on every `soccer/*` league in both
   `ESPN_LEAGUES` (preview-server) and `$leagues` (PHP), and on the Soccer entries in the
   curated `SPORT` fallback. American Football (`football/nfl`) stays `American Football`.
2. **Add Cricket leagues** to both maps (verified live against ESPN):
   - `cricket/8039` → World Cup
   - `cricket/8048` and `cricket/8044` → additional international/T20 competitions
   - Each mapped as `{ label, code: 'Cricket', country: 'International' }`. Final label/id
     set confirmed during implementation against the live ESPN scoreboard.
3. **Add curated fallback fixtures** to the `SPORT` array in `assets/portal.js` for
   **Cricket, Golf, Rugby, and Soccer**, so all four always appear even with no live ESPN
   data. Existing Football fallback entries become Soccer.

**Sync rule:** `ESPN_LEAGUES` (preview-server) and `$leagues` (PHP) MUST stay identical —
both files carry the existing "keep in sync" comment.

**No new sport category hue needed** — all sport cards share the single `bg('Sport')` hue.

---

## 2. Collections — real, auto-populated data (no manual title lists)

**Current state:** `COLLECTIONS` is a static array of 5 cards with a fake `"12 titles"`
string and **no member data**. Clicking a card re-prints only the description. No
`collections` source exists in any API.

**Approach (decided):** each collection is a **live query over the TMDB data already
fetched** (`data.movies`, `data.series`, `data.newWeek`, `data.catalog` — surfaced via the
flat `INDEX`). No manual title lists, no new backend endpoint, no new PHP/preview sync.

**Design**
- Redefine `COLLECTIONS` as objects with a **`match(item)` predicate** plus name / desc /
  hue, e.g.:
  | Collection | Predicate (over INDEX items) |
  |---|---|
  | Family Movie Night | `type === 'Movies'` AND genre ∈ {Family, Kids, Animation} |
  | True Crime Deep Dive | genre ∈ {Crime, Mystery, Thriller, Documentary, Docs} |
  | Award Season Catch-Up | rating ≥ 7.5, sorted by rating desc |
  | Weekend Binge | `type === 'Series'`, sorted by rating desc |
  | Big Match Build-Up | genre ∈ {Documentary, Docs} (sport-doc leaning) |
- At render time, compute `items = INDEX.filter(c.match)` (deduped) for each collection.
  - **Real count**: the card's label becomes `${items.length} titles` (dynamic).
  - **Empty handling**: collections resolving to fewer than **3** matches are **hidden**
    (prevents empty/thin cards when the live catalog lacks a genre). If *all* collections
    are thin (e.g. no live TMDB key at all), the curated fallback catalog still yields
    entertainment genres, so at least the entertainment collections populate.
- **Detail drawer:** extend the `detailKind === 'collection'` branch of `detailDrawer()` to
  render the collection's `items` as a **poster grid** (reusing `posterGridItem`), replacing
  the current count+desc-only body. The matched `items` array is attached to the collection
  object passed through `cardAttrs`/`reg()` (kept in the in-memory `cardRegistry`, never
  serialised to the DOM), so no re-query is needed on open.
- **Search index:** the `COLLECTIONS` stubs in `buildIndex()` keep appearing as searchable
  entries; their `meta` uses the dynamic count.

**Why client-side filtering (not new TMDB `/discover` queries):** the portal already
receives a broad, multi-genre, multi-country TMDB catalog every load. Filtering it needs no
second endpoint and no PHP↔preview sync burden, and collections update daily for free with
the catalog. If future needs outgrow the fetched catalog, a dedicated `collections` source
can be added later without changing the front-end contract.

---

## 3. Editor Picks header — match What to Watch (title + description + tags, no search/filters)

**Decided interpretation:** the Editor Picks screen header becomes **title + subtitle +
a single row of tag pills**, styled exactly like the What-to-Watch quick-nav pills
(`subBtn`), with **no search input and no "Filters" button**. The tag pills themselves are
the filter control.

**Changes to `editorSection()`**
- Keep the existing H1 (`Editor Picks`) + subtitle block (already matches What to Watch).
- Replace the current labelled chip rows (`Type:` / `Genre:` / `Sort:` via `chipRow`) with a
  **single unlabelled pill row** ("tags") in the What-to-Watch pill style. Tags = `All`
  plus the type/genre facets present in the picks (e.g. `All`, `Movies`, `Series`, then the
  distinct genres). Selecting a tag filters the grid, mirroring the existing
  `editor-filter` action wiring.
- **Remove** the separate Sort chip row and the reset-filters affordance's dependence on it
  (default order = editor's order / rank). A single "Reset" pill/link may remain when a tag
  is active.
- No search input is added (Editor Picks never had one; this is the "no search bar" part).

**Open for spec review:** exact tag set (type-only vs type+genre). Default assumption:
`All` + present types + present genres in one row.

---

## 4. Scoped CSS — `.dashboard-right { padding: 0 !important }` only on the shortcode page

**Requirement:** apply `.dashboard-right { padding: 0 !important; }` **only** on pages that
render `[afristream_portal]`, never elsewhere.

**Approach:** the portal stylesheet is *registered* globally but only *enqueued* inside
`afristream_portal_shortcode()`. Attach the rule as **inline CSS on that handle** via
`wp_add_inline_style( 'afristream-portal', '.dashboard-right{padding:0 !important;}' )`,
called from within the shortcode render (right after `wp_enqueue_style`). Because the handle
only prints on shortcode pages, the rule is naturally page-scoped — no body class or
`is_page()` guard needed. `.dashboard-right` is a host/theme element (outside the portal
root), which is exactly why the scoping matters.

---

## 5. Editor Picks from the IMDb watchlist — Playwright sync at deploy time

**Constraint (verified):** IMDb's watchlist page is behind AWS WAF. A plain server fetch
returns **HTTP 202 with an empty body** (challenge interstitial); RSS is gone (404); export
needs login. The WordPress server therefore **cannot** pull the watchlist itself. A **real
browser** (Playwright) renders it fully — confirmed: the shared list
`p.oaowjxrmiacczaqrabkib5cpdi` resolves to *"lukemcfarland-28052's Watchlist"*, **125
ordered titles** extracted cleanly from the page's `__NEXT_DATA__` (id, title, year, type).

Because the plugin is **built and manually zipped/deployed**, the browser step runs at
**build/deploy time**, not live on the server. TMDB artwork resolution stays at runtime,
exactly as today.

**Design**
1. **New script `scripts/sync-watchlist.mjs`** (Playwright, uses the already-installed
   `@playwright/test` browser):
   - Reads the watchlist URL from config: env `IMDB_WATCHLIST_URL` (fallback to a constant
     default = the user's current shared URL).
   - Launches headless Chromium, navigates to the URL, waits for render, parses
     `__NEXT_DATA__`, and extracts the **ordered `tt` IDs** (dedup, order preserved).
   - Writes them to a **bundled data file `data/editor-picks-ids.txt`** (one `tt` id per
     line) that ships inside the plugin zip. Logs count + first/last titles.
   - Fails soft: if extraction yields zero IDs, it exits non-zero and leaves the existing
     file untouched (never blanks the list).
2. **Plugin reads the bundled file as the default ID source.** In
   `afristream_portal_editor_ids()` (PHP), when the `afristream_editor_picks_ids` option is
   empty, fall back to reading `data/editor-picks-ids.txt` from the plugin dir. The admin
   paste-box still overrides when set. Same fallback added to the preview server
   (`EDITOR_PICKS_IDS` env → then the file).
3. **Raise the pick cap** from 24 to **60** in both PHP (`afristream_portal_editor_ids`) and
   preview-server (`parseEditorIds`) so a real watchlist isn't truncated to 24. (125 items
   fully resolved every cache-miss = 125 TMDB `/find` calls per 12h refresh; 60 keeps that
   bounded while comfortably covering a curated editors' list. Cap value open for review.)
4. **Seed now:** run the script once so the branch ships with the current 125-item list
   already extracted (subject to the cap).
5. **Deploy hook:** document in `README`/`CHANGELOG` that `npm run sync-watchlist` is run
   before building the zip when the watchlist has changed. Optionally add it as a
   `prebuild`-style convenience script (not automatic in CI, per the manual-deploy model).

**Admin copy update:** the settings-field help text (currently "IMDb's watchlist page can't
be read automatically, so the list is maintained here") is updated to explain the new
`sync-watchlist` flow and that the paste-box now overrides the synced default.

---

## Cross-cutting

- **Versioning:** bump `0.6.0 → 0.7.0` in `afristream-portal.php` header, the
  `AFRISTREAM_PORTAL_VERSION` constant, and `package.json`. Add a `[0.7.0]` CHANGELOG entry.
- **Tests (CI guardrail):** add/extend Playwright coverage for the new user-visible
  behaviour — collections render real poster items on click, the four sports appear, and the
  Editor Picks header shows tags without a search bar. The watchlist sync script is exercised
  by a small unit-style test of its `__NEXT_DATA__` parser against a saved fixture (no live
  network in CI).
- **Lint:** `npm run lint` (node --check on the JS/mjs) run once at the end; findings
  presented, not auto-looped.
- **Deps:** no new runtime dependencies. `@playwright/test` is already a devDependency, so
  the sync script needs no `approved-deps.json` change.
- **Files touched:** `assets/portal.js`, `afristream-portal.php`,
  `includes/` (settings help text if separate), `scripts/preview-server.mjs`,
  new `scripts/sync-watchlist.mjs`, new `data/editor-picks-ids.txt`,
  `scripts/build-plugin.mjs` (ensure `data/` is included in the zip), `package.json`,
  `CHANGELOG.md`, `README.md`, tests.

## Out of scope

- No live server-side watchlist fetching (WAF-blocked by design).
- No new page builder / theme changes beyond the single scoped CSS rule.
- No redesign of the poster/detail components beyond the collection drawer body.
