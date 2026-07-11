# Card Detail Panel — Design

**Date:** 2026-07-09
**Status:** Approved
**Branch:** `watch-overhaul-editor-picks`

## Summary

Make every card in **What to Watch** and **Editor Picks** clickable. Clicking a
card (or pressing Enter/Space while it is focused) slides in a right-hand detail
panel showing that item's details. For film/series cards the panel lazy-fetches
a plot synopsis from TMDB on open. The panel is information-only — no action
buttons. The drawer reuses the existing Filters-drawer pattern (fixed scrim +
right-sliding panel) for visual and behavioural consistency.

## Decisions (from brainstorming)

- **Panel content:** current item data (poster, title, genre, rating, year/meta,
  country, type, platform) **plus** a TMDB synopsis.
- **Which cards:** *every* card — film/series posters, search results, sport
  fixtures, Live TV channels, Collections, the Editor hero, and the Editor
  ranked rail.
- **Synopsis delivery:** lazy-fetch on click. Items carry a TMDB `id`; a new
  lightweight endpoint returns the overview on demand, with a brief "Loading…"
  state and per-item client caching. Keeps the payload lean.
- **Action button:** none. The portal is a reference/credentials hub with no
  deep links to players, so an info-only panel is the honest choice.

## Architecture

The portal stays a single vanilla-JS file that re-renders the whole mount on
every state change. The PHP plugin and the local preview server mirror each
other and **must stay in sync** (existing invariant, noted throughout the code).

### 1. Data layer — carry a TMDB id

The mapping helpers currently drop TMDB's `id`. Add it so a synopsis can be
fetched later.

- **`afristream-portal.php`:** add `'id' => isset($row['id']) ? (int) $row['id'] : 0`
  in `afristream_portal_tmdb_map` and `afristream_portal_tmdb_discover`, and
  `'id' => isset($hit['id']) ? (int) $hit['id'] : 0` in
  `afristream_portal_resolve_pick`.
- **`scripts/preview-server.mjs`:** add `id: row.id` (and `hit.id` in
  `resolvePick`) in `mapItems`, `discoverCountry`, `resolvePick`.
- **Front-end:** `prep`/`prepPicks` already spread `...x`, so `id` passes
  through with no change. The existing `type` field (`Movies`/`Series`) already
  distinguishes movie vs tv — no extra field required.

### 2. Synopsis endpoint

- **`afristream-portal.php`:** register `afristream/v1/detail` (GET,
  `__return_true` permission). Params: `id` (sanitized to int), `type` (allow-
  list `movie`|`tv`, derived by the front-end from the item's `type`). Calls
  `afristream_portal_tmdb_get( '/' . $kind . '/' . $id )` and returns
  `array( 'overview' => $overview )` (empty string when none). Cached per
  id+type in a transient for 24h. When no key is configured, returns
  `{ overview: '' }`.
- **`scripts/preview-server.mjs`:** add `/api/detail` mirroring the above via
  `tmdbGet`. `?fixture=1` returns a **canned deterministic** overview
  (`Fixture synopsis for <id>.`) so the Playwright suite passes with
  `WATCH_OFFLINE=1`. Offline non-fixture returns `{ overview: '' }`.
- **Shortcode:** add a `data-detail-endpoint` attribute pointing at
  `rest_url( 'afristream/v1/detail' )`, alongside the existing endpoints.
- **`preview/fixture.html`:** set `data-detail-endpoint="/api/detail?fixture=1"`.
  `preview/index.html` uses `/api/detail`.

### 3. Front-end — clickable cards

- Add a render-scoped `cardRegistry` array, reset at the top of each `render()`.
  A helper `reg(detailObj)` pushes the object and returns its index.
- Clickable cards get these attributes on their outer element:
  `data-act="detail" data-card="<idx>" role="button" tabindex="0"` plus
  `cursor:pointer`. A subtle hover affordance may be added via existing CSS
  hover classes / inline transitions.
- Cards wired up:
  - Poster grid items (`posterGridItem`) and row items (`posterRowItem`) — used
    by trending movies/series, New This Week, Documentaries, Kids, and search
    results. These register the raw item (`detailKind: 'title'`).
  - Sport cards (`detailKind: 'sport'`).
  - Live TV channel cards (`detailKind: 'channel'`).
  - Collection cards (`detailKind: 'collection'`).
  - Editor hero and Editor ranked rail cards (`detailKind: 'title'`).
- The existing drag-scroll only swallows a click after a real ≥5px drag, so a
  plain click on a card in a horizontal row still opens the panel.

### 4. Detail drawer

- New state field `state.detail` holds the clicked detail object (or `null`).
- New `detailDrawer(obj)` renders a fixed scrim (`data-act="close-detail"`) and a
  right-sliding panel at a z-index above the Filters drawer. Structure:
  - Header: poster/gradient art with the item's colour, title, and a close ✕.
  - Meta chips depending on `detailKind`:
    - `title`: genre · year/meta · country · rating · type · platform.
    - `sport`: competition, fixture, time, channel, LIVE badge.
    - `channel`: name, tag, "Live channel".
    - `collection`: name, description, count.
  - Synopsis area (only for `title` items with an `id`): "Loading synopsis…" →
    the text → or "No synopsis available." Non-title kinds omit this area.
- Close via ✕, scrim click, and the **Escape** key (a document `keydown`
  listener active only while `state.detail` is set for this mount).
- Focus: on a fresh open, move focus to the close button; a `role="dialog"` /
  `aria-modal` / `aria-label` is set for accessibility.

### 5. Synopsis fetch + cache

- Module-level `Map` (`overviewCache`) keyed `"<type>:<id>"`.
- On opening a `title` card with an `id`:
  - If cached, render the text immediately.
  - Else render "Loading synopsis…", `fetch(detailEndpoint + '&id=' + id + '&type=' + kind)`,
    store the result, and re-render if the panel is still showing that item.
- Fetch failures resolve to "No synopsis available." (never throws to the UI).

## Testing (TDD)

Write tests first (red), then implement (green). New Playwright specs in
`tests/portal.spec.js`, run against the fixture page where live-data behaviour
is needed:

1. Clicking a poster card opens the detail panel showing its title, genre and
   rating.
2. The panel loads the canned fixture synopsis text.
3. The panel closes via the ✕ button, via scrim click, and via Escape.
4. Clicking an Editor Picks card (fixture, editor tab) opens the panel.
5. Clicking a sport card opens the panel showing its channel/competition.
6. `GET /api/detail?fixture=1&id=…&type=movie` responds with an `overview`.

Fixture data (`FIXTURE`, `EDITOR_FIXTURE`) gains `id` values on each item so the
front-end can request details.

## Versioning & deployment

- Minor feature → bump to **0.5.0**: the `Version:` header and
  `AFRISTREAM_PORTAL_VERSION` constant in `afristream-portal.php`, and
  `package.json`.
- Add a `## [0.5.0]` entry to `CHANGELOG.md`.
- Run the linter once as a final check; present findings, don't auto-loop.
- Build and zip the plugin to `<plugin-parent-dir>/afristream-portal.zip`,
  removing older versioned zips first.

## Out of scope

- Deep links / "play on platform" actions.
- Preloading synopses for every catalog item.
- Any change to the Tips or Troubleshooting sections.
