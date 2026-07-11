# Editor Picks — filters, tags & show-all — Design

**Date:** 2026-07-09
**Branch:** `watch-overhaul-editor-picks`
**Plugin:** AfriStream Customer Portal (`assets/portal.js`)

## Goal

Make the Editor Picks page filterable and show every pick. Three user-requested additions:

1. **Type tags** — Movies / Series / … as filter chips.
2. **Genre tags** — filter chips per genre present in the picks.
3. **Rating sort** — a "Top Rated" sort (plus A–Z and Newest).

And: **show all picks**, not just the ones that fit in the current horizontal rail.

## Context / current state

- Editor Picks lives in `editorSection()` in [assets/portal.js](../../../assets/portal.js). Today it renders a premium **#1 hero** (`picks[0]`) plus a single horizontal **drag-scroll rail** ("More from the list") of the remaining picks — no filters, and the rail hides picks off-screen.
- Each pick already carries every field we need: `t`, `type` (`Movies` / `Series`), `genre`, `platform` (`★ x.x` rating badge), `meta` (year, e.g. `2024` or `TV · 2023`), `country`, `rank`, plus `initial` / `bg` added by `prepPicks`.
- Picks are **not** run through `buildIndex`, so they have no numeric `year` / `decade` — Newest sort parses the year out of `meta`.
- Data source: `/editor-picks` (PHP `afristream_portal_editor_picks` / preview `editorPicksPayload`). The preview `EDITOR_FIXTURE` (`?fixture=1`) has 3 mixed picks (Movies/Series, Drama/Thriller/Comedy, ratings 8.5/8.1/7.6); the built-in `EDITOR_FALLBACK` has 5 mixed picks. **No backend change is needed** — this is front-end only.
- Watch's own filters live in `state.type/genre/country/decade/sort` + `FILTER_DEFAULTS` + `facetPool`. Editor Picks gets its **own** isolated state so the two tabs never cross-contaminate.

## Decisions (from brainstorming)

- **Layout:** *Hero + grid, hero hides when filtering.* Default (no filter/sort) shows the hero + a wrapping grid of all remaining picks; any active filter/sort collapses the hero and shows all matching picks in the grid.
- **Controls:** *Inline chip tags + sort chips* — no drawer.
- **Rating:** *Sort only* — "Top Rated" sorts by `★` score; no rating-threshold filter.
- **Genre chips:** *Only genres present in the picks* (type-independent, so the chip set is stable when Type changes).

## Design

### State (isolated from Watch)

Add to `state`:

- `editorType: 'All'`
- `editorGenre: 'All'`
- `editorSort: 'Editor\'s order'`

These are separate keys from Watch's `type`/`genre`/`sort` — switching tabs never leaks filter state.

`filtering` = any of the three differs from its default. When `filtering` is true the hero is hidden and the grid shows all matches; otherwise hero + full grid.

### Chip rows (above the grid)

Rendered in `editorSection()` from the current picks:

- **Type:** `['All', ...uniq(picks, 'type')]` — only types present (Movies / Series today).
- **Genre:** `['All', ...uniq(picks, 'genre').sort()]` — only genres present, type-independent.
- **Sort:** `['Editor\'s order', 'Top Rated', 'A–Z', 'Newest']`.

Chips reuse the existing `subBtn(active)` style. Each chip: `data-act="editor-filter" data-key="editorType|editorGenre|editorSort" data-val="…"`.

A **Reset** link (`data-act="clear-editor-filters"`) shows only while `filtering`.

### Filtering & sorting

```
let list = picks.filter(p =>
  (state.editorType === 'All'  || p.type  === state.editorType) &&
  (state.editorGenre === 'All' || p.genre === state.editorGenre));
```

Sort (stable copy):
- `Editor's order` → by `rank` ascending (default).
- `Top Rated` → by parsed `★` score desc; picks without a numeric rating (`IMDb` / `New`) sort last. Reuse the same `String(x.platform).match(/([\d.]+)/)` approach Watch uses.
- `A–Z` → `t.localeCompare`.
- `Newest` → by year parsed from `meta` (`String(meta).match(/\d{4}/)`), desc; no-year last.

### Rendering

- **Default view:** hero (`list[0]` = rank-1 pick) unchanged, then `<h2>More from the list</h2>` + a **wrapping grid** (`grid-template-columns:repeat(auto-fill,minmax(150px,1fr))`) of `list.slice(1)`. Reuses the existing editor-card markup (poster, rank badge, title, genre · meta). `data-dragscroll` removed — the grid wraps, no horizontal scroll.
- **Filtering view:** no hero; the grid renders **all** of `list` (rank badges retained).
- **Empty result:** small dashed "No picks match these filters" card with a reset button.
- **No picks at all:** existing "list is refreshing" empty state.
- TMDB attribution footer unchanged (`editorSource === 'imdb'`).

### Events

In the delegated `click` handler:

- `editor-filter` → `setState({ [data-key]: data-val })`.
- `clear-editor-filters` → `setState({ editorType: 'All', editorGenre: 'All', editorSort: 'Editor\'s order' })`.

No change to Watch's `filter` / `clear-filters` actions.

## Testing

Extend [tests/portal.spec.js](../../../tests/portal.spec.js), against the preview harness with the editor `?fixture=1` payload:

- Editor tab shows the Type / Genre / Sort chip rows.
- Default view shows the hero (Fixture Pick One) + the rest in the grid.
- Filtering to Type `Series` narrows the grid to the one series pick and **hides the hero**.
- `Top Rated` sort orders picks by `★` score.
- Reset restores the hero.

## Guardrails / housekeeping

- **Version bump** (minor — new feature): `0.4.0 → 0.5.0` in `afristream-portal.php` header + `AFRISTREAM_PORTAL_VERSION` + `package.json`, kept in sync.
- **Changelog** updated alongside the bump.
- Lint run once as a final check; findings presented, not auto-actioned.
- WordPress plugin deployment zip produced at session end.

## Out of scope / non-goals

- No backend change (PHP / preview server) — picks already carry type/genre/rating.
- No rating-threshold filter (sort only, per decision).
- No Country or Decade chips on Editor Picks.
- No new dependencies.
