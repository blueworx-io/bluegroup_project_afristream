# What to Watch overhaul + Editor Picks — Design

**Date:** 2026-07-08
**Branch:** `watch-overhaul-editor-picks`
**Plugin:** AfriStream Customer Portal (`afristream-portal.php` + `assets/portal.js`)

## Goal

Three user-requested improvements to the AfriStream portal:

1. **Drag-to-scroll** — every horizontal scrollable row supports click-and-drag panning with the mouse.
2. **Filter overhaul** — replace the current Type / Genre / Year / Sort drawer with a deeper, cascading set: **Type → Genre → Country → Decade**, plus **Sorts**. Backed by a much larger TMDB catalog so the filters return meaningful results.
3. **Editor Picks** — a new top-level page, curated from an IMDb watchlist, given a premium editorial feel.

## Context / current state

- The portal is a single vanilla-JS SPA ([assets/portal.js](../../../assets/portal.js)) rendered by the `[afristream_portal]` shortcode. State lives per-mount; every state change re-renders the whole portal via `root.innerHTML`.
- Each catalog item is `{ t, genre, platform, meta, poster, type }`. There is **no country, category, or decade field** today.
- Horizontal rows are `overflow-x:auto` with `scroll-snap` — no drag-scroll.
- Filters live in a right-hand drawer (`filtersDrawer`), with facet options derived live from a flat search `INDEX` via `facetPool(exclude)` — facets are already **dependent** (options recompute against the other active filters).
- Data comes from `afristream/v1/watch` (WP REST, PHP in `afristream-portal.php`) mirrored by `scripts/preview-server.mjs`. TMDB powers movies/series/newWeek; ESPN powers sport. Both keep built-in curated fallbacks. **The PHP and the preview `.mjs` must stay in sync** — every backend change is made in both.
- Tests: Playwright specs in [tests/portal.spec.js](../../../tests/portal.spec.js), run against the local preview harness (`WATCH_OFFLINE=1` + `?fixture=1` for hermetic runs).

## Decisions (from brainstorming)

- **Editor Picks source:** keep the IMDb watchlist link as the source of truth; scrape it server-side as robustly as possible (accepted trade-off: fragile vs. IMDb markup changes, mitigated by caching + fallback).
- **Category:** folds into **Genre** — a single genre-based facet, not two. The user's cascade "Type → Category/Genre → Country → Decade" collapses Category and Genre into one tier.
- **Country:** genuine origin country, backed by a **deep catalog** (many TMDB pages) so multi-facet filtering isn't mostly empty.
- **Editor Picks layout:** premium / high-end editorial feel (not a plain grid).

---

## 1. Drag-to-scroll

**Approach:** one delegated pointer handler on the mount `root`, keyed off a `data-dragscroll` attribute added to each horizontal row container. On `pointerdown` inside a `[data-dragscroll]` element, record start X + `scrollLeft`; on `pointermove` (while pressed) set `scrollLeft = start - dx` and switch the cursor to `grabbing`; release on `pointerup`/`pointercancel`/`pointerleave`.

- A small **drag threshold** (~5px) distinguishes a drag from a click, so clicking a poster still registers as a click.
- Once a drag exceeds threshold, suppress the ensuing `click` (guard flag) so drags don't trigger poster/card actions.
- Coexists with native wheel + touch scrolling (touch already works; this only adds mouse drag). Uses Pointer Events so it's unified.
- `user-select:none` applied during an active drag to stop text selection.
- Because the portal re-renders via `innerHTML`, the handler is attached **once** to `root` (delegation) and survives re-renders. Row containers just need the `data-dragscroll` marker + `cursor:grab`.

**Rows affected:** trending movies/series/new-week/documentaries/kids rows, sport, live TV, collections, and the Editor Picks rows.

## 2. Filter overhaul

### Data model

Every catalog item gains three derived fields, computed once when the index is built:

- `country` — origin country **name** (e.g. "South Africa"), mapped from an ISO code.
- `decade` — `"2020s"`, `"2010s"`, … derived from `year` (`Math.floor(year/10)*10 + "s"`). Items with no year → excluded from the Decade facet.
- (`genre` already exists; **Category is merged into Genre** — no separate field.)

Curated/non-catalog items (sport, live TV, collections) get sensible defaults (`country: ''`, no decade) and are naturally excluded from country/decade facets.

### Drawer facets (cascade order)

1. **Type** — Movies, Series, Documentaries, Kids, Sport, Live TV, Collections (existing `type`).
2. **Genre** — specific TMDB genre (Action, Drama, Comedy, Crime…).
3. **Country** — origin country name.
4. **Decade** — replaces Year.
5. **Sorts** — Recommended, A–Z, Newest, Top Rated (Top Rated sorts by the numeric rating parsed from `platform` `★ x.x`; items without a rating sort last).

- Facets remain **dependent**: `facetPool(exclude)` extends to filter on `country` and `decade` too; each group's options are computed from the pool excluding itself, so selecting Type narrows Genre, which narrows Country, etc. Groups with ≤1 real option (beyond the "All" entry) and no active selection hide themselves (existing behaviour, generalised).
- `FILTER_DEFAULTS`, the "Filters · N" count, Clear-all, and the `searching` predicate all extend to the new keys. `year`/`Year` state is renamed to `decade`/`Decade` throughout (state, handlers, tests).

### Sort

`Recommended` = index order (curated/popularity). `A–Z` = title. `Newest` = by year desc. `Top Rated` = by parsed rating desc.

## 3. Deep catalog backend

Both `afristream-portal.php` and `scripts/preview-server.mjs` gain a **catalog** builder (kept in sync), in addition to the existing trending rows that still drive the default homepage view.

- Build a deeper catalog from TMDB **`/discover/movie`** and **`/discover/tv`**, queried **per origin country** across a curated set of ~14: `US, GB, ZA, NG, KE, IN, FR, ES, KR, JP, BR, DE, AU, EG`. 1–2 pages each, `sort_by=popularity.desc`. Using `with_origin_country=<CC>` guarantees each returned item's origin country is known (movies' discover results don't include origin country otherwise).
- Map each item to the existing shape **plus** `country` (name), `genre` (first genre id → name), and the raw `year` (front-end derives decade). Dedupe by TMDB id / title.
- Returned under a new `catalog` key on the `/watch` payload (alongside the existing `movies` / `series` / `newWeek` / `sport`). The front-end merges `catalog` into the search `INDEX`; the homepage rows keep using `movies`/`series`/`newWeek`.
- **Caching:** 12h transient (PHP) / in-memory (preview), same pattern as today. Only successful builds cache. Cold-cache cost is higher (more requests) but bounded and infrequent. On failure, the front-end keeps its built-in curated lists (existing fallback path).
- Genre id→name maps already fetched (`afristream_portal_tmdb_genres`) are reused to name genres.

## 4. Editor Picks (premium)

### Backend

New route `afristream/v1/editor-picks` (PHP) + `/api/editor-picks` (preview), plus `?fixture=1` support for tests.

1. Fetch the configured IMDb watchlist URL server-side (`wp_remote_get` / `fetch`), parse the embedded **`__NEXT_DATA__`** JSON as the primary source (with the page's JSON-LD `ItemList` as a secondary fallback selector) to extract the ordered `tt…` IMDb IDs + titles.
2. Resolve each ID via TMDB **`/find/{imdb_id}?external_source=imdb_id`** to get poster, genre, year, rating, media type — consistent with the rest of the portal.
3. Cache **12–24h** with a **last-good** cache: if IMDb is unreachable/blocked or markup changed, serve the previous good result (or the built-in fallback list) so the page never blanks.
4. The watchlist URL is a new field on the existing wp-admin settings page (defaulting to the provided link); TMDB attribution shown whenever live data renders.

### Front-end (premium editorial feel)

New fifth nav tab **"Editor Picks"**, placed between *What to Watch* and *Tips & Tricks*. Design intent (built with the frontend-design skill for a distinctive, non-templated look):

- An **editorial hero**: the #1 pick shown large (big artwork, title, genre/year, a short editor's note / byline like "Curated by the AfriStream editors"), against the portal's navy gradient for a luxe feel.
- A **ranked, premium rail**: remaining picks as larger-than-usual posters in a drag-scroll row, with subtle rank numerals, refined typography, and a gentle hover lift/scale. Rounded corners, soft shadows, restrained gold/light accent to signal "curated / premium" vs. the standard blue.
- Graceful empty/fallback state and TMDB attribution footer.
- Respects `prefers-reduced-motion` for the hover/scale.

## Testing

Playwright specs (extend [tests/portal.spec.js](../../../tests/portal.spec.js)):

- **Drag-scroll:** simulate pointer drag on a row; assert `scrollLeft` increased and that a drag past threshold does not trigger a poster click.
- **Filters:** open drawer, assert the five groups (Type/Genre/Country/Decade/Sorts) appear when the fixture provides catalog data; filter by Decade and by Country; assert results narrow; assert dependent facets update.
- **Editor Picks:** new nav tab renders; from a `?fixture=1` editor-picks payload, the hero + ranked rail show the fixture titles; attribution visible; fallback state when payload is empty.
- Existing specs updated for renamed Year→Decade and the extra nav tab.
- Fixture payloads added to the preview server for both `/api/watch` (with `catalog`) and `/api/editor-picks`.

## Guardrails / housekeeping

- **Version bump** (minor — new feature) in `afristream-portal.php` header + `AFRISTREAM_PORTAL_VERSION` + `package.json`, kept in sync.
- **Changelog** updated alongside the bump.
- PHP and preview `.mjs` kept in lockstep (documented invariant).
- Lint run once as a final check; findings presented, not auto-actioned.
- WordPress plugin deployment zip produced at session end.

## Out of scope / non-goals

- No new npm dependencies (drag-scroll and scraping are hand-rolled; no scraping library) — keeps `approved-deps.json` untouched.
- No change to Profile / Tips / Troubleshooting sections beyond adding the nav tab.
- No IMDb write-back or auth; watchlist is read-only and public.
- Not building a general CMS for Editor Picks — the IMDb watchlist remains the single source of truth.

## Open risks

- **IMDb scraping fragility:** their markup can change or block server requests. Mitigated by last-good caching + fallback list, and by isolating the parse to one function that's easy to re-point. Accepted by the user.
- **TMDB API budget on cold cache:** per-country discover multiplies requests. Bounded by page limits + 12h cache; degrades to fallback on failure.
- **`find/{imdb_id}` misses:** some IMDb titles may not resolve on TMDB; those fall back to IMDb-provided title/year with a placeholder poster (initial-letter art, as the portal already does for posterless items).
