# Card Detail Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every card in What to Watch and Editor Picks clickable, opening a right-hand detail panel that shows the item's details and (for films/series) a lazy-fetched TMDB synopsis.

**Architecture:** The portal is a single vanilla-JS file (`assets/portal.js`) that re-renders the whole mount on every state change. A new `state.detail` object drives a right-sliding drawer built with the same scrim-plus-panel pattern as the existing Filters drawer. Cards register themselves into a render-scoped array so a click can be mapped back to its data object. Synopses are fetched on demand from a new REST endpoint (`afristream/v1/detail`), mirrored in the local preview server, and cached client-side. The PHP plugin and the preview server are two mirrors of the same data mapping and must stay in sync.

**Tech Stack:** Vanilla JS (no framework), PHP (WordPress plugin), Node.js http preview server, Playwright tests.

## Global Constraints

- Plugin version bumps to **0.5.0** (minor / new feature): the `Version:` header AND `AFRISTREAM_PORTAL_VERSION` constant in `afristream-portal.php`, and `version` in `package.json` — all three in sync.
- `CHANGELOG.md` gets a `## [0.5.0] - 2026-07-09` entry alongside the version bump.
- No new npm/PHP dependencies (none needed here).
- PHP mapping (`afristream-portal.php`) and preview server (`scripts/preview-server.mjs`) mirror each other and MUST stay in sync — every data-shape change lands in both.
- TDD: write the failing test first, watch it fail, implement minimal code, watch it pass, commit.
- Tests run hermetically with `WATCH_OFFLINE=1` (see `playwright.config.js`); anything needing live-shaped data uses the fixture page (`preview/fixture.html`) / `?fixture=1` endpoints.
- Lint once at the end (`npm run lint`); present findings, do not auto-loop.
- All user-facing HTML strings pass through the existing `esc()` helper; never interpolate raw item text into markup.

---

### Task 1: Carry the TMDB `id` through the data layer

Adds the `id` field to every mapped item in both mirrors and the fixtures, so a synopsis can be fetched later. No UI change yet — this task is verified by an endpoint-shape test.

**Files:**
- Modify: `afristream-portal.php` (functions `afristream_portal_tmdb_map`, `afristream_portal_tmdb_discover`, `afristream_portal_resolve_pick`)
- Modify: `scripts/preview-server.mjs` (functions `mapItems`, `discoverCountry`, `resolvePick`; `FIXTURE` and `EDITOR_FIXTURE` constants)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Produces: every item object returned by `/api/watch` and `/api/editor-picks` (movies, series, newWeek, catalog, picks) now includes `id` (integer TMDB id; `0` when unknown). The item's existing `type` field (`'Movies'`/`'Series'`) still distinguishes movie vs tv.

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```javascript
test('watch fixture items carry a TMDB id for detail lookups', async ({ request }) => {
  const res = await request.get('/api/watch?fixture=1');
  const json = await res.json();
  expect(json.movies[0]).toHaveProperty('id');
  expect(typeof json.movies[0].id).toBe('number');
  expect(json.catalog[0]).toHaveProperty('id');
});

test('editor-picks fixture items carry a TMDB id', async ({ request }) => {
  const res = await request.get('/api/editor-picks?fixture=1');
  const json = await res.json();
  expect(json.picks[0]).toHaveProperty('id');
  expect(typeof json.picks[0].id).toBe('number');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx playwright test -g "carry a TMDB id"`
Expected: FAIL — items have no `id` property.

- [ ] **Step 3: Add `id` to the preview-server fixtures**

In `scripts/preview-server.mjs`, add an `id` to every item in `FIXTURE` (movies, series, newWeek, catalog) and `EDITOR_FIXTURE.picks`. Use distinct positive integers, e.g. movies `101, 102`, series `201`, newWeek `301`, catalog `401..406`, picks `501, 502, 503`. Example for the two movies:

```javascript
  movies: [
    { t: 'Fixture Movie One', genre: 'Drama', platform: '★ 8.1', meta: '2026', poster: null, type: 'Movies', id: 101 },
    { t: 'Fixture Movie Two', genre: 'Action', platform: '★ 7.4', meta: '2025', poster: null, type: 'Movies', id: 102 },
  ],
```

Apply the same pattern (adding `id: <n>`) to every remaining item in `series`, `newWeek`, `catalog`, and `EDITOR_FIXTURE.picks`.

- [ ] **Step 4: Add `id` to the preview-server live mappers**

In `scripts/preview-server.mjs`:

In `mapItems`, add `id` to the pushed object:

```javascript
    items.push({
      t: title,
      id: Number(row.id) || 0,
      genre: genres[row.genre_ids?.[0]] || type,
      platform: rating > 0 ? `★ ${rating.toFixed(1)}` : 'New',
      meta: metaLabel || (type === 'Series' ? `TV · ${year}` : year),
      poster: row.poster_path ? `https://image.tmdb.org/t/p/w342${row.poster_path}` : null,
      type,
    });
```

In `discoverCountry`, add `id: Number(row.id) || 0` to the returned object.

In `resolvePick`, add `id: Number(hit.id) || 0` to the returned object (and, for completeness, the no-hit fallback branch can use `id: 0`).

- [ ] **Step 5: Add `id` to the PHP live mappers**

In `afristream-portal.php`:

In `afristream_portal_tmdb_map`, add to the `$items[]` array:

```php
			'id'       => isset( $row['id'] ) ? (int) $row['id'] : 0,
```

In `afristream_portal_tmdb_discover`, add `'id' => isset( $row['id'] ) ? (int) $row['id'] : 0,` to the `$items[]` array.

In `afristream_portal_resolve_pick`, add `'id' => isset( $hit['id'] ) ? (int) $hit['id'] : 0,` to the resolved-item array (and `'id' => 0,` in the fallback-title array).

- [ ] **Step 6: Run tests to verify they pass**

Run: `npx playwright test -g "carry a TMDB id"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add afristream-portal.php scripts/preview-server.mjs tests/portal.spec.js
git commit -m "Carry TMDB id through watch and editor-picks items"
```

---

### Task 2: Synopsis endpoint (`/detail`)

Adds a lightweight endpoint returning a single item's overview text, in both mirrors, plus a deterministic fixture response for tests.

**Files:**
- Modify: `afristream-portal.php` (new `afristream_portal_detail_data` callback + route registration in `afristream_portal_register_rest_routes`)
- Modify: `scripts/preview-server.mjs` (new `detailPayload` + route handling in the request `createServer` callback)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Produces: `GET /api/detail?id=<int>&type=<movie|tv>` → `{ "overview": "<string>" }`. In fixture mode (`?fixture=1`) returns `{ "overview": "Fixture synopsis for <id>." }`. On any failure or missing key, returns `{ "overview": "" }`.
- The WordPress equivalent is `afristream/v1/detail` with the same query params and response shape.

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```javascript
test('detail fixture endpoint returns an overview for an id', async ({ request }) => {
  const res = await request.get('/api/detail?fixture=1&id=101&type=movie');
  expect(res.ok()).toBeTruthy();
  const json = await res.json();
  expect(json).toHaveProperty('overview');
  expect(json.overview).toContain('101');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx playwright test -g "detail fixture endpoint"`
Expected: FAIL — `/api/detail` is not handled (404 / not ok).

- [ ] **Step 3: Implement the preview-server endpoint**

In `scripts/preview-server.mjs`, add a payload builder near `watchPayload`:

```javascript
async function detailPayload(id, type) {
  const kind = type === 'tv' ? 'tv' : 'movie';
  if (process.env.WATCH_OFFLINE === '1' || !id) return { overview: '' };
  const json = await tmdbGet(`/${kind}/${id}`);
  return { overview: (json && json.overview) || '' };
}
```

Then, inside the `createServer` request handler, add a route branch (place it beside the `/api/editor-picks` branch):

```javascript
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx playwright test -g "detail fixture endpoint"`
Expected: PASS.

- [ ] **Step 5: Implement the PHP endpoint**

In `afristream-portal.php`, add the callback (near `afristream_portal_editor_data`):

```php
/**
 * Single-item synopsis for the card detail panel. Given a TMDB id and kind
 * (movie|tv), returns { overview }. Cached per id+kind for 24h; an empty
 * string whenever no key is set or TMDB is unreachable.
 */
function afristream_portal_detail_data( $request ) {
	$id   = absint( $request->get_param( 'id' ) );
	$type = 'tv' === $request->get_param( 'type' ) ? 'tv' : 'movie';
	if ( ! $id ) {
		return rest_ensure_response( array( 'overview' => '' ) );
	}
	$cache_key = 'afristream_portal_detail_' . $type . '_' . $id;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return rest_ensure_response( array( 'overview' => $cached ) );
	}
	if ( ! afristream_portal_tmdb_key() ) {
		return rest_ensure_response( array( 'overview' => '' ) );
	}
	$json     = afristream_portal_tmdb_get( '/' . $type . '/' . $id );
	$overview = ( $json && ! empty( $json['overview'] ) ) ? (string) $json['overview'] : '';
	set_transient( $cache_key, $overview, 24 * HOUR_IN_SECONDS );
	return rest_ensure_response( array( 'overview' => $overview ) );
}
```

Then register the route inside `afristream_portal_register_rest_routes`, after the `/editor-picks` block:

```php
	register_rest_route(
		'afristream/v1',
		'/detail',
		array(
			'methods'             => 'GET',
			'callback'            => 'afristream_portal_detail_data',
			'permission_callback' => '__return_true',
		)
	);
```

- [ ] **Step 6: Verify PHP parses**

Run: `npm run lint`
Expected: `node --check` passes for the JS files (PHP is not checked here; ensure no JS syntax errors were introduced). The `-g "detail fixture endpoint"` test still passes.

- [ ] **Step 7: Commit**

```bash
git add afristream-portal.php scripts/preview-server.mjs tests/portal.spec.js
git commit -m "Add /detail synopsis endpoint (PHP + preview mirror)"
```

---

### Task 3: Wire the detail endpoint into the front-end props

Adds the `data-detail-endpoint` attribute to the shortcode output and the preview pages, and reads it into the portal's `props`. No visible change yet.

**Files:**
- Modify: `afristream-portal.php` (`afristream_portal_shortcode`)
- Modify: `preview/index.html`, `preview/fixture.html`
- Modify: `assets/portal.js` (`createPortal` props)

**Interfaces:**
- Produces: `props.detailEndpoint` — a string base URL for the detail endpoint (e.g. `/api/detail?fixture=1` on the fixture page, `/api/detail` on the main preview, `rest_url('afristream/v1/detail')` in WP). May already contain a query string; the front-end appends params with the correct separator.

- [ ] **Step 1: Add the attribute in the shortcode**

In `afristream-portal.php`, extend the `sprintf` in `afristream_portal_shortcode` to add a fourth data attribute. Update the format string and add the arg:

```php
	return sprintf(
		'<div class="afristream-portal" data-afristream-portal data-default-tab="%s" data-show-sport="%s" data-endpoint="%s" data-editor-endpoint="%s" data-detail-endpoint="%s"></div>',
		esc_attr( $atts['default_tab'] ),
		esc_attr( $atts['show_sport'] ),
		esc_url( rest_url( 'afristream/v1/watch' ) ),
		esc_url( rest_url( 'afristream/v1/editor-picks' ) ),
		esc_url( rest_url( 'afristream/v1/detail' ) )
	);
```

- [ ] **Step 2: Add the attribute to the preview pages**

In `preview/index.html`, add `data-detail-endpoint="/api/detail"` to the portal div.

In `preview/fixture.html`, add `data-detail-endpoint="/api/detail?fixture=1"` to the portal div.

- [ ] **Step 3: Read the prop in the front-end**

In `assets/portal.js`, in `createPortal`'s `props` object, add:

```javascript
      detailEndpoint: root.getAttribute('data-detail-endpoint') || ''
```

- [ ] **Step 4: Verify nothing broke**

Run: `npm run lint && npx playwright test -g "poster rows and sport"`
Expected: lint passes; the existing watch smoke test still passes.

- [ ] **Step 5: Commit**

```bash
git add afristream-portal.php preview/index.html preview/fixture.html assets/portal.js
git commit -m "Thread detail endpoint through shortcode and preview pages"
```

---

### Task 4: Make title cards clickable and open a basic detail panel

Introduces the card registry, `state.detail`, the drawer markup, and the click/keyboard/close wiring — for poster (title) cards first. Synopsis loading comes in Task 6; non-title kinds in Task 5.

**Files:**
- Modify: `assets/portal.js` (helpers `posterGridItem`/`posterRowItem`, new `detailDrawer`, `render`, event handlers, `state`)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `props.detailEndpoint` (Task 3); items carrying `id`/`type`/`genre`/`meta`/`platform`/`country`/`poster`/`bg`/`initial`.
- Produces:
  - `state.detail` — the registered detail object currently shown, or `null`.
  - `cardRegistry` — a render-scoped array; `reg(obj)` pushes `obj` and returns its index.
  - `detailDrawer(obj)` — returns the drawer HTML string for `obj`.
  - Clickable cards carry `data-act="detail" data-card="<idx>"`.
  - `data-testid="detail-drawer"` on the drawer panel for tests.

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```javascript
test('clicking a poster card opens a detail panel with its details', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Fixture Movie One')).toBeVisible();
  await expect(drawer.getByText('Drama')).toBeVisible();
  await expect(drawer.getByText('★ 8.1')).toBeVisible();
});

test('detail panel closes via the close button', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await drawer.getByRole('button', { name: 'Close details' }).click();
  await expect(drawer).not.toBeVisible();
});

test('detail panel closes on Escape', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('detail-drawer')).not.toBeVisible();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx playwright test -g "detail panel|opens a detail panel"`
Expected: FAIL — no drawer exists.

- [ ] **Step 3: Add the registry and `state.detail`**

In `assets/portal.js`, inside `createPortal`, add to `state` (near the other fields):

```javascript
      detail: null,
```

Above the `render` function (alongside the other render-scoped locals), declare the registry and a flag for one-time focus:

```javascript
    let cardRegistry = [];
    let detailFocusPending = false;
    const reg = (obj) => cardRegistry.push(obj) - 1;
```

At the very top of `render`, reset the registry each pass:

```javascript
    function render(preserveFocus) {
      cardRegistry = [];
```

(Place this immediately after the `function render(preserveFocus) {` line, before the existing focus-capture code.)

- [ ] **Step 4: Make title poster cards register + become clickable**

In `assets/portal.js`, replace the two poster item helpers so the outer element is a button-like, registered card:

```javascript
  const cardAttrs = (obj) => `data-act="detail" data-card="${reg(obj)}" role="button" tabindex="0" aria-label="View details for ${esc(obj.t)}"`;

  const posterGridItem = (m) => `<div ${cardAttrs(m)} style="cursor:pointer">${posterArt(m)}${posterMeta(m)}</div>`;
  const posterRowItem = (m) => `<div ${cardAttrs(m)} style="flex:none;width:148px;scroll-snap-align:start;cursor:pointer">${posterArt(m)}${posterMeta(m)}</div>`;
```

Note: `reg`, `cardAttrs`, `posterGridItem`, `posterRowItem` all reference `createPortal`-scoped state, so they must live inside `createPortal`. Move the `posterGridItem`/`posterRowItem` definitions (and add `cardAttrs`) to inside `createPortal`, just after `reg` is declared. Leave `posterArt`/`posterMeta` where they are (they are pure and take the item as an argument).

- [ ] **Step 5: Add the `detailDrawer` function (title kind only for now)**

In `assets/portal.js`, add inside `createPortal` (near the other section builders):

```javascript
    function detailDrawer(obj) {
      const chips = [obj.genre, obj.meta, obj.country, obj.platform, obj.type]
        .filter(Boolean)
        .map((c) => `<span style="font-size:12px;font-weight:700;color:#0B1533;background:#EEF3FE;border:1px solid rgba(46,91,230,.18);padding:5px 11px;border-radius:999px">${esc(c)}</span>`)
        .join('');
      const art = obj.poster
        ? `<img src="${esc(obj.poster)}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"><div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,9,24,0) 40%,rgba(5,9,24,.85))"></div>`
        : `<div style="position:absolute;top:-30px;right:-8px;font-size:180px;font-weight:800;color:rgba(255,255,255,.12);line-height:1;user-select:none">${esc(obj.initial || (obj.t || '')[0] || '')}</div>`;
      return `
    <div data-act="close-detail" style="position:fixed;inset:0;background:rgba(11,21,51,.5);z-index:70"></div>
    <div data-testid="detail-drawer" role="dialog" aria-modal="true" aria-label="${esc(obj.t)} details" style="position:fixed;top:0;right:0;bottom:0;width:min(420px,94vw);background:#fff;z-index:71;box-shadow:-24px 0 60px -30px rgba(11,21,51,.5);display:flex;flex-direction:column;overflow-y:auto">
      <div style="position:relative;min-height:220px;background:${obj.bg || '#0B1533'};color:#fff;display:flex;align-items:flex-end;padding:18px">
        ${art}
        <button data-act="close-detail" aria-label="Close details" style="position:absolute;top:14px;right:14px;background:rgba(5,9,24,.55);border:none;border-radius:999px;width:34px;height:34px;cursor:pointer;font-size:15px;color:#fff;font-family:inherit;z-index:1">✕</button>
        <div style="position:relative;font-size:22px;font-weight:800;line-height:1.15;text-shadow:0 1px 8px rgba(0,0,0,.5)">${esc(obj.t)}</div>
      </div>
      <div style="padding:20px 22px;display:flex;flex-direction:column;gap:16px">
        <div style="display:flex;gap:8px;flex-wrap:wrap">${chips}</div>
        <div data-detail-synopsis style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)"></div>
      </div>
    </div>`;
    }
```

- [ ] **Step 6: Render the drawer from `render`**

In `assets/portal.js`, in `render`, append the drawer just before the closing `</div>` of the top-level wrapper. Find the line that closes the main wrapper (the final `</div>` in the `root.innerHTML` template, after `<footer>…</footer>`) and insert the drawer conditionally:

```javascript
</footer>
${state.detail ? detailDrawer(state.detail) : ''}
</div>`;
```

- [ ] **Step 7: Handle open / close clicks**

In `assets/portal.js`, in the bubbling click handler `switch`, add two cases:

```javascript
        case 'detail': {
          const obj = cardRegistry[+el.getAttribute('data-card')];
          if (obj) { detailFocusPending = true; setState({ detail: obj }); }
          break;
        }
        case 'close-detail': setState({ detail: null }); break;
```

- [ ] **Step 8: Keyboard — Enter/Space to open, Escape to close, focus the close button**

In `assets/portal.js`, after the existing `root.addEventListener('input', …)` block, add:

```javascript
    root.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && state.detail) { setState({ detail: null }); return; }
      if ((e.key === 'Enter' || e.key === ' ') && e.target.getAttribute && e.target.getAttribute('data-act') === 'detail') {
        const obj = cardRegistry[+e.target.getAttribute('data-card')];
        if (obj) { e.preventDefault(); detailFocusPending = true; setState({ detail: obj }); }
      }
    });
```

At the end of `render`, after the focus-restore block for the query input, focus the drawer's close button on a fresh open:

```javascript
      if (detailFocusPending) {
        detailFocusPending = false;
        const closeBtn = root.querySelector('[data-testid="detail-drawer"] [aria-label="Close details"]');
        if (closeBtn) closeBtn.focus();
      }
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `npx playwright test -g "detail panel|opens a detail panel"`
Expected: PASS (all three tests).

- [ ] **Step 10: Confirm existing drag-scroll test still passes**

Run: `npx playwright test -g "drag-scroll"`
Expected: PASS — a plain click still opens the panel; a real drag still scrolls without opening it.

- [ ] **Step 11: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "Clickable title cards open a detail side panel"
```

---

### Task 5: Make sport, Live TV, Collections, and Editor cards clickable

Extends the registry/drawer to the remaining card types with per-kind detail content, and wires the Editor hero + ranked rail.

**Files:**
- Modify: `assets/portal.js` (`watchSection` sport/liveTV/collections cards, `editorSection` hero + rail, `detailDrawer`)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `reg`, `cardAttrs`, `detailDrawer` (Task 4).
- Produces: registered detail objects tagged with `detailKind`:
  - `'sport'`: `{ detailKind:'sport', t, comp, time, ch, live, bg }`
  - `'channel'`: `{ detailKind:'channel', t, tag, bg }`
  - `'collection'`: `{ detailKind:'collection', t, desc, count, bg }`
  - `'title'` (default, unchanged): the raw item.
  - `detailDrawer` switches its body on `detailKind`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/portal.spec.js`:

```javascript
test('clicking a sport card opens its detail panel', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture FC vs Test United').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Fixture Sports')).toBeVisible();
  await expect(drawer.getByText('Fixture League')).toBeVisible();
});

test('clicking an editor pick card opens its detail panel', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  await page.getByText('Fixture Pick Two').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Fixture Pick Two')).toBeVisible();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx playwright test -g "sport card opens|editor pick card opens"`
Expected: FAIL — those cards are not clickable yet.

- [ ] **Step 3: Extend `detailDrawer` to switch on `detailKind`**

In `assets/portal.js`, update `detailDrawer` so the meta chips + synopsis area depend on `obj.detailKind`. Replace the `chips` / body construction with a per-kind block. Keep the header (art + title + close) identical. Body:

```javascript
      let body;
      if (obj.detailKind === 'sport') {
        const rows = [['Competition', obj.comp], ['When', obj.time], ['Channel', obj.ch], obj.live ? ['Status', 'LIVE now'] : null].filter(Boolean);
        body = `<div style="display:flex;flex-direction:column;gap:12px">${rows.map(([k, v]) => `<div style="display:flex;justify-content:space-between;gap:16px;font-size:14px"><span style="color:rgba(11,21,51,.55);font-weight:700">${esc(k)}</span><span style="color:#0B1533;text-align:right">${esc(v)}</span></div>`).join('')}</div>`;
      } else if (obj.detailKind === 'channel') {
        body = `<div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)"><span style="font-weight:700">${esc(obj.tag)}</span> · Live channel</div>`;
      } else if (obj.detailKind === 'collection') {
        body = `<div style="display:flex;flex-direction:column;gap:10px"><div style="font-size:12px;font-weight:700;color:rgba(11,21,51,.55)">${esc(obj.count || '')}</div><div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)">${esc(obj.desc || '')}</div></div>`;
      } else {
        const chips = [obj.genre, obj.meta, obj.country, obj.platform, obj.type]
          .filter(Boolean)
          .map((c) => `<span style="font-size:12px;font-weight:700;color:#0B1533;background:#EEF3FE;border:1px solid rgba(46,91,230,.18);padding:5px 11px;border-radius:999px">${esc(c)}</span>`)
          .join('');
        body = `<div style="display:flex;flex-direction:column;gap:16px"><div style="display:flex;gap:8px;flex-wrap:wrap">${chips}</div><div data-detail-synopsis style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)"></div></div>`;
      }
```

Then change the drawer body container to render `${body}` instead of the inline chips/synopsis from Task 4:

```javascript
      <div style="padding:20px 22px;display:flex;flex-direction:column;gap:16px">
        ${body}
      </div>
```

- [ ] **Step 4: Register the sport cards**

In `assets/portal.js`, in `watchSection`, the sport card outer `<div>` (inside `data.sport.map`) gets card attributes. Change its opening tag from `<div style="flex:none;width:236px;…">` to include the registered attrs:

```javascript
            <div ${cardAttrs({ detailKind: 'sport', t: s.fx, comp: s.comp, time: s.time, ch: s.ch, live: s.live, bg: 'linear-gradient(150deg,#13264E,#0A142E)' })} style="cursor:pointer;flex:none;width:236px;border-radius:14px;background:linear-gradient(150deg,#13264E,#0A142E);color:#fff;padding:15px 16px;display:flex;flex-direction:column;gap:8px;min-height:118px">
```

- [ ] **Step 5: Register the Live TV cards**

In `assets/portal.js`, in `watchSection`, the Live TV card outer `<div>` (inside `LIVE_TV.map`) gets:

```javascript
            <div ${cardAttrs({ detailKind: 'channel', t: t.name, tag: t.tag, bg: t.bg })} style="cursor:pointer;flex:none;width:158px;aspect-ratio:16/10;border-radius:13px;background:${t.bg};color:#fff;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:5px;padding:10px;text-align:center">
```

- [ ] **Step 6: Register the Collections cards**

In `assets/portal.js`, in `watchSection`, the Collections card outer `<div>` (inside `COLLECTIONS.map`) gets:

```javascript
            <div ${cardAttrs({ detailKind: 'collection', t: c.name, desc: c.desc, count: c.count, bg: c.bg })} style="cursor:pointer;flex:none;width:250px;border-radius:15px;background:${c.bg};color:#fff;padding:18px;display:flex;flex-direction:column;gap:6px;min-height:132px">
```

- [ ] **Step 7: Register the Editor hero + grid cards**

> NOTE (reconciled with concurrent refactor): Editor Picks is now a filterable **grid** rendered via an `editorCard(m)` helper (outer `<div class="as-editor-card">`) plus a hero, inside `editorSection`. There is no `rest` variable or `width:174px` rail anymore.

In `assets/portal.js`, in `editorCard(m)`, add card attributes + `cursor:pointer` to the outer div:

```javascript
      <div class="as-editor-card" ${cardAttrs(m)} style="cursor:pointer">
```

In `editorSection`, the hero wrapper `<div style="position:relative;border-radius:22px;overflow:hidden;background:linear-gradient(120deg,#0B1533 20%,#16327E 80%);…">` gets `${cardAttrs(hero)}` added and `cursor:pointer;` prepended to its inline `style`:

```javascript
  <div ${cardAttrs(hero)} style="cursor:pointer;position:relative;border-radius:22px;overflow:hidden;background:linear-gradient(120deg,#0B1533 20%,#16327E 80%);color:#fff;min-height:280px;display:flex;align-items:flex-end;margin-bottom:26px;box-shadow:0 24px 60px -34px rgba(11,21,51,.7)">
```

`hero` and each `m` passed to `editorCard` are raw pick items (they already carry `id`, `type`, `genre`, `meta`, `platform`, `country`, `poster`, `bg`, `initial`), so they register as `detailKind: 'title'` with no wrapper object needed.

- [ ] **Step 8: Run tests to verify they pass**

Run: `npx playwright test -g "sport card opens|editor pick card opens"`
Expected: PASS.

- [ ] **Step 9: Run the full suite to catch regressions**

Run: `npx playwright test`
Expected: PASS (all tests, including the earlier filter/search/drag tests — sport cards are now clickable but still drag-scroll and filter as before).

- [ ] **Step 10: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "Sport, Live TV, Collections and Editor cards open detail panels"
```

---

### Task 6: Lazy-fetch and render the synopsis

Loads the TMDB overview for `title` cards on open, with a loading state and client-side cache.

**Files:**
- Modify: `assets/portal.js` (new `overviewCache`, fetch-on-open logic, render fill)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `props.detailEndpoint`, `state.detail` (a `title` object with `id`/`type`), the `[data-detail-synopsis]` element rendered by `detailDrawer`.
- Produces: fills `[data-detail-synopsis]` with "Loading synopsis…", then the fetched text, or "No synopsis available." Cache keyed `"<type>:<id>"`.

- [ ] **Step 1: Write the failing test**

Add to `tests/portal.spec.js`:

```javascript
test('detail panel loads a synopsis for a title card', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  // Fixture id 101 → canned "Fixture synopsis for 101."
  await expect(drawer.getByText('Fixture synopsis for 101.')).toBeVisible();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx playwright test -g "loads a synopsis"`
Expected: FAIL — the synopsis area stays empty.

- [ ] **Step 3: Add the cache and a fill helper**

In `assets/portal.js`, inside `createPortal` (near `reg`), add:

```javascript
    const overviewCache = new Map();

    function fillSynopsis() {
      if (!state.detail || state.detail.detailKind && state.detail.detailKind !== 'title') return;
      const box = root.querySelector('[data-detail-synopsis]');
      if (!box) return;
      const obj = state.detail;
      if (!obj.id || !props.detailEndpoint || typeof fetch !== 'function') {
        box.textContent = 'No synopsis available.';
        return;
      }
      const kind = obj.type === 'Series' ? 'tv' : 'movie';
      const cacheKey = kind + ':' + obj.id;
      if (overviewCache.has(cacheKey)) {
        box.textContent = overviewCache.get(cacheKey) || 'No synopsis available.';
        return;
      }
      box.textContent = 'Loading synopsis…';
      const sep = props.detailEndpoint.includes('?') ? '&' : '?';
      const url = props.detailEndpoint + sep + 'id=' + encodeURIComponent(obj.id) + '&type=' + kind;
      fetch(url)
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          const text = (payload && payload.overview) || '';
          overviewCache.set(cacheKey, text);
          // Only fill if the same item is still open.
          if (state.detail === obj) {
            const el = root.querySelector('[data-detail-synopsis]');
            if (el) el.textContent = text || 'No synopsis available.';
          }
        })
        .catch(() => {
          if (state.detail === obj) {
            const el = root.querySelector('[data-detail-synopsis]');
            if (el) el.textContent = 'No synopsis available.';
          }
        });
    }
```

- [ ] **Step 4: Call `fillSynopsis` after every render while the panel is open**

In `assets/portal.js`, at the very end of `render` (after the `detailFocusPending` block added in Task 4), add:

```javascript
      if (state.detail) fillSynopsis();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx playwright test -g "loads a synopsis"`
Expected: PASS.

- [ ] **Step 6: Confirm no regression in close/other tests**

Run: `npx playwright test -g "detail"`
Expected: PASS (open, close, Escape, sport, editor, synopsis).

- [ ] **Step 7: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "Lazy-fetch and render TMDB synopsis in the detail panel"
```

---

### Task 7: Version bump, changelog, full suite, build & zip

Finalizes the release per the deployment rules.

**Files:**
- Modify: `afristream-portal.php` (`Version:` header + `AFRISTREAM_PORTAL_VERSION`)
- Modify: `package.json` (`version`)
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Version is already at 0.5.0 — do NOT bump**

> RECONCILED: a concurrent change already bumped `afristream-portal.php` (header + `AFRISTREAM_PORTAL_VERSION`) and `package.json` to `0.5.0`. This feature ships within the same 0.5.0 release, so leave all three untouched. Verify they read `0.5.0` and move on.

- [ ] **Step 2: Add to the EXISTING 0.5.0 changelog entry**

`CHANGELOG.md` already has a `## [0.5.0] - 2026-07-09` section (added by the concurrent Editor Picks filters change). Do NOT create a second 0.5.0 heading. Instead add this bullet under that section's existing `### Added` list (append after the "Editor Picks filters" bullet):

```markdown
- **Card detail panel** — every card in What to Watch and Editor Picks is now clickable (mouse, or keyboard via Enter/Space), sliding in a right-hand detail panel with the item's poster, genre, rating, year, country and type. For films and series the panel lazy-loads a plot synopsis from TMDB via a new `afristream/v1/detail` endpoint (cached 24h; empty when no key or TMDB is unreachable), with a brief loading state and per-item client caching. Sport fixtures, Live TV channels and Collections open panels scaled to their own data. Closes via the ✕ button, the scrim, or Escape. Every mapped item now carries its TMDB id to support the lookup.
```

- [ ] **Step 3: Run the full test suite**

Run: `npx playwright test`
Expected: PASS — all specs green.

- [ ] **Step 4: Lint once**

Run: `npm run lint`
Expected: PASS. Present any findings to the user; do not auto-fix in a loop.

- [ ] **Step 5: Commit the release metadata**

```bash
git add CHANGELOG.md
git commit -m "Changelog: card detail panel (0.5.0)"
```
(Version files are already at 0.5.0 from the concurrent change — nothing to stage there.)

- [ ] **Step 6: Build and zip the plugin**

Run: `npm run build` (stages the deployable folder to `dist/`).

Then create the deployment zip at the plugin parent directory, removing any older versioned zips first, per the WordPress deployment rule. Confirm the final artifact path is `<plugin-parent-dir>/afristream-portal.zip`.

---

## Self-Review

**Spec coverage:**
- Data layer carries `id` → Task 1. ✅
- `/detail` endpoint (PHP + preview + fixture) → Task 2. ✅
- `data-detail-endpoint` threaded through shortcode + preview + props → Task 3. ✅
- Title cards clickable + drawer + open/close/Escape/focus → Task 4. ✅
- Every card (sport/channel/collection/editor hero+rail) → Task 5. ✅
- Lazy synopsis fetch + cache + loading state → Task 6. ✅
- Non-title kinds skip synopsis and show adapted content → Task 5 (`detailDrawer` switch). ✅
- Keyboard access, `role="dialog"`, focus on open → Task 4. ✅
- Version 0.5.0 in all three files, changelog, build/zip → Task 7. ✅
- Tests listed in spec §Testing → Tasks 1, 2, 4, 5, 6. ✅

**Placeholder scan:** No TBD/TODO; all code steps show full code. ✅

**Type consistency:** `reg`/`cardAttrs`/`detailDrawer`/`state.detail`/`cardRegistry`/`overviewCache`/`detailFocusPending`/`fillSynopsis` names are used identically across Tasks 4–6. `detailKind` values (`'sport'`/`'channel'`/`'collection'`/`'title'`) match between registration (Task 5) and the `detailDrawer` switch (Task 5). Endpoint response shape `{ overview }` matches between Task 2 (producer) and Task 6 (consumer). ✅
