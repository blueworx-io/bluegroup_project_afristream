# What to Watch Overhaul + Editor Picks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add mouse drag-to-scroll to every horizontal row, replace the What to Watch filters with a cascading Type → Genre → Country → Decade set backed by a deep TMDB catalog, and add a premium "Editor Picks" page curated from an IMDb watchlist.

**Architecture:** The portal is one vanilla-JS SPA (`assets/portal.js`) fed by a WP REST endpoint (`afristream-portal.php`) that is mirrored 1:1 by a local preview server (`scripts/preview-server.mjs`). Every backend change is made in BOTH the PHP and the `.mjs`, kept byte-for-byte equivalent in behaviour. The front-end degrades to built-in curated lists whenever live data is missing. Tests run against the preview server (hermetic, `WATCH_OFFLINE=1`, with `?fixture=1` deterministic payloads).

**Tech Stack:** PHP (WordPress plugin), vanilla ES (browser + Node preview server), Playwright.

## Global Constraints

- **No new npm/PHP dependencies** — drag-scroll and IMDb parsing are hand-rolled; `approved-deps.json` stays untouched.
- **PHP ↔ preview parity** — any change to `afristream-portal.php` data logic is mirrored in `scripts/preview-server.mjs`, and vice versa.
- **Version bump (minor):** `0.3.0` → `0.4.0` in `afristream-portal.php` (plugin header `Version:` + `AFRISTREAM_PORTAL_VERSION`) and `package.json`, kept in sync. Changelog updated to match.
- **New functionality needs a Playwright test** (CI guardrail).
- **Lint once at the end** (`npm run lint`) — present findings, do not auto-fix in a loop.
- **Category is merged into Genre** — a single genre facet, never a separate Category facet.
- **Filter cascade order:** Type → Genre → Country → Decade, with Sorts always available.
- **TMDB attribution** shown wherever live TMDB data renders (existing pattern reused).

---

## File Structure

- `assets/portal.js` — front-end: drag-scroll handler, filter facet rework, decade derivation, Editor Picks tab + premium UI, catalog + editor-picks consumption. (Modified.)
- `assets/portal.css` — `cursor:grab` on drag rows, premium Editor Picks helpers, reduced-motion guard. (Modified.)
- `afristream-portal.php` — deep catalog builder, `editor-picks` REST route + IMDb scrape + TMDB resolve + last-good cache, IMDb-URL setting, editor endpoint on the shortcode, version bump. (Modified.)
- `scripts/preview-server.mjs` — mirror of catalog builder + editor-picks endpoint, fixtures. (Modified.)
- `preview/index.html`, `preview/fixture.html` — add `data-editor-endpoint`. (Modified.)
- `tests/portal.spec.js` — drag-scroll, new facets, decade/country filter, Editor Picks tab. (Modified.)
- `package.json`, `CHANGELOG.md` — version + changelog. (Modified.)

---

## Task 1: Drag-to-scroll on horizontal rows

**Files:**
- Modify: `assets/portal.js` (row containers get `data-dragscroll`; one delegated pointer handler added once per mount)
- Modify: `assets/portal.css` (grab cursor)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Produces: every horizontal-scroll row carries the attribute `data-dragscroll`; a delegated pointer handler on `root` pans it and swallows the click that ends a real drag.

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```js
test('poster rows drag-scroll with the mouse', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  const row = page.locator('[data-dragscroll]').first();
  await expect(row).toBeVisible();
  const box = await row.boundingBox();
  const startLeft = await row.evaluate((el) => el.scrollLeft);

  await page.mouse.move(box.x + box.width - 20, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 20, box.y + box.height / 2, { steps: 12 });
  await page.mouse.up();

  const endLeft = await row.evaluate((el) => el.scrollLeft);
  expect(endLeft).toBeGreaterThan(startLeft);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx playwright test -g "drag-scroll with the mouse"`
Expected: FAIL — no element matches `[data-dragscroll]` yet.

- [ ] **Step 3: Add `data-dragscroll` to every horizontal row container**

In `assets/portal.js`, add the attribute to each row's `<div>` that has `overflow-x:auto`. There are these row wrappers inside `watchSection()`:

- the poster rows loop: `<div style="display:flex;gap:14px;overflow-x:auto;...` → add `data-dragscroll` to that div.
- the sport row: `<div style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px">` → add `data-dragscroll`.
- the Live TV row: `<div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:12px">` → add `data-dragscroll`.
- the Collections row: `<div style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px">` → add `data-dragscroll`.

Example — the poster rows loop becomes:

```js
    ${posterRows.map((row) => `
      <div style="margin-bottom:28px">
        <h2 style="margin:0 0 12px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">${esc(row.h)}</h2>
        <div data-dragscroll style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px;scroll-snap-type:x proximity">
          ${row.items.map(posterRowItem).join('')}
        </div>
      </div>`).join('')}
```

Apply the same `data-dragscroll` addition to the sport, Live TV, and Collections row `<div>`s.

- [ ] **Step 4: Add the delegated drag handler once per mount**

In `assets/portal.js`, inside `createPortal(root)`, immediately AFTER the existing `root.addEventListener('input', …)` block and BEFORE the first `render();` call, add:

```js
    // Mouse drag-to-scroll for any [data-dragscroll] row. Pointer-based so it
    // unifies with wheel/touch scroll; a 5px threshold preserves poster clicks,
    // and once a real drag starts we swallow the trailing click.
    let drag = null;
    let draggedClick = false;
    root.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      const row = e.target.closest('[data-dragscroll]');
      if (!row || !root.contains(row)) return;
      drag = { row, startX: e.clientX, startScroll: row.scrollLeft };
    });
    root.addEventListener('pointermove', (e) => {
      if (!drag) return;
      const dx = e.clientX - drag.startX;
      if (!draggedClick && Math.abs(dx) < 5) return;
      draggedClick = true;
      drag.row.style.cursor = 'grabbing';
      drag.row.style.userSelect = 'none';
      drag.row.scrollLeft = drag.startScroll - dx;
      e.preventDefault();
    });
    const endDrag = () => {
      if (!drag) return;
      drag.row.style.cursor = '';
      drag.row.style.userSelect = '';
      drag = null;
    };
    root.addEventListener('pointerup', endDrag);
    root.addEventListener('pointercancel', endDrag);
    root.addEventListener('pointerleave', endDrag);
    // Capture phase so this runs before the bubbling click handler below.
    root.addEventListener('click', (e) => {
      if (draggedClick) { draggedClick = false; e.stopPropagation(); e.preventDefault(); }
    }, true);
```

- [ ] **Step 5: Add the grab cursor**

In `assets/portal.css`, append:

```css
.afristream-portal [data-dragscroll] {
  cursor: grab;
}
```

- [ ] **Step 6: Run the test and confirm it passes**

Run: `npx playwright test -g "drag-scroll with the mouse"`
Expected: PASS.

- [ ] **Step 7: Confirm no regression on poster clicks**

Run: `npx playwright test tests/portal.spec.js`
Expected: all existing tests still PASS (the capture click-swallow only fires after a real drag).

- [ ] **Step 8: Commit**

```bash
git add assets/portal.js assets/portal.css tests/portal.spec.js
git commit -m "Add mouse drag-to-scroll to horizontal rows"
```

---

## Task 2: Deep TMDB catalog (backend + fixtures)

**Files:**
- Modify: `scripts/preview-server.mjs` (catalog builder, `FIXTURE.catalog`, payload includes `catalog`)
- Modify: `afristream-portal.php` (mirror catalog builder; payload includes `catalog`)
- Test: `tests/portal.spec.js` (API returns catalog)

**Interfaces:**
- Produces: the `/watch` payload (WP `afristream/v1/watch` and preview `/api/watch`) gains a `catalog` array. Each catalog item: `{ t, genre, platform, meta, poster, type, country }` where `country` is a full country name (e.g. `"South Africa"`) and `type` is `"Movies"` or `"Series"`.

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```js
test('watch fixture payload carries a deep catalog with country data', async ({ request }) => {
  const res = await request.get('/api/watch?fixture=1');
  const json = await res.json();
  expect(Array.isArray(json.catalog)).toBeTruthy();
  expect(json.catalog.length).toBeGreaterThan(3);
  const withCountry = json.catalog.find((x) => x.country);
  expect(withCountry).toBeTruthy();
  expect(withCountry).toHaveProperty('type');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx playwright test -g "deep catalog with country data"`
Expected: FAIL — `json.catalog` is undefined.

- [ ] **Step 3: Add the catalog to the preview FIXTURE**

In `scripts/preview-server.mjs`, add a `catalog` array to the `FIXTURE` object (spanning several countries and decades so filter tests have variety):

```js
  catalog: [
    { t: 'Jozi Heat', genre: 'Crime', platform: '★ 7.8', meta: '2023', poster: null, type: 'Movies', country: 'South Africa' },
    { t: 'Lagos Lights', genre: 'Drama', platform: '★ 8.0', meta: '2019', poster: null, type: 'Movies', country: 'Nigeria' },
    { t: 'Seoul Signal', genre: 'Thriller', platform: '★ 8.4', meta: 'TV · 2021', poster: null, type: 'Series', country: 'South Korea' },
    { t: 'London Fog', genre: 'Mystery', platform: '★ 7.2', meta: '2008', poster: null, type: 'Movies', country: 'United Kingdom' },
    { t: 'Nairobi Nights', genre: 'Drama', platform: '★ 7.5', meta: 'TV · 1998', poster: null, type: 'Series', country: 'Kenya' },
  ],
```

- [ ] **Step 4: Add the catalog builder to the preview server**

In `scripts/preview-server.mjs`, add the country map and a `discover` helper above `tmdbCatalog`:

```js
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
      meta: type === 'Series' ? `TV · ${year}` : year,
      poster: row.poster_path ? `https://image.tmdb.org/t/p/w342${row.poster_path}` : null,
      type,
      country: COUNTRIES[cc],
    };
  }).filter((x) => x.t);
}
```

Then, inside `tmdbCatalog()`, AFTER `newWeek` is built and BEFORE the `return { … }`, build the catalog and include it:

```js
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
```

`watchPayload()` already spreads `...(catalog || {})`, so `catalog` flows into the payload automatically.

- [ ] **Step 5: Mirror the catalog builder in PHP**

In `afristream-portal.php`, add the country map + discover helper above `afristream_portal_tmdb_catalog()`:

```php
/**
 * Origin countries for the deep, filterable catalog. ISO 3166-1 → display name.
 */
function afristream_portal_countries() {
	return array(
		'US' => 'United States', 'GB' => 'United Kingdom', 'ZA' => 'South Africa',
		'NG' => 'Nigeria', 'KE' => 'Kenya', 'IN' => 'India', 'FR' => 'France',
		'ES' => 'Spain', 'KR' => 'South Korea', 'JP' => 'Japan', 'BR' => 'Brazil',
		'DE' => 'Germany', 'AU' => 'Australia', 'EG' => 'Egypt',
	);
}

/**
 * One page of TMDB discover results for a given media kind and origin country,
 * mapped onto the portal item shape with a country name attached.
 */
function afristream_portal_tmdb_discover( $kind, $cc, $country_name, $genres ) {
	$json = afristream_portal_tmdb_get(
		'/discover/' . $kind,
		array(
			'sort_by'            => 'popularity.desc',
			'with_origin_country' => $cc,
			'vote_count.gte'     => 20,
			'page'               => 1,
		)
	);
	$type    = ( 'movie' === $kind ) ? 'Movies' : 'Series';
	$items   = array();
	$results = ( $json && ! empty( $json['results'] ) ) ? $json['results'] : array();
	foreach ( $results as $row ) {
		$title = isset( $row['title'] ) ? $row['title'] : ( isset( $row['name'] ) ? $row['name'] : '' );
		if ( '' === $title ) {
			continue;
		}
		$date     = isset( $row['release_date'] ) ? $row['release_date'] : ( isset( $row['first_air_date'] ) ? $row['first_air_date'] : '' );
		$year     = substr( (string) $date, 0, 4 );
		$genre_id = ! empty( $row['genre_ids'] ) ? $row['genre_ids'][0] : 0;
		$rating   = isset( $row['vote_average'] ) ? (float) $row['vote_average'] : 0;
		$items[]  = array(
			't'        => $title,
			'genre'    => isset( $genres[ $genre_id ] ) ? $genres[ $genre_id ] : $type,
			'platform' => $rating > 0 ? '★ ' . number_format( $rating, 1 ) : 'New',
			'meta'     => ( 'Series' === $type ) ? trim( 'TV · ' . $year, ' ·' ) : $year,
			'poster'   => ! empty( $row['poster_path'] ) ? 'https://image.tmdb.org/t/p/w342' . $row['poster_path'] : null,
			'type'     => $type,
			'country'  => $country_name,
		);
	}
	return $items;
}
```

Then in `afristream_portal_tmdb_catalog()`, after `$on_air` is fetched and before building `$catalog`, build the deep catalog and add it:

```php
	$deep = array();
	$seen = array();
	foreach ( afristream_portal_countries() as $cc => $country_name ) {
		$rows = array_merge(
			afristream_portal_tmdb_discover( 'movie', $cc, $country_name, $movie_genres ),
			afristream_portal_tmdb_discover( 'tv', $cc, $country_name, $tv_genres )
		);
		foreach ( $rows as $item ) {
			if ( ! isset( $seen[ $item['t'] ] ) ) {
				$seen[ $item['t'] ] = true;
				$deep[]             = $item;
			}
		}
	}

	$catalog = array(
		'movies'  => afristream_portal_tmdb_map( $trending_movies, $movie_genres, 'Movies', 10 ),
		'series'  => afristream_portal_tmdb_map( $trending_tv, $tv_genres, 'Series', 10 ),
		'newWeek' => array_merge(
			afristream_portal_tmdb_map( $new_movies, $movie_genres, 'Movies', 4, 'New release' ),
			afristream_portal_tmdb_map( $on_air, $tv_genres, 'Series', 4, 'New episodes' )
		),
		'catalog' => $deep,
	);
```

(The existing `set_transient` + `return $catalog` lines are unchanged; `afristream_portal_watch_data()` already merges the whole `$catalog` array into the response, so `catalog` is included.)

- [ ] **Step 6: Run the test and confirm it passes**

Run: `npx playwright test -g "deep catalog with country data"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add scripts/preview-server.mjs afristream-portal.php tests/portal.spec.js
git commit -m "Add deep per-country TMDB catalog to the watch endpoint"
```

---

## Task 3: Filter overhaul (front-end)

**Files:**
- Modify: `assets/portal.js` (consume `catalog`, add `country`/`decade` fields, rework facets, rename year→decade, drop unused platform facet, Top Rated sort)
- Test: `tests/portal.spec.js` (five facet groups; decade + country filtering)

**Interfaces:**
- Consumes: the `catalog` array from Task 2 (`{ …, country }`).
- Produces: `state` has filter keys `type, genre, country, decade, sort`; `FILTER_DEFAULTS = { type:'All Types', genre:'All Genres', country:'All Countries', decade:'All Decades', sort:'Recommended' }`; every indexed item has `year`, `country`, `decade`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/portal.spec.js`:

```js
test('filter drawer exposes country and decade facets from the catalog', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Filters', exact: true }).click();
  const drawer = page.getByTestId('filters-drawer');
  await expect(drawer.getByText('Country', { exact: true })).toBeVisible();
  await expect(drawer.getByText('Decade', { exact: true })).toBeVisible();

  // Filter by a decade present only in the catalog fixture.
  await drawer.getByRole('button', { name: '1990s', exact: true }).click();
  await drawer.getByRole('button', { name: /^Show \d+ results?$/ }).click();
  await expect(page.getByText('Nairobi Nights')).toBeVisible();
  await expect(page.getByText('Jozi Heat')).not.toBeVisible();
});

test('filter drawer filters the catalog by country', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Filters', exact: true }).click();
  const drawer = page.getByTestId('filters-drawer');
  await drawer.getByRole('button', { name: 'Nigeria', exact: true }).click();
  await drawer.getByRole('button', { name: /^Show \d+ results?$/ }).click();
  await expect(page.getByText('Lagos Lights')).toBeVisible();
  await expect(page.getByText('Seoul Signal')).not.toBeVisible();
});
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `npx playwright test -g "country and decade facets"`
Expected: FAIL — no Country/Decade group; `1990s` button not found.

- [ ] **Step 3: Add decade helper and broaden the year matcher**

In `assets/portal.js`, replace the `yr` helper so it matches 1900s–2000s years, and add a `decadeOf` helper next to it:

```js
  const yr = (meta) => {
    const m = String(meta).match(/((?:19|20)\d\d)/);
    return m ? +m[1] : 0;
  };
  const decadeOf = (year) => (year ? `${Math.floor(year / 10) * 10}s` : '');
```

- [ ] **Step 4: Index the catalog with country + decade**

In `buildIndex(data)`, add the catalog items to the source list and attach `country`/`decade` to every indexed item. Update the `src` array to include `...(data.catalog || [])` (last, so trending versions win on dedupe):

```js
    const src = [
      ...data.movies.map((x) => ({ ...x, type: x.type || 'Movies' })),
      ...data.series.map((x) => ({ ...x, type: x.type || 'Series' })),
      ...data.newWeek.map((x) => ({ ...x, type: x.type || (/episode/i.test(x.meta) ? 'Series' : 'Movies') })),
      ...data.sport.map((s) => ({ t: s.fx, genre: 'Sport', platform: s.ch, meta: `${s.comp} · ${s.time}`, type: 'Sport', initial: s.fx[0], bg: bg('Sport') })),
      ...LIVE_TV.map((t) => ({ t: t.name, genre: t.tag, platform: 'Live TV', meta: 'Live channel', type: 'Live TV', initial: t.name[0], bg: t.bg })),
      ...COLLECTIONS.map((c) => ({ t: c.name, genre: 'Collection', platform: 'AfriStream', meta: c.count, type: 'Collection', initial: c.name[0], bg: c.bg })),
      ...(data.catalog || []).map((x) => ({ ...x, type: x.type || 'Movies' })),
    ];
    for (const x of src) {
      if (!seen.has(x.t)) {
        seen.add(x.t);
        const year = yr(x.meta);
        index.push({ ...x, year, country: x.country || '', decade: decadeOf(year) });
      }
    }
```

- [ ] **Step 5: Add `catalog` to the initial data object and default filter state**

In `createPortal`, update the `data` object and `FILTER_DEFAULTS`, and the `state` filter keys:

```js
    const data = { movies: MOVIES, series: SERIES, newWeek: NEW_WEEK, sport: SPORT, catalog: [] };
    let INDEX = buildIndex(data);
    let dataSource = 'built-in';
    const FILTER_DEFAULTS = { type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' };
```

In the `state` object, replace the `platform` / `year` lines with `country` / `decade` (remove `platform` entirely):

```js
      query: '',
      genre: 'All Genres',
      type: 'All Types',
      country: 'All Countries',
      decade: 'All Decades',
      sort: 'Recommended',
```

- [ ] **Step 6: Update `facetPool` to filter on country + decade (drop platform)**

Replace `facetPool` with:

```js
    function facetPool(exclude) {
      const q = state.query.trim().toLowerCase();
      return INDEX.filter((x) =>
        (!q || x.t.toLowerCase().includes(q)) &&
        (exclude === 'type' || state.type === 'All Types' || x.type === state.type) &&
        (exclude === 'genre' || state.genre === 'All Genres' || x.genre === state.genre) &&
        (exclude === 'country' || state.country === 'All Countries' || x.country === state.country) &&
        (exclude === 'decade' || state.decade === 'All Decades' || x.decade === state.decade)
      );
    }
```

- [ ] **Step 7: Rework the drawer groups**

In `filtersDrawer`, replace the `groups` array with the cascade set (Type → Genre → Country → Decade → Sort):

```js
      const groups = [
        { key: 'type', label: 'Type', options: ['All Types', ...uniq(facetPool('type'), 'type').sort()] },
        { key: 'genre', label: 'Genre', options: ['All Genres', ...uniq(facetPool('genre'), 'genre').sort()] },
        { key: 'country', label: 'Country', options: ['All Countries', ...uniq(facetPool('country').filter((x) => x.country), 'country').sort()] },
        { key: 'decade', label: 'Decade', options: ['All Decades', ...uniq(facetPool('decade').filter((x) => x.decade), 'decade').sort((a, b) => parseInt(b, 10) - parseInt(a, 10))] },
        { key: 'sort', label: 'Sort by', options: ['Recommended', 'A–Z', 'Newest', 'Top Rated'] },
      ];
```

(The existing `filterGroups` line — `g.key === 'sort' || g.options.length > 2 || state[g.key] !== FILTER_DEFAULTS[g.key]` — is unchanged and now correctly hides empty Country/Decade facets.)

- [ ] **Step 8: Update the searching predicate and sorts in `watchSection`**

Replace the `searching` line and the sort block:

```js
      const searching = !!q || state.type !== 'All Types' || state.genre !== 'All Genres' || state.country !== 'All Countries' || state.decade !== 'All Decades' || state.sort !== 'Recommended';
      let results = searching ? facetPool(null) : [];
      if (state.sort === 'A–Z') results = [...results].sort((a, b) => a.t.localeCompare(b.t));
      else if (state.sort === 'Newest') results = [...results].sort((a, b) => b.year - a.year);
      else if (state.sort === 'Top Rated') {
        const rate = (x) => { const m = String(x.platform).match(/([\d.]+)/); return m ? +m[1] : -1; };
        results = [...results].sort((a, b) => rate(b) - rate(a));
      }
```

- [ ] **Step 9: Update the clear-filters handler**

In the click handler `switch`, replace the `clear-filters` case:

```js
        case 'clear-filters': setState({ query: '', type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' }); break;
```

- [ ] **Step 10: Consume the catalog from the API response**

In the `fetch(props.endpoint)` `.then` block, add catalog handling alongside the existing arrays:

```js
          const movies = prep(payload.movies);
          const series = prep(payload.series);
          const newWeek = prep(payload.newWeek);
          const catalog = prep(payload.catalog);
          const sport = prepSport(payload.sport);
          if (!movies.length && !series.length && !sport.length && !catalog.length) return;
          if (movies.length) data.movies = movies;
          if (series.length) data.series = series;
          if (newWeek.length) data.newWeek = newWeek;
          if (catalog.length) data.catalog = catalog;
          if (sport.length) data.sport = sport;
          if (movies.length || series.length || catalog.length) dataSource = 'tmdb';
          INDEX = buildIndex(data);
          render(true);
```

(`prep` spreads `...x`, so each catalog item keeps its `country`. `country: ''` is added by `buildIndex` for anything missing it.)

- [ ] **Step 11: Run the new filter tests and confirm they pass**

Run: `npx playwright test -g "country and decade facets" -g "by country"`
Expected: PASS.

- [ ] **Step 12: Run the full suite (catch the renamed year→decade / removed platform)**

Run: `npx playwright test tests/portal.spec.js`
Expected: all PASS. If the existing "filter drawer filters by type" test fails, it is unrelated to renames (Type is unchanged) — investigate before proceeding.

- [ ] **Step 13: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "Overhaul What to Watch filters: Type/Genre/Country/Decade cascade"
```

---

## Task 4: Editor Picks endpoint (backend + fixtures + setting)

**Files:**
- Modify: `scripts/preview-server.mjs` (`/api/editor-picks`, IMDb parse, TMDB resolve, last-good cache, fixture)
- Modify: `afristream-portal.php` (`afristream/v1/editor-picks` route, IMDb parse, TMDB resolve, last-good transient/option, IMDb-URL setting, editor endpoint on shortcode)
- Modify: `preview/index.html`, `preview/fixture.html` (`data-editor-endpoint`)
- Test: `tests/portal.spec.js` (endpoint returns picks)

**Interfaces:**
- Produces: `GET /api/editor-picks` (preview) and `afristream/v1/editor-picks` (WP) return `{ source, picks: [...] }`. Each pick: `{ t, genre, platform, meta, poster, type, country, rank }`. `?fixture=1` returns a deterministic payload; offline / failure returns `{ source: 'fallback' }` (front-end then keeps its built-in list).

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```js
test('editor-picks fixture endpoint returns ranked picks', async ({ request }) => {
  const res = await request.get('/api/editor-picks?fixture=1');
  expect(res.ok()).toBeTruthy();
  const json = await res.json();
  expect(Array.isArray(json.picks)).toBeTruthy();
  expect(json.picks.length).toBeGreaterThan(1);
  expect(json.picks[0]).toHaveProperty('t');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `npx playwright test -g "editor-picks fixture endpoint"`
Expected: FAIL — 404 / not JSON (`/api/editor-picks` not handled).

- [ ] **Step 3: Add the editor-picks fixture + IMDb parse + TMDB resolve to the preview server**

In `scripts/preview-server.mjs`, add near the top (after `FIXTURE`):

```js
const IMDB_WATCHLIST_URL = process.env.IMDB_WATCHLIST_URL
  || 'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/';

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

// Walk the IMDb watchlist page's embedded JSON for ordered tt-ids + titles.
function parseImdbWatchlist(html) {
  const m = html.match(/<script id="__NEXT_DATA__" type="application\/json">([\s\S]*?)<\/script>/);
  if (!m) return [];
  let data;
  try { data = JSON.parse(m[1]); } catch { return []; }
  const out = [];
  const seen = new Set();
  const walk = (node) => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) { node.forEach(walk); return; }
    const id = node.titleId || node.constId || node.id;
    if (typeof id === 'string' && /^tt\d+$/.test(id) && !seen.has(id)) {
      seen.add(id);
      const title = node.titleText?.text || node.originalTitleText?.text || node.title || '';
      out.push({ id, title });
    }
    for (const k of Object.keys(node)) walk(node[k]);
  };
  walk(data);
  return out;
}

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
    meta: type === 'Series' ? `TV · ${year}` : year,
    poster: hit.poster_path ? `https://image.tmdb.org/t/p/w342${hit.poster_path}` : null,
    type,
    country: (hit.origin_country && hit.origin_country[0] && COUNTRIES[hit.origin_country[0]]) || '',
    rank,
  };
}

async function editorPicksPayload() {
  if (process.env.WATCH_OFFLINE === '1') return { source: 'fallback', reason: 'offline' };
  if (editorCache && Date.now() - editorCacheAt < 12 * 60 * 60 * 1000) return editorCache;
  try {
    const res = await fetch(IMDB_WATCHLIST_URL, {
      headers: { 'User-Agent': 'Mozilla/5.0 (AfriStream portal)' },
      signal: AbortSignal.timeout(10000),
    });
    if (!res.ok) throw new Error('imdb ' + res.status);
    const entries = parseImdbWatchlist(await res.text()).slice(0, 24);
    if (!entries.length) throw new Error('no entries');

    const genreList = async (type) =>
      Object.fromEntries(((await tmdbGet(`/genre/${type}/list`))?.genres ?? []).map((g) => [g.id, g.name]));
    const [movieGenres, tvGenres] = await Promise.all([genreList('movie'), genreList('tv')]);
    const resolved = await Promise.all(
      entries.map((e, i) => resolvePick(e.id, e.title, i + 1, movieGenres, tvGenres))
    );
    const picks = resolved.filter(Boolean);
    if (!picks.length) throw new Error('none resolved');

    editorCache = { source: 'imdb', updated: new Date().toISOString(), picks };
    editorCacheAt = Date.now();
    return editorCache;
  } catch {
    // Last-good cache survives an IMDb hiccup; otherwise the front-end falls back.
    return editorCache || { source: 'fallback', reason: 'unavailable' };
  }
}
```

- [ ] **Step 4: Route `/api/editor-picks` in the preview server**

In the `createServer` handler, add a branch alongside the `/api/watch` one:

```js
    if (path === '/api/editor-picks') {
      const payload = url.searchParams.get('fixture') === '1' ? EDITOR_FIXTURE : await editorPicksPayload();
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(payload));
      return;
    }
```

- [ ] **Step 5: Run the endpoint test and confirm it passes**

Run: `npx playwright test -g "editor-picks fixture endpoint"`
Expected: PASS.

- [ ] **Step 6: Mirror the editor-picks logic in PHP**

In `afristream-portal.php`, add the watchlist URL getter, parse, resolve, and route:

```php
/**
 * Editor Picks — curated from a public IMDb watchlist. The watchlist URL comes
 * from the afristream_imdb_watchlist_url option (default: the provided list).
 * We scrape the page's embedded JSON for IMDb ids, resolve each via TMDB for
 * consistent artwork, and keep a last-good copy so an IMDb hiccup never blanks
 * the page.
 */
function afristream_portal_imdb_watchlist_url() {
	return get_option(
		'afristream_imdb_watchlist_url',
		'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/'
	);
}

function afristream_portal_imdb_walk( $node, &$out, &$seen ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	foreach ( array( 'titleId', 'constId', 'id' ) as $key ) {
		if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && preg_match( '/^tt\d+$/', $node[ $key ] ) && ! isset( $seen[ $node[ $key ] ] ) ) {
			$seen[ $node[ $key ] ] = true;
			$title                 = '';
			if ( isset( $node['titleText']['text'] ) ) {
				$title = $node['titleText']['text'];
			} elseif ( isset( $node['originalTitleText']['text'] ) ) {
				$title = $node['originalTitleText']['text'];
			} elseif ( isset( $node['title'] ) && is_string( $node['title'] ) ) {
				$title = $node['title'];
			}
			$out[] = array( 'id' => $node[ $key ], 'title' => $title );
			break;
		}
	}
	foreach ( $node as $child ) {
		if ( is_array( $child ) ) {
			afristream_portal_imdb_walk( $child, $out, $seen );
		}
	}
}

function afristream_portal_imdb_entries( $html ) {
	if ( ! preg_match( '#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $m ) ) {
		return array();
	}
	$data = json_decode( $m[1], true );
	if ( ! is_array( $data ) ) {
		return array();
	}
	$out  = array();
	$seen = array();
	afristream_portal_imdb_walk( $data, $out, $seen );
	return $out;
}

function afristream_portal_resolve_pick( $imdb_id, $fallback_title, $rank, $movie_genres, $tv_genres ) {
	$json  = afristream_portal_tmdb_get( '/find/' . $imdb_id, array( 'external_source' => 'imdb_id' ) );
	$movie = ! empty( $json['movie_results'] ) ? $json['movie_results'][0] : null;
	$tv    = ! empty( $json['tv_results'] ) ? $json['tv_results'][0] : null;
	$hit   = $movie ? $movie : $tv;
	if ( ! $hit ) {
		if ( '' === $fallback_title ) {
			return null;
		}
		return array(
			't' => $fallback_title, 'genre' => 'Film', 'platform' => 'IMDb',
			'meta' => '', 'poster' => null, 'type' => 'Movies', 'country' => '', 'rank' => $rank,
		);
	}
	$type      = $movie ? 'Movies' : 'Series';
	$genres    = $movie ? $movie_genres : $tv_genres;
	$date      = isset( $hit['release_date'] ) ? $hit['release_date'] : ( isset( $hit['first_air_date'] ) ? $hit['first_air_date'] : '' );
	$year      = substr( (string) $date, 0, 4 );
	$genre_id  = ! empty( $hit['genre_ids'] ) ? $hit['genre_ids'][0] : 0;
	$rating    = isset( $hit['vote_average'] ) ? (float) $hit['vote_average'] : 0;
	$countries = afristream_portal_countries();
	$cc        = ! empty( $hit['origin_country'][0] ) ? $hit['origin_country'][0] : '';
	$title     = isset( $hit['title'] ) ? $hit['title'] : ( isset( $hit['name'] ) ? $hit['name'] : $fallback_title );
	return array(
		't'        => $title,
		'genre'    => isset( $genres[ $genre_id ] ) ? $genres[ $genre_id ] : $type,
		'platform' => $rating > 0 ? '★ ' . number_format( $rating, 1 ) : 'IMDb',
		'meta'     => ( 'Series' === $type ) ? trim( 'TV · ' . $year, ' ·' ) : $year,
		'poster'   => ! empty( $hit['poster_path'] ) ? 'https://image.tmdb.org/t/p/w342' . $hit['poster_path'] : null,
		'type'     => $type,
		'country'  => isset( $countries[ $cc ] ) ? $countries[ $cc ] : '',
		'rank'     => $rank,
	);
}

function afristream_portal_editor_picks() {
	$cached = get_transient( 'afristream_portal_editor' );
	if ( false !== $cached ) {
		return $cached;
	}
	if ( ! afristream_portal_tmdb_key() ) {
		return array( 'source' => 'fallback', 'reason' => 'no-key' );
	}

	$response = wp_remote_get(
		afristream_portal_imdb_watchlist_url(),
		array( 'timeout' => 10, 'user-agent' => 'Mozilla/5.0 (AfriStream portal)' )
	);
	$last_good = get_option( 'afristream_portal_editor_lastgood', null );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'unavailable' );
	}

	$entries = array_slice( afristream_portal_imdb_entries( wp_remote_retrieve_body( $response ) ), 0, 24 );
	if ( empty( $entries ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'no-entries' );
	}

	$movie_genres = afristream_portal_tmdb_genres( 'movie' );
	$tv_genres    = afristream_portal_tmdb_genres( 'tv' );
	$picks        = array();
	$rank         = 1;
	foreach ( $entries as $entry ) {
		$pick = afristream_portal_resolve_pick( $entry['id'], $entry['title'], $rank, $movie_genres, $tv_genres );
		if ( $pick ) {
			$picks[] = $pick;
			$rank++;
		}
	}
	if ( empty( $picks ) ) {
		return $last_good ? $last_good : array( 'source' => 'fallback', 'reason' => 'none-resolved' );
	}

	$payload = array( 'source' => 'imdb', 'updated' => gmdate( 'c' ), 'picks' => $picks );
	set_transient( 'afristream_portal_editor', $payload, 12 * HOUR_IN_SECONDS );
	update_option( 'afristream_portal_editor_lastgood', $payload, false );
	return $payload;
}

function afristream_portal_editor_data() {
	return rest_ensure_response( afristream_portal_editor_picks() );
}
```

Then register the route inside `afristream_portal_register_rest_routes()` (add a second `register_rest_route` call):

```php
	register_rest_route(
		'afristream/v1',
		'/editor-picks',
		array(
			'methods'             => 'GET',
			'callback'            => 'afristream_portal_editor_data',
			'permission_callback' => '__return_true',
		)
	);
```

- [ ] **Step 7: Add the IMDb-watchlist-URL setting**

In `afristream_portal_register_settings()`, register the option + field:

```php
	register_setting(
		'afristream_portal',
		'afristream_imdb_watchlist_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'afristream_portal_sanitize_imdb_url',
			'default'           => 'https://www.imdb.com/user/p.oaowjxrmiacczaqrabkib5cpdi/watchlist/',
		)
	);

	add_settings_field(
		'afristream_imdb_watchlist_url',
		__( 'Editor Picks IMDb watchlist', 'afristream-portal' ),
		'afristream_portal_imdb_url_field',
		'afristream-portal',
		'afristream_portal_data',
		array( 'label_for' => 'afristream_imdb_watchlist_url' )
	);
```

Add the sanitizer + field renderer (near the TMDB equivalents):

```php
function afristream_portal_sanitize_imdb_url( $value ) {
	delete_transient( 'afristream_portal_editor' );
	return esc_url_raw( trim( (string) $value ) );
}

function afristream_portal_imdb_url_field() {
	printf(
		'<input type="url" class="regular-text code" name="afristream_imdb_watchlist_url" id="afristream_imdb_watchlist_url" value="%s" autocomplete="off">',
		esc_attr( afristream_portal_imdb_watchlist_url() )
	);
	echo '<p class="description">' . esc_html__( 'Public IMDb watchlist URL powering the Editor Picks page. Titles are resolved through TMDB for artwork (needs a TMDB key). Saving refreshes the list immediately.', 'afristream-portal' ) . '</p>';
}
```

- [ ] **Step 8: Pass the editor endpoint through the shortcode**

In `afristream_portal_shortcode()`, add a `data-editor-endpoint` attribute to the mount markup:

```php
	return sprintf(
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s" data-endpoint="%s" data-editor-endpoint="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] ),
		esc_url( rest_url( 'afristream/v1/watch' ) ),
		esc_url( rest_url( 'afristream/v1/editor-picks' ) )
	);
```

- [ ] **Step 9: Point the preview pages at the editor endpoint**

In `preview/index.html`, add to the mount div:

```html
data-editor-endpoint="/api/editor-picks"
```

In `preview/fixture.html`, add:

```html
data-editor-endpoint="/api/editor-picks?fixture=1"
```

- [ ] **Step 10: Run the endpoint test again + full suite**

Run: `npx playwright test tests/portal.spec.js`
Expected: all PASS (the front-end doesn't read the editor endpoint yet, so this only re-confirms the API test).

- [ ] **Step 11: Commit**

```bash
git add scripts/preview-server.mjs afristream-portal.php preview/index.html preview/fixture.html tests/portal.spec.js
git commit -m "Add Editor Picks endpoint: IMDb watchlist resolved via TMDB"
```

---

## Task 5: Editor Picks page (premium front-end)

**Files:**
- Modify: `assets/portal.js` (nav tab, `editorSection`, built-in fallback list, fetch editor endpoint, drag-scroll rail)
- Modify: `assets/portal.css` (premium hover lift, reduced-motion guard)
- Test: `tests/portal.spec.js` (tab renders hero + picks from fixture)

**Interfaces:**
- Consumes: `props.editorEndpoint` (from `data-editor-endpoint`); the editor payload `{ source, picks: [{ t, genre, platform, meta, poster, type, country, rank }] }` from Task 4.
- Produces: a fifth nav tab `editor` → `editorSection()`; `data.editorPicks` array; `editorSource` flag.

- [ ] **Step 1: Write the failing tests**

Add to `tests/portal.spec.js`:

```js
test('editor picks tab renders a premium hero and ranked rail from fixture', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  await expect(page.getByRole('heading', { name: 'Editor Picks' })).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toBeVisible();
  await expect(page.getByText('Fixture Pick Two')).toBeVisible();
  await expect(page.getByText('Listings and artwork from')).toBeVisible();
});

test('editor picks tab shows the built-in list when the endpoint is offline', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  await expect(page.getByRole('heading', { name: 'Editor Picks' })).toBeVisible();
  // At least one built-in fallback pick renders.
  await expect(page.locator('[data-screen-label="Editor Picks"]')).toContainText('Curated by');
});
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `npx playwright test -g "editor picks tab"`
Expected: FAIL — no "Editor Picks" nav button.

- [ ] **Step 3: Add the built-in fallback picks + a pick art helper**

In `assets/portal.js`, near the other data constants (after `COLLECTIONS`), add a small curated fallback so the tab is never empty offline:

```js
  const EDITOR_FALLBACK = [
    { t: 'The Colour of Home', genre: 'Drama', platform: '★ 8.4', meta: '2024', type: 'Movies', country: 'South Africa', rank: 1 },
    { t: 'Harmattan', genre: 'Thriller', platform: '★ 8.1', meta: 'TV · 2023', type: 'Series', country: 'Nigeria', rank: 2 },
    { t: 'Salt & Silver', genre: 'Docs', platform: '★ 7.9', meta: '2022', type: 'Movies', country: 'Kenya', rank: 3 },
    { t: 'The Long Dry', genre: 'Drama', platform: '★ 7.7', meta: '2021', type: 'Movies', country: 'South Africa', rank: 4 },
    { t: 'Northern Lights', genre: 'Family', platform: '★ 7.5', meta: 'TV · 2020', type: 'Series', country: 'United Kingdom', rank: 5 },
  ].map((p) => ({ ...p, initial: p.t[0], bg: bg(p.genre) }));
```

- [ ] **Step 4: Wire the nav tab and section registry**

Update `NAV`, `SECTIONS`, the default-tab whitelist, and add `editorPicks`/`editorSource` to state/data.

`NAV` (insert Editor Picks between watch and tips):

```js
    const NAV = [
      { id: 'profile', label: 'Profile' },
      { id: 'watch', label: 'What to Watch' },
      { id: 'editor', label: 'Editor Picks' },
      { id: 'tips', label: 'Tips & Tricks' },
      { id: 'help', label: 'Troubleshooting' }
    ];
    const SECTIONS = { profile: profileSection, watch: watchSection, editor: editorSection, tips: tipsSection, help: helpSection };
```

The `state.section` init whitelist:

```js
      section: ['profile', 'watch', 'editor', 'tips', 'help'].includes(props.defaultTab) ? props.defaultTab : 'profile',
```

Add to the `data` object literal: `editorPicks: EDITOR_FALLBACK`. Add a `let editorSource = 'built-in';` next to `let dataSource = 'built-in';`. Add `editorEndpoint: root.getAttribute('data-editor-endpoint') || ''` to the `props` object.

- [ ] **Step 5: Write `editorSection()` (premium editorial layout)**

Add this function alongside the other section functions in `assets/portal.js`:

```js
    function editorSection() {
      const picks = data.editorPicks || [];
      const hero = picks[0];
      const rest = picks.slice(1);
      const heroArt = (m) => m.poster
        ? `<img src="${esc(m.poster)}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">`
        : `<div style="position:absolute;top:-30px;right:-6px;font-size:200px;font-weight:800;color:rgba(255,255,255,.10);line-height:1;user-select:none">${esc(m.initial)}</div>`;

      return `
<section data-screen-label="Editor Picks">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Editor Picks</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Curated by the AfriStream editors — a hand-picked watchlist, refreshed regularly.</p>
  </div>
  ${hero ? `
  <div style="position:relative;border-radius:22px;overflow:hidden;background:linear-gradient(120deg,#0B1533 20%,#16327E 80%);color:#fff;min-height:280px;display:flex;align-items:flex-end;margin-bottom:26px;box-shadow:0 24px 60px -34px rgba(11,21,51,.7)">
    <div style="position:absolute;inset:0">${heroArt(hero)}<div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(5,9,24,.86) 0%,rgba(5,9,24,.55) 46%,rgba(5,9,24,.2) 100%)"></div></div>
    <div style="position:relative;padding:clamp(22px,4vw,40px);max-width:620px;display:flex;flex-direction:column;gap:12px">
      <span style="align-self:flex-start;display:inline-flex;align-items:center;gap:7px;font-size:10.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#F4C56B">★ Editors' No.1</span>
      <div style="font-size:clamp(26px,4.5vw,40px);font-weight:800;letter-spacing:-0.02em;line-height:1.05">${esc(hero.t)}</div>
      <div style="font-size:13px;color:rgba(255,255,255,.75);display:flex;gap:8px;flex-wrap:wrap"><span style="font-weight:700">${esc(hero.genre)}</span><span>·</span><span>${esc(hero.meta)}</span>${hero.country ? `<span>·</span><span>${esc(hero.country)}</span>` : ''}<span>·</span><span>${esc(hero.platform)}</span></div>
    </div>
  </div>` : ''}
  ${rest.length ? `
  <h2 style="margin:0 0 12px 2px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">More from the list</h2>
  <div data-dragscroll style="display:flex;gap:16px;overflow-x:auto;padding-bottom:12px">
    ${rest.map((m) => `
      <div class="as-editor-card" style="flex:none;width:174px;scroll-snap-align:start">
        <div style="width:100%;aspect-ratio:2/3;border-radius:16px;background:${m.bg};position:relative;overflow:hidden;box-shadow:0 14px 30px -20px rgba(11,21,51,.6)">
          ${m.poster ? `<img src="${esc(m.poster)}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"><div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,9,24,0) 48%,rgba(5,9,24,.82))"></div>` : `<div style="position:absolute;top:-22px;right:-8px;font-size:130px;font-weight:800;color:rgba(255,255,255,.13);line-height:1;user-select:none">${esc(m.initial)}</div>`}
          <div style="position:absolute;top:10px;left:10px;width:30px;height:30px;border-radius:50%;background:rgba(5,9,24,.6);color:#F4C56B;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(244,197,107,.5)">${m.rank || ''}</div>
        </div>
        <div style="margin-top:9px;font-size:13.5px;font-weight:700;line-height:1.25">${esc(m.t)}</div>
        <div style="margin-top:3px;font-size:11.5px;color:rgba(11,21,51,.55)">${esc(m.genre)} · ${esc(m.meta)}</div>
      </div>`).join('')}
  </div>` : ''}
  ${!picks.length ? `<div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">The editors' list is refreshing — check back shortly.</div>` : ''}
  ${editorSource === 'imdb' ? `
  <p style="margin:22px 2px 0;display:flex;align-items:center;flex-wrap:wrap;gap:6px 8px;font-size:11px;color:rgba(11,21,51,.45)">
    <a href="https://www.themoviedb.org" target="_blank" rel="noopener noreferrer" aria-label="TMDB" style="display:inline-flex;flex:none">${TMDB_LOGO}</a>
    <span>Listings and artwork from TMDB. This product uses the TMDB API but is not endorsed or certified by TMDB.</span>
  </p>` : ''}
</section>`;
    }
```

- [ ] **Step 6: Fetch the editor endpoint on mount**

In `createPortal`, after the existing `fetch(props.endpoint)` block (still inside `createPortal`), add:

```js
    if (props.editorEndpoint && typeof fetch === 'function') {
      const prepPicks = (arr) => (Array.isArray(arr) ? arr : [])
        .filter((x) => x && x.t)
        .map((x) => ({ ...x, initial: String(x.t)[0], bg: bg(x.genre) }));
      fetch(props.editorEndpoint)
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          if (!payload || payload.source === 'fallback') return;
          const picks = prepPicks(payload.picks);
          if (!picks.length) return;
          data.editorPicks = picks;
          editorSource = 'imdb';
          if (state.section === 'editor') render(true);
        })
        .catch(() => { /* endpoint unreachable — built-in picks stay */ });
    }
```

- [ ] **Step 7: Add premium hover + reduced-motion CSS**

In `assets/portal.css`, append:

```css
.afristream-portal .as-editor-card {
  transition: transform .18s ease;
}
.afristream-portal .as-editor-card:hover {
  transform: translateY(-4px) scale(1.02);
}
@media (prefers-reduced-motion: reduce) {
  .afristream-portal .as-editor-card { transition: none; }
  .afristream-portal .as-editor-card:hover { transform: none; }
}
```

- [ ] **Step 8: Run the Editor Picks tests and confirm they pass**

Run: `npx playwright test -g "editor picks tab"`
Expected: PASS.

- [ ] **Step 9: Run the full suite**

Run: `npx playwright test tests/portal.spec.js`
Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add assets/portal.js assets/portal.css tests/portal.spec.js
git commit -m "Add premium Editor Picks page consuming the IMDb-curated endpoint"
```

---

## Task 6: Guardrails — version bump, changelog, lint, final verification

**Files:**
- Modify: `afristream-portal.php` (header `Version:` + `AFRISTREAM_PORTAL_VERSION`)
- Modify: `package.json` (`version`)
- Modify: `CHANGELOG.md`

**Interfaces:** none (release housekeeping).

- [ ] **Step 1: Bump the version to 0.4.0**

In `afristream-portal.php`, change the header line `* Version:     0.3.0` → `* Version:     0.4.0`, and `define( 'AFRISTREAM_PORTAL_VERSION', '0.3.0' );` → `'0.4.0'`.

In `package.json`, change `"version": "0.3.0"` → `"version": "0.4.0"`.

- [ ] **Step 2: Add the changelog entry**

In `CHANGELOG.md`, insert directly under the `# ...Semantic Versioning...` intro line (above `## [0.3.0]`):

```markdown
## [0.4.0] - 2026-07-08

### Added

- **Editor Picks** — a new page curated from a public IMDb watchlist. The plugin scrapes the watchlist server-side, resolves each title through TMDB for consistent artwork, and presents them with a premium editorial hero and a ranked, drag-scrollable rail. New REST endpoint `afristream/v1/editor-picks` (cached 12h with a last-good fallback so an IMDb outage never blanks the page), a settings field for the watchlist URL, and a deterministic fixture for tests.
- **Mouse drag-to-scroll** on every horizontal row in What to Watch and Editor Picks — click and drag to pan, with a small threshold so clicking a poster still works.
- **Deep, filterable catalog** — the watch endpoint now returns a much larger catalog built from TMDB discover across ~14 origin countries, so the filters have real breadth. Cached 12h; falls back to the built-in lists when unavailable.

### Changed

- **What to Watch filters overhauled** — the drawer is now a cascading Type → Genre → Country → Decade set, with Sorts (Recommended, A–Z, Newest, Top Rated) always available. Country is real origin-country data from the deep catalog; Year is replaced by Decade; the separate Category concept is folded into Genre. Facets remain dependent, so each choice narrows the next.
```

- [ ] **Step 3: Run the linter once**

Run: `npm run lint`
Expected: exit 0 (all three files pass `node --check`). Record any findings for the user — do not auto-fix.

- [ ] **Step 4: Run the full test suite**

Run: `npx playwright test`
Expected: all PASS.

- [ ] **Step 5: Manually verify in the preview server (optional but recommended)**

Run: `npm run preview` then open http://localhost:4173 — check drag-scroll, the new filter drawer, and the Editor Picks tab render. (Set `TMDB_API_KEY` to see live catalog + real IMDb picks.)

- [ ] **Step 6: Commit**

```bash
git add afristream-portal.php package.json CHANGELOG.md
git commit -m "Bump to 0.4.0 and update changelog"
```

---

## Self-Review Notes

- **Spec coverage:** drag-scroll (Task 1), filter overhaul incl. decade + dependent facets (Task 3) backed by deep per-country catalog (Task 2), Editor Picks premium page from IMDb watchlist with last-good fallback + settings (Tasks 4–5), tests throughout, version/changelog/lint (Task 6). All spec sections map to a task.
- **Category = Genre:** no Category facet exists anywhere in the plan — satisfied.
- **PHP ↔ preview parity:** Tasks 2 and 4 change both files in the same task.
- **Type consistency:** payload keys (`catalog`, `picks`), item fields (`country`, `decade`, `rank`), state keys (`type/genre/country/decade/sort`), and helper names (`decadeOf`, `facetPool`, `discoverCountry`/`afristream_portal_tmdb_discover`, `resolvePick`/`afristream_portal_resolve_pick`) are used identically across tasks.
- **No new dependencies:** drag-scroll and IMDb parsing are hand-rolled.

## Deployment (session end, per global rules)

After all tasks pass: bump already done (Task 6); build and zip the plugin to `<plugin-parent-dir>/afristream-portal.zip` (removing any older versioned zip first) via `npm run build` / the build script; that zip is the deployment artifact.
