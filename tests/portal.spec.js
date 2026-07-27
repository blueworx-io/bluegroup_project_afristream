import { test, expect } from '@playwright/test';
import { mergeEntries, parseEntries } from '../scripts/picks-list.mjs';

// Smoke tests for the AfriStream Customer Portal front-end. They run against
// baseURL — the local preview harness until a staging URL exists (see
// playwright.config.js). Paths may need updating once tests point at the
// real WordPress page hosting [afristream_portal].

test.beforeEach(async ({ page }) => {
  await page.goto('/');
});

test('profile section renders credentials with working copy feedback', async ({ page }) => {
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
  await expect(page.getByText('BabyBlue123')).toBeVisible();

  await page.getByRole('button', { name: 'Copy', exact: true }).first().click();
  await expect(page.getByRole('button', { name: 'Copied!' })).toBeVisible();

  // Switching profile tabs swaps the credentials.
  await page.getByRole('button', { name: 'Profile 2' }).click();
  await expect(page.getByText('BabyBlue-TV')).toBeVisible();
});

test('a customer holding two licences gets a profile tab for each', async ({ page }) => {
  // Two licences is the case the old ACF field could not express — it was
  // capped at one — so both tabs existing together, each with its own
  // exclusive set of credentials, is the case worth pinning down.
  await expect(page.getByRole('button', { name: 'Profile 1' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Profile 2' })).toBeVisible();

  await expect(page.getByText('BabyBlue123')).toBeVisible();
  await page.getByRole('button', { name: 'Profile 2' }).click();
  await expect(page.getByText('BabyBlue-TV')).toBeVisible();
  await expect(page.getByText('BabyBlue123')).toBeHidden();
});

test('on mobile the top bar is a horizontally scrollable tab list', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');

  // The nav tabs live in their own scroll container that overflows on a phone
  // (so the list scrolls sideways) while the page itself never scrolls across.
  const tabs = page.locator('.as-tabs');
  await expect(tabs).toBeVisible();
  const { tabsOverflow, pageOverflow } = await page.evaluate(() => {
    const t = document.querySelector('.as-tabs');
    const de = document.documentElement;
    return { tabsOverflow: t.scrollWidth - t.clientWidth, pageOverflow: de.scrollWidth - de.clientWidth };
  });
  expect(tabsOverflow).toBeGreaterThan(0);
  expect(pageOverflow).toBe(0);

  // The secondary plan badge is dropped on mobile so the tabs own the bar.
  await expect(page.locator('.as-plan')).toBeHidden();

  // Every tab is still reachable and still navigates.
  await page.getByRole('button', { name: 'Download' }).click();
  await expect(page.getByRole('heading', { name: 'Add AfriStream to Your Device' })).toBeVisible();
});

test('the active tab scrolls to the centre of the bar, clamped at both ends', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');

  const measure = async (id) => page.evaluate((tab) => {
    const strip = document.querySelector('.as-tabs');
    const a = strip.querySelector('[data-act="nav"][data-val="' + tab + '"]');
    const sb = strip.getBoundingClientRect(), r = a.getBoundingClientRect();
    const max = strip.scrollWidth - strip.clientWidth;
    return {
      offCentre: Math.round((r.left + r.width / 2) - (sb.left + sb.width / 2)),
      left: Math.round(strip.scrollLeft),
      max: Math.round(max),
    };
  }, id);

  // A tab with room on both sides lands dead centre. Free Streaming no longer
  // has one (the affiliate-only tab it used to have on its right is gone by
  // design), so it sits with the clamped tabs below instead.
  for (const id of ['watch', 'editor']) {
    await page.locator(`.as-tabs [data-act="nav"][data-val="${id}"]`).click();
    const m = await measure(id);
    expect(Math.abs(m.offCentre), `${id} should be centred`).toBeLessThanOrEqual(1);
  }

  // The first tab stays against the left edge rather than being dragged in.
  await page.locator('.as-tabs [data-act="nav"][data-val="profile"]').click();
  const first = await measure('profile');
  expect(first.left).toBe(0);
  expect(first.offCentre).toBeLessThan(0);

  // ...and the last against the right edge, so there is never dead space
  // beside it. This is the "but not so that the last item scrolls to the
  // centre" half of the behaviour.
  await page.locator('.as-tabs [data-act="nav"][data-val="download"]').click();
  const last = await measure('download');
  expect(last.left).toBe(last.max);
  expect(last.offCentre).toBeGreaterThan(0);

  // Free Streaming sits one tab before Download, but the strip is now narrow
  // enough that centring it would scroll past the end of the range, so it
  // clamps to the same right edge as the last tab rather than landing centred.
  await page.locator('.as-tabs [data-act="nav"][data-val="apps"]').click();
  const apps = await measure('apps');
  expect(apps.left).toBe(apps.max);
  expect(apps.offCentre).toBeGreaterThan(0);
});

test('the tab bar can be dragged with a mouse without navigating', async ({ page }) => {
  // Narrow enough that the bar overflows on desktop too. 760px no longer
  // does — the strip lost the outbound Affiliates anchor it used to carry —
  // so this drops to a width that still overflows by a comfortable margin.
  await page.setViewportSize({ width: 650, height: 800 });
  await page.goto('/');

  const strip = page.locator('.as-tabs');
  await expect(strip).toHaveClass(/as-draggable/);
  expect(await strip.evaluate((el) => getComputedStyle(el).cursor)).toBe('grab');

  const box = await strip.boundingBox();
  const y = box.y + box.height / 2;
  const startX = box.x + box.width - 40;

  await page.mouse.move(startX, y);
  await page.mouse.down();
  for (let dx = 20; dx <= 120; dx += 20) await page.mouse.move(startX - dx, y);
  await page.mouse.up();

  expect(await strip.evaluate((el) => Math.round(el.scrollLeft))).toBeGreaterThan(50);
  // Dragging across a tab must not trigger it.
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
});

test('nav switches to What to Watch with poster rows and sport', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  await expect(page.getByRole('heading', { name: 'What to Watch' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Trending Movies' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Live & Upcoming Sport' })).toBeVisible();
  await expect(page.getByText('Iron Vows')).toBeVisible();
});

test('search narrows the catalog and can be cleared', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  await page.getByPlaceholder('Search titles…').fill('Iron Vows');
  await expect(page.getByText('1 result', { exact: true })).toBeVisible();
  await expect(page.getByText('Iron Vows')).toBeVisible();

  await page.getByRole('button', { name: 'Clear search & filters' }).click();
  await expect(page.getByRole('heading', { name: 'Trending Movies' })).toBeVisible();
});

test('filter drawer filters by type', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  await page.getByRole('button', { name: 'Filters', exact: true }).click();

  const drawer = page.getByTestId('filters-drawer');
  await expect(drawer).toBeVisible();
  await drawer.getByRole('button', { name: 'Series', exact: true }).click();
  await drawer.getByRole('button', { name: /^Show \d+ results?$/ }).click();

  await expect(page.getByText('Harbour House')).toBeVisible();
  await expect(page.getByText('Iron Vows')).not.toBeVisible();
});

// Tips & Tricks and Troubleshooting are hidden from the nav but not deleted, so
// they stay reachable through default_tab and keep working once opened.
test('tips section is hidden from the nav but still reachable via default_tab', async ({ page }) => {
  await expect(page.getByRole('button', { name: 'Tips & Tricks' })).toHaveCount(0);

  await page.setContent(
    '<div class="afristream-portal" data-afristream-portal data-default-tab="tips" data-show-sport="true"></div>' +
    '<script src="/assets/portal.js"></script>'
  );
  await expect(page.getByText('Setup Tips')).toBeVisible();

  await page.getByRole('button', { name: 'Open Troubleshooting' }).click();
  await expect(page.getByRole('heading', { name: 'AfriStream Troubleshooting Guide' })).toBeVisible();
});

test('watch API endpoint responds with a valid source', async ({ request }) => {
  const res = await request.get('/api/watch');
  expect(res.ok()).toBeTruthy();
  const json = await res.json();
  expect(['live', 'fallback']).toContain(json.source);
});

test('watch section consumes API data when the endpoint provides it', async ({ page }) => {
  await page.goto('/preview/fixture.html');

  // Fixture payload replaces the curated movie/series/new-week/sport rows.
  await expect(page.getByText('Fixture Movie One')).toBeVisible();
  await expect(page.getByText('Fixture Series One')).toBeVisible();
  await expect(page.getByText('Fixture New Arrival')).toBeVisible();
  await expect(page.getByText('Listings and artwork from')).toBeVisible();
  await expect(page.getByText('Fixture FC vs Test United')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Live & Upcoming Sport' })).toBeVisible();

  // Search works over the API-provided index.
  await page.getByPlaceholder('Search titles…').fill('Fixture New Arrival');
  await expect(page.getByText('1 result', { exact: true })).toBeVisible();
});

test('watch fixture payload carries a deep catalog with country data', async ({ request }) => {
  const res = await request.get('/api/watch?fixture=1');
  const json = await res.json();
  expect(Array.isArray(json.catalog)).toBeTruthy();
  expect(json.catalog.length).toBeGreaterThan(3);
  const withCountry = json.catalog.find((x) => x.country);
  expect(withCountry).toBeTruthy();
  expect(withCountry).toHaveProperty('type');
});

test('watch fixture items carry a TMDB id for detail lookups', async ({ request }) => {
  const res = await request.get('/api/watch?fixture=1');
  const json = await res.json();
  expect(json.movies[0]).toHaveProperty('id');
  expect(typeof json.movies[0].id).toBe('number');
  expect(json.catalog[0]).toHaveProperty('id');
});

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

test('a trending title also in the catalog inherits its origin country', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Filters', exact: true }).click();
  const drawer = page.getByTestId('filters-drawer');
  // "Fixture Movie One" is a trending row (no country) that the catalog also
  // lists as United States — it should match the United States country facet.
  await drawer.getByRole('button', { name: 'United States', exact: true }).click();
  await drawer.getByRole('button', { name: /^Show \d+ results?$/ }).click();
  await expect(page.getByText('Fixture Movie One')).toBeVisible();
  await expect(page.getByText('Lagos Lights')).not.toBeVisible();
});

test('sport can be filtered by sport type and country', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Filters', exact: true }).click();
  const drawer = page.getByTestId('filters-drawer');
  // Selecting the Sport type relabels the Genre facet to "Sport Type" and lists
  // the sporting codes (target buttons by facet key to avoid the Live TV
  // "Sport" genre tag colliding on the word "Sport").
  await drawer.locator('button[data-key="type"][data-val="Sport"]').click();
  await expect(drawer.getByText('Sport Type', { exact: true })).toBeVisible();
  await expect(drawer.locator('button[data-key="genre"][data-val="Soccer"]')).toBeVisible();
  await expect(drawer.locator('button[data-key="genre"][data-val="Tennis"]')).toBeVisible();
  // Country narrows sport too: Australia keeps the tennis fixture, drops the soccer one.
  await drawer.locator('button[data-key="country"][data-val="Australia"]').click();
  await drawer.getByRole('button', { name: /^Show \d+ results?$/ }).click();
  await expect(page.getByText('A. Player vs B. Player')).toBeVisible();
  await expect(page.getByText('Fixture FC vs Test United')).not.toBeVisible();
});

test('sport fallback includes Cricket, Golf, Rugby and Soccer fixtures', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  const section = page.locator('[data-screen-label="What to Watch"]');
  await expect(section.getByRole('heading', { name: 'Live & Upcoming Sport' })).toBeVisible();
  // The curated fallback now always carries all four requested sports.
  await expect(section.getByText('ICC World Cup')).toBeVisible();
  await expect(section.getByText('PGA Tour')).toBeVisible();
  await expect(section.getByText('URC Rugby')).toBeVisible();
  await expect(section.getByText('Premier League')).toBeVisible();
});

test('collections show real title counts and open a poster grid', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  const section = page.locator('[data-screen-label="What to Watch"]');
  await section.getByRole('button', { name: 'Collections', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Collections' })).toBeVisible();

  // Cards now show a real, non-zero count (no more fake "12 titles" label).
  await expect(section.getByText('True Crime Deep Dive')).toBeVisible();
  await expect(section.getByText(/\d+ titles/).first()).toBeVisible();

  // Opening a collection renders the actual matching posters in the drawer.
  await section.getByText('True Crime Deep Dive').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Jozi Heat')).toBeVisible();
});

test('a collection only contains titles matching its own category', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  const section = page.locator('[data-screen-label="What to Watch"]');
  await section.getByRole('button', { name: 'Collections', exact: true }).click();

  // True Crime is crime and mystery — it must not sweep in every thriller or,
  // as it once did, unrelated documentaries.
  await section.getByText('True Crime Deep Dive').click();
  const genres = await page.evaluate(() =>
    [...document.querySelectorAll('[data-testid="detail-drawer"] [data-act="detail"]')]
      .map((c) => (c.textContent.match(/(Crime|Mystery|Thriller|Documentary|Docs|Drama|Comedy)/) || [])[0])
      .filter(Boolean)
  );
  expect(genres.length).toBeGreaterThan(0);
  expect(genres.every((g) => g === 'Crime' || g === 'Mystery')).toBe(true);
});

test('the Live TV Channels row is gone', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  await expect(page.getByRole('heading', { name: 'Live TV Channels' })).toHaveCount(0);
  // And live channels no longer leak into search results either.
  await page.getByPlaceholder('Search titles…').fill('Sky News');
  await expect(page.getByText('Sky News')).toHaveCount(0);
});

test('picking a category loads more titles than the All summary shows', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  const section = page.locator('[data-screen-label="What to Watch"]');
  const rowCount = () => page.locator('main [data-dragscroll]').first().evaluate((el) => el.children.length);

  await expect(page.getByRole('heading', { name: 'Trending Movies' })).toBeVisible();
  const summary = await rowCount();

  // "Movies" re-backs the row from the whole catalog, not the trending slice.
  await section.getByRole('button', { name: 'Movies', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Movies', exact: true })).toBeVisible();
  expect(await rowCount()).toBeGreaterThan(summary);

  // Going back to All restores the shorter summary row.
  await section.getByRole('button', { name: 'All', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Trending Movies' })).toBeVisible();
  expect(await rowCount()).toBe(summary);
});

test('the detail panel still scrolls when a host ancestor creates a containing block', async ({ page }) => {
  // A transform/filter/will-change on any ancestor makes that ancestor the
  // containing block for position:fixed, which used to stretch the panel to its
  // full content height and leave nothing to scroll.
  await page.goto('/preview/fixture.html');
  await page.setViewportSize({ width: 900, height: 600 });
  await page.evaluate(() => {
    document.querySelector('.afristream-portal').parentElement.style.transform = 'translateZ(0)';
  });

  const section = page.locator('[data-screen-label="What to Watch"]');
  await section.getByRole('button', { name: 'Collections', exact: true }).click();
  await section.getByText('True Crime Deep Dive').click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();

  const metrics = await page.evaluate(() => {
    const panel = document.querySelector('[data-testid="detail-drawer"]');
    const body = panel.querySelector('.as-panel-body');
    body.scrollTop = 9999;
    return {
      panelHeight: Math.round(panel.getBoundingClientRect().height),
      viewport: window.innerHeight,
      scrolled: body.scrollTop,
    };
  });
  expect(metrics.panelHeight).toBeLessThanOrEqual(metrics.viewport);
  expect(metrics.scrolled).toBeGreaterThan(0);
});

test('poster rows drag-scroll with the mouse', async ({ page }) => {
  await page.getByRole('button', { name: 'What to Watch' }).click();
  const row = page.locator('main [data-dragscroll]').first();
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

test('editor-picks fixture endpoint returns ranked picks', async ({ request }) => {
  const res = await request.get('/api/editor-picks?fixture=1');
  expect(res.ok()).toBeTruthy();
  const json = await res.json();
  expect(Array.isArray(json.picks)).toBeTruthy();
  expect(json.picks.length).toBeGreaterThan(1);
  expect(json.picks[0]).toHaveProperty('t');
});

test('editor-picks fixture items carry a TMDB id', async ({ request }) => {
  const res = await request.get('/api/editor-picks?fixture=1');
  const json = await res.json();
  expect(json.picks[0]).toHaveProperty('id');
  expect(typeof json.picks[0].id).toBe('number');
});

test('detail fixture endpoint returns an overview for an id', async ({ request }) => {
  const res = await request.get('/api/detail?fixture=1&id=101&type=movie');
  expect(res.ok()).toBeTruthy();
  const json = await res.json();
  expect(json).toHaveProperty('overview');
  expect(json.overview).toContain('101');
});

test('clicking a poster card opens a detail panel with its details', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Fixture Movie One')).toBeVisible();
  await expect(drawer.getByText('Drama')).toBeVisible();
  await expect(drawer.getByText('★ 8.1')).toBeVisible();
});

test('detail panel loads a synopsis for a title card', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  // Fixture id 101 → canned "Fixture synopsis for 101."
  await expect(drawer.getByText('Fixture synopsis for 101.')).toBeVisible();
});

test('clicking a sport card opens its detail panel', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture FC vs Test United').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Fixture Sports')).toBeVisible();
  await expect(drawer.getByText('Fixture League')).toBeVisible();
});

test('sport cards name the broadcaster and the country it airs in', async ({ page }) => {
  await page.goto('/preview/fixture.html');

  // TheSportsDB supplies broadcasters outside the US, so each listing carries
  // the channel plus the country that channel broadcasts in.
  const card = page.locator('[data-sport-channel]').first();
  await expect(card).toContainText('Fixture Sports');
  await expect(card).toContainText('United Kingdom');

  // The detail panel spells the same thing out as its own row.
  await page.getByText('Fixture FC vs Test United').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer.getByText('Broadcast in')).toBeVisible();
  await expect(drawer.getByText('United Kingdom')).toBeVisible();
});

test('watch endpoint sport rows carry channel and broadcast country', async ({ request }) => {
  const res = await request.get('/api/watch?fixture=1');
  const json = await res.json();
  expect(Array.isArray(json.sport)).toBeTruthy();
  for (const row of json.sport) {
    expect(row.ch).toBeTruthy();
    expect(row.chCountry).toBeTruthy();
  }
});

test('sport listings span more than one broadcast country', async ({ page }) => {
  await page.goto('/preview/fixture.html');

  // The whole point of merging three feeds: a viewer outside the US sees a
  // channel they can actually watch, not just a US network.
  const channels = page.locator('[data-sport-channel]');
  await expect(channels.first()).toBeVisible();
  const countries = new Set(
    (await channels.allInnerTexts()).map((text) => text.split('\n').pop().trim())
  );
  expect(countries.size).toBeGreaterThan(1);
  expect([...countries]).toContain('South Africa');
});

test('the baked sports guide is well-formed and in the plugin payload', async () => {
  // data/sports-listings.json ships inside the plugin zip, so a malformed or
  // stale file is a deployment problem, not just a local one.
  const { readFileSync, existsSync } = await import('node:fs');
  const path = 'data/sports-listings.json';
  test.skip(!existsSync(path), 'no guide baked yet — run npm run sync-listings');

  const guide = JSON.parse(readFileSync(path, 'utf8'));
  expect(guide.source).toBe('iptv-org/epg');
  expect(Array.isArray(guide.listings)).toBeTruthy();
  expect(guide.listings.length).toBeGreaterThan(0);

  for (const row of guide.listings) {
    expect(row.fx, 'every listing names a fixture or programme').toBeTruthy();
    expect(row.ch, 'every listing names a channel').toBeTruthy();
    expect(row.chCountry, 'every listing names a broadcast country').toBeTruthy();
    expect(Number.isNaN(Date.parse(row.iso)), `start time parses: ${row.iso}`).toBeFalsy();
    // A bare episode marker means the parser fell through to the wrong field.
    expect(row.fx).not.toMatch(/^S\d+\/E\d+/i);
  }
});

test('clicking an editor pick card opens its detail panel', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  await page.getByText('Fixture Pick Two').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('Fixture Pick Two')).toBeVisible();
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

test('a title card opens the detail panel via keyboard', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  // The card title text sits inside a nested div — focus() on that text node
  // is a no-op (it isn't focusable), so real keyboard focus lands on the
  // outer [data-act="detail"] card (tabindex="0"). Exercise that baseline
  // path with a real Enter keypress.
  const card = page.locator('[data-act="detail"]').filter({ hasText: 'Fixture Movie One' }).first();
  await card.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByTestId('detail-drawer')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('detail-drawer')).not.toBeVisible();

  // Also cover a keydown that bubbles up from a descendant of the card
  // (e.g. assistive tech dispatching to inner markup) — this is what the
  // handler's closest('[data-act="detail"]') resolution specifically
  // hardens, mirroring the click handler's existing delegation. Dispatched
  // directly since a non-focusable descendant can never itself receive
  // real keyboard focus.
  const title = page.getByText('Fixture Movie One').first();
  await title.evaluate((el) => el.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true })));
  await expect(page.getByTestId('detail-drawer')).toBeVisible();
});

test('detail panel returns focus to the card it was opened from', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('detail-drawer')).not.toBeVisible();
  // Focus returns to the triggering card (its aria-label names the title).
  const label = await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('aria-label'));
  expect(label).toContain('Fixture Movie One');
});

test('detail panel locks background scroll while open and restores on close', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();
  expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).toBe('hidden');
  await page.getByRole('button', { name: 'Close details' }).click();
  await expect(page.getByTestId('detail-drawer')).not.toBeVisible();
  expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).not.toBe('hidden');
});

test('detail panel traps Tab focus within the drawer', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByText('Fixture Movie One').click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();
  const inDrawer = () => page.evaluate(() => !!(document.activeElement && document.activeElement.closest('[data-testid="detail-drawer"]')));
  await page.keyboard.press('Tab');
  expect(await inDrawer()).toBe(true);
  await page.keyboard.press('Shift+Tab');
  expect(await inDrawer()).toBe(true);
});

test('editor picks tab renders a premium hero and ranked grid from fixture', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  await expect(page.getByRole('heading', { name: 'Editor Picks' })).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toBeVisible();
  await expect(page.getByText('Fixture Pick Two')).toBeVisible();
  await expect(page.getByText('Listings and artwork from')).toBeVisible();
});

test('editor picks offers only the three content types, not a genre dump', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  const typeRow = section.getByRole('group', { name: 'Filter by type' });

  await expect(typeRow.getByRole('button')).toHaveText(['All', 'Movies', 'Series', 'Documentaries']);
  // No search box and no Filters button on this tab.
  await expect(section.getByRole('textbox')).toHaveCount(0);
  await expect(section.getByRole('button', { name: 'Filters', exact: true })).toHaveCount(0);

  // A documentary is filed under Documentaries, never also under Movies.
  await typeRow.getByRole('button', { name: 'Movies', exact: true }).click();
  await expect(page.getByText('Fixture Pick One')).toBeVisible();
  await expect(page.getByText('Fixture Pick Four')).toHaveCount(0);

  await typeRow.getByRole('button', { name: 'Documentaries', exact: true }).click();
  await expect(page.getByText('Fixture Pick Four')).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toHaveCount(0);
});

// Fixture genres are Drama, Thriller, Comedy, Documentary and Drama. None of
// them is an Action, Adventure or Animation title outright, so this also proves
// the fallback mapping: the two Dramas answer to Thriller and the Documentary,
// which has no shelf of its own, lands on Adventure.
test('editor picks offers sub-categories alphabetically, with every pick on one', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  const categoryRow = section.getByRole('group', { name: 'Filter by category' });

  await expect(categoryRow.getByRole('button')).toHaveText(['All', 'Adventure', 'Comedy', 'Thriller']);

  // Thriller holds the real thriller and both dramas.
  await categoryRow.getByRole('button', { name: 'Thriller', exact: true }).click();
  await expect(page.getByText('Fixture Pick One')).toBeVisible();
  await expect(page.getByText('Fixture Pick Two')).toBeVisible();
  await expect(page.getByText('Fixture Pick Five')).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);
  await expect(page.getByText('Fixture Pick Four')).toHaveCount(0);

  await categoryRow.getByRole('button', { name: 'Comedy', exact: true }).click();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toHaveCount(0);

  // The documentary has no shelf of its own and falls back rather than vanishing.
  await categoryRow.getByRole('button', { name: 'Adventure', exact: true }).click();
  await expect(page.getByText('Fixture Pick Four')).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);

  await categoryRow.getByRole('button', { name: 'All', exact: true }).click();
  await expect(page.getByText('Fixture Pick One')).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();
});

test('the category and type filters narrow each other', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  const typeRow = section.getByRole('group', { name: 'Filter by type' });
  const categoryRow = section.getByRole('group', { name: 'Filter by category' });

  // Documentaries holds one pick, mapped to Adventure — so that is the only
  // shelf the row may offer once the type is narrowed to it.
  await typeRow.getByRole('button', { name: 'Documentaries', exact: true }).click();
  await expect(categoryRow.getByRole('button')).toHaveText(['All', 'Adventure']);

  // Series holds the Thriller and one Drama, both on the Thriller shelf.
  await typeRow.getByRole('button', { name: 'Series', exact: true }).click();
  await expect(categoryRow.getByRole('button')).toHaveText(['All', 'Thriller']);
  await categoryRow.getByRole('button', { name: 'Thriller', exact: true }).click();
  await expect(page.getByText('Fixture Pick Two')).toBeVisible();
  await expect(page.getByText('Fixture Pick Five')).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);
});

// Bands are exclusive, not thresholds: picking 8 must not drag the 9.1 pick in
// with it. Fixture ratings are 6.4, 7.6, 8.1, 8.5 and 9.1 — one per band, with
// two in the 8s so a band can hold more than one.
test('editor picks filters by exclusive rating band', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  const ratingRow = section.getByRole('group', { name: 'Filter by IMDb rating' });

  await expect(ratingRow.getByRole('button')).toHaveText(['Any', '★ 6–6.9', '★ 7–7.9', '★ 8–8.9', '★ 9+']);

  // The top band stays open-ended, so it holds the 9.1 pick and nothing else.
  await ratingRow.getByRole('button', { name: '★ 9+' }).click();
  await expect(page.getByText('Fixture Pick Four')).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toHaveCount(0);
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);

  // 8–8.9 holds both 8s and excludes the 9.1 above it and the 7.6 below.
  await ratingRow.getByRole('button', { name: '★ 8–8.9' }).click();
  await expect(page.getByText('Fixture Pick One')).toBeVisible();
  await expect(page.getByText('Fixture Pick Two')).toBeVisible();
  await expect(page.getByText('Fixture Pick Four')).toHaveCount(0);
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);

  // 7–7.9 holds only the 7.6, with the 8s excluded.
  await ratingRow.getByRole('button', { name: '★ 7–7.9' }).click();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toHaveCount(0);

  // And the new bottom band holds only the 6.4.
  await ratingRow.getByRole('button', { name: '★ 6–6.9' }).click();
  await expect(page.getByText('Fixture Pick Five')).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);

  await ratingRow.getByRole('button', { name: 'Any' }).click();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();
  await expect(page.getByText('Fixture Pick Four')).toBeVisible();
});

test('editor picks are ordered best-rated first', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  await expect(section.locator('[data-testid="editor-grid"]')).toBeVisible();

  // The hero is lifted out of the grid, so read the ratings off what's left and
  // assert they only ever descend.
  const ratings = await page.evaluate(() => {
    const cards = document.querySelectorAll('[data-testid="editor-grid"] > *');
    return [...cards].map((c) => {
      const m = c.textContent.match(/★\s*([\d.]+)/);
      return m ? Number(m[1]) : null;
    });
  });
  const known = ratings.filter((r) => r !== null);
  expect([...known]).toEqual([...known].sort((a, b) => b - a));
});

test("editor picks lead with Today's Pick, which stays put for the day", async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  await expect(section.getByText(/Today's Pick/i)).toBeVisible();

  const title = () => section.locator('[data-testid="editor-grid"]')
    .evaluate(() => document.querySelector('[data-screen-label="Editor Picks"] [style*="min-height:280px"]').innerText);
  const first = await title();

  // Re-rendering (navigating away and back) must not reshuffle it.
  await page.getByRole('button', { name: 'What to Watch' }).click();
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  expect(await title()).toBe(first);

  // And the hero is not duplicated in the grid below it.
  const heroTitle = first.split('\n').filter(Boolean)[1];
  await expect(section.locator('[data-testid="editor-grid"]').getByText(heroTitle, { exact: true })).toHaveCount(0);
});

test('editor picks tab shows the built-in list when the endpoint is offline', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  await expect(page.getByRole('heading', { name: 'Editor Picks' })).toBeVisible();
  // At least one built-in fallback pick renders.
  await expect(page.locator('[data-screen-label="Editor Picks"]')).toContainText('Curated by');
  await expect(page.getByText('The Colour of Home')).toBeVisible();
});

test('troubleshooting accordion works', async ({ page }) => {
  // Hidden from the nav, so reach it the way a pinned host page would.
  await page.setContent(
    '<div class="afristream-portal" data-afristream-portal data-default-tab="help" data-show-sport="true"></div>' +
    '<script src="/assets/portal.js"></script>'
  );

  // First item is open by default; clicking another swaps the open panel.
  await expect(page.getByText('Do not uninstall your app unless instructed')).toBeVisible();
  await page.getByRole('button', { name: /Restart the App/ }).click();
  await expect(page.getByText('Close the app completely and reopen it.')).toBeVisible();

  // The alternative-app step exposes the Downloader codes.
  await page.getByRole('button', { name: /Try an Alternative App/ }).click();
  await expect(page.getByText('569138')).toBeVisible();
});

test('profile tab shows the logged-in user credentials from the endpoint', async ({ page }) => {
  // fixture.html supplies a credentials endpoint, mirroring the WordPress mount
  // (the plain preview keeps its built-in demo profiles).
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Account', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
  await expect(page.getByText('afri_fixture', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Copy', exact: true }).first().click();
  await expect(page.getByRole('button', { name: 'Copied!' })).toBeVisible();

  // Switching profile tabs swaps to the second license's credentials.
  await page.getByRole('button', { name: 'Profile 2' }).click();
  await expect(page.getByText('afri_fixture_tv')).toBeVisible();
});

test('the preview harness serves the same credentials source words as the plugin', async ({ request }) => {
  // The harness exists to stand in for the WordPress mount, and the front end
  // branches on this exact string — payload.source === 'fallback' is what makes
  // it show "no profile assigned" instead of credentials. When the plugin
  // stopped saying 'acf' and started saying 'assigned', the harness went on
  // serving the old word, so every Profile test was exercising a state
  // production can no longer produce. Read out of both files — through the
  // harness itself, which serves the repo — so the two cannot drift apart again
  // without this failing.
  const plugin = await (await request.get('/bluegroup-project-afristream.php')).text();
  const harness = await (await request.get('/scripts/preview-server.mjs')).text();

  const pluginSource = plugin.match(/'source'\s*=>\s*\$profiles\s*\?\s*'([a-z-]+)'\s*:\s*'([a-z-]+)'/);
  expect(pluginSource, 'the plugin still answers /credentials with a source').not.toBeNull();
  const [, assigned, fallback] = pluginSource;

  const fixtureSource = harness.match(/const CREDENTIALS_FIXTURE = \{\s*source: '([a-z-]+)'/);
  expect(fixtureSource, 'the harness still has a credentials fixture').not.toBeNull();
  expect(fixtureSource[1]).toBe(assigned);

  const emptySource = harness.match(/:\s*\{ source: '([a-z-]+)', profiles: \[\] \}/);
  expect(emptySource, 'the harness still has an empty-credentials answer').not.toBeNull();
  expect(emptySource[1]).toBe(fallback);
});

test('poster grids stay multi-column on a phone inside a padded theme container', async ({ page }) => {
  // A host theme wraps shortcode output in a container with its own gutter, and
  // the portal adds its own on top. That doubled gutter is what used to drop the
  // content box below the poster grids' column threshold, collapsing them to a
  // single full-width poster per row on an ordinary phone.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await page.addStyleTag({ content: 'body{padding:0 24px}' });

  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const grid = page.getByTestId('editor-grid');
  await expect(grid).toBeVisible();

  const cols = await grid.evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
  expect(cols).toBeGreaterThanOrEqual(2);

  // And no grid may spill out of the column it was given.
  const overflow = await page.evaluate(() => {
    let worst = 0;
    document.querySelectorAll('.afristream-portal [style*="grid-template-columns"]').forEach((g) => {
      worst = Math.max(worst, g.getBoundingClientRect().width - g.parentElement.clientWidth);
    });
    return Math.round(worst);
  });
  expect(overflow).toBeLessThanOrEqual(0);
});

test('portal never widens the page past the viewport inside a padded theme container', async ({ page }) => {
  // The full-width rule lifts the host wrapper's width cap so the portal fills
  // the content area. It has to size the border box doing it: on a content-box
  // wrapper, width:100% plus the theme's own padding makes the document wider
  // than the screen, and the phone zooms the whole page out to fit.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await page.addStyleTag({ content: 'body{padding:0 24px}' });

  for (const tab of ['Account', 'Setup', 'What to Watch', 'Editor Picks', 'Free Streaming', 'Download']) {
    await page.getByRole('button', { name: tab, exact: true }).click();
    const overflow = await page.evaluate(() => {
      const de = document.documentElement;
      return de.scrollWidth - de.clientWidth;
    });
    expect(overflow, `${tab} widens the page past the viewport`).toBe(0);
  }
});

test('the filters drawer scroll-locks the page behind it', async ({ page }) => {
  // Both overlays are modal — a fixed panel over a full-viewport scrim — so both
  // have to stop the page scrolling underneath. On a phone an unlocked page is
  // very visible: dragging the filter list scrolls the catalogue behind it.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await page.getByRole('button', { name: 'What to Watch' }).click();

  // Two things this has to get right to be worth anything:
  //   - a real wheel event, not window.scrollBy — overflow:hidden only blocks
  //     user scrolling, so a programmatic scroll sails straight through a
  //     working lock and the test would pass no matter what;
  //   - the pointer over the scrim, not the panel — the panel body already
  //     stops chaining via overscroll-behavior, so wheeling there is green
  //     even with no page lock at all. The scrim is where the gap shows.
  const scrollable = async (x = 195) => {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.mouse.move(x, 400);
    await page.mouse.wheel(0, 400);
    await page.waitForTimeout(250);
    const moved = await page.evaluate(() => window.scrollY > 0);
    await page.evaluate(() => window.scrollTo(0, 0));
    return moved;
  };

  // Midpoint of the scrim strip left of the drawer.
  const overScrim = async () => {
    const left = await page.getByTestId('filters-drawer')
      .evaluate((el) => el.getBoundingClientRect().left);
    return scrollable(Math.max(4, Math.round(left / 2)));
  };

  expect(await scrollable(), 'page should scroll with no drawer open').toBe(true);

  await page.getByRole('button', { name: 'Filters', exact: true }).click();
  await expect(page.getByTestId('filters-drawer')).toBeVisible();
  expect(await overScrim(), 'page must not scroll behind the filters drawer').toBe(false);

  // Closing restores scrolling rather than leaving the page stuck.
  await page.getByTestId('filters-drawer').getByRole('button', { name: 'Done', exact: true }).click();
  await expect(page.getByTestId('filters-drawer')).not.toBeVisible();
  expect(await scrollable(), 'page should scroll again once closed').toBe(true);
});

test('the Affiliates tab is absent for a non-affiliate, not an outbound link', async ({ page }) => {
  // The nav used to carry a permanent outbound link to SureCart for every
  // subscriber. It is gone by design — the tab now appears only once the
  // affiliate payload confirms an active affiliation.
  await expect(page.getByTestId('nav-affiliates')).toHaveCount(0);
  const labels = await page.locator('.as-tabs > *').evaluateAll((els) => els.map((e) => e.textContent.trim()));
  expect(labels.join(' ')).not.toContain('Affiliates');

  // The portal itself stays on Account, unaffected by the nav change.
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
});

// ------------------------------------------------------------ account, setup

test('the nav offers exactly the visible tabs, with tips and troubleshooting hidden', async ({ page }) => {
  const tabs = page.locator('.as-tabs');
  await expect(tabs.getByRole('button')).toHaveText([
    'Account', 'Setup', 'What to Watch', 'Editor Picks', 'Free Streaming', 'Download',
  ]);
  await expect(tabs.getByRole('button', { name: 'Tips & Tricks' })).toHaveCount(0);
  await expect(tabs.getByRole('button', { name: 'Troubleshooting' })).toHaveCount(0);
});

test('the Account tab explains what the credentials do not unlock', async ({ page }) => {
  const notice = page.getByTestId('account-scope-notice');
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('used in conjunction with your Apps used via AfriStream');
  await expect(notice).toContainText('do not provide any access to the Free Streaming Apps provided');

  // It has to be read before the credentials, so it sits above them.
  const noticeY = (await notice.boundingBox()).y;
  const userY = (await page.getByText('Active Username').boundingBox()).y;
  expect(noticeY).toBeLessThan(userY);
});

test('the Account tab spells out the one-screen and same-household rules', async ({ page }) => {
  // Breaking these gets a line deleted automatically, so they belong next to
  // the credentials rather than buried in a support thread.
  const notice = page.getByTestId('account-connections-notice');
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('only on one device at once');
  await expect(notice).toContainText('same household');
  await expect(notice).toContainText('mobile data');

  const noticeY = (await notice.boundingBox()).y;
  const userY = (await page.getByText('Active Username').boundingBox()).y;
  expect(noticeY).toBeLessThan(userY);
});

// Setup is now one tab doing what Setup and Devices used to do between them:
// family -> which one -> install -> app, with the buying advice at step 2.

const openSetup = async (page) => page.getByRole('button', { name: 'Setup' }).click();

test('the Setup tab starts at step 1 with only the family picker', async ({ page }) => {
  await openSetup(page);
  await expect(page.getByRole('heading', { name: 'Set Up AfriStream' })).toBeVisible();

  await expect(page.getByTestId('setup-device-picker')).toBeVisible();
  await expect(page.getByTestId('setup-sub-picker')).toHaveCount(0);
  await expect(page.getByTestId('setup-steps')).toHaveCount(0);
  await expect(page.getByTestId('setup-codes')).toHaveCount(0);

  // Three families, no more — TVs & Sticks, Android Boxes, Android Devices.
  await expect(page.getByTestId('setup-device-picker').getByRole('button')).toHaveText([
    /TVs & Sticks/, /Android Boxes/, /Android Devices/,
  ]);
});

test('the Devices tab is gone, folded into Setup', async ({ page }) => {
  await expect(page.locator('.as-tabs').getByRole('button', { name: 'Devices' })).toHaveCount(0);
  await openSetup(page);
  // Its buying advice now lives at step 2 rather than on a tab of its own.
  await page.getByRole('button', { name: /TVs & Sticks/ }).click();
  await expect(page.getByTestId('setup-buying')).toBeVisible();
});

test('step 2 offers the sub-devices with the three recommended sticks flagged', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /TVs & Sticks/ }).click();

  const picker = page.getByTestId('setup-sub-picker');
  await expect(picker).toBeVisible();
  await expect(picker.getByRole('button')).toHaveCount(4);
  // Three sticks carry a Recommended badge; the bare Smart TV does not.
  await expect(picker.getByRole('button', { name: /Recommended/ })).toHaveCount(3);
  await expect(picker.getByRole('button', { name: /Smart TV, no stick/ })).not.toContainText('Recommended');

  // Steps stay hidden until a sub-device is picked.
  await expect(page.getByTestId('setup-steps')).toHaveCount(0);
});

test('step 2 carries the buying advice and the WiFi guidance', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android Boxes/ }).click();

  const buying = page.getByTestId('setup-buying');
  await expect(buying).toBeVisible();
  await expect(buying).toContainText('Android TV or Google TV');
  await expect(buying).toContainText('memory');

  // Almost everyone watches over WiFi, so that advice is in the flow — in
  // plain words, since the people reading it are not technical.
  const wifi = page.getByTestId('setup-wifi');
  await expect(wifi).toBeVisible();
  await expect(wifi).toContainText('ending in 5G');
  await expect(wifi).toContainText('Walls');
});

test('sticks and boxes link straight to something you can buy', async ({ page }) => {
  for (const [family, expected] of [[/TVs & Sticks/, 2], [/Android Boxes/, 2]]) {
    await page.goto('/');
    await openSetup(page);
    await page.getByRole('button', { name: family }).click();

    const links = page.getByTestId('setup-buy-links').locator('[data-buy-link]');
    await expect(links).toHaveCount(expected);

    for (let i = 0; i < expected; i++) {
      const href = await links.nth(i).getAttribute('href');
      // Real South African retailers, and safe to open in a new tab.
      expect(href).toMatch(/^https:\/\/(www\.takealot\.com|www\.amazon\.co\.za)\//);
      await expect(links.nth(i)).toHaveAttribute('target', '_blank');
      await expect(links.nth(i)).toHaveAttribute('rel', /noopener/);
    }
  }

  // Phones and tablets are something people already own — nothing to sell them.
  await page.goto('/');
  await openSetup(page);
  await page.getByRole('button', { name: /Android Devices/ }).click();
  await expect(page.getByTestId('setup-buy-links')).toHaveCount(0);
});

test('a Fire TV stick gets the Firesend route and both codes', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /TVs & Sticks/ }).click();
  await page.getByRole('button', { name: /Amazon Fire TV Stick/ }).click();

  const steps = page.getByTestId('setup-steps');
  await expect(steps.getByRole('heading', { name: 'Turn on Developer Options' })).toBeVisible();
  await expect(steps.getByRole('heading', { name: 'Install Firesend and unlock the app list' })).toBeVisible();
  await expect(steps.getByRole('heading', { name: 'Install your app and sign in' })).toBeVisible();

  // The room code is called out; the app code comes from the list below.
  await expect(steps.locator('[data-setup-code]')).toHaveText(['10325']);
  await expect(steps.locator('[data-setup-note]').first()).toContainText('seven times');

  // Amazon's newest sticks cannot install our app at all — that has to be
  // said before someone buys the wrong one, in words a customer understands.
  await expect(page.getByTestId('setup-buy-warning')).toContainText('4K Max');
});

test('a Google TV stick skips Firesend and goes straight to Downloader', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /TVs & Sticks/ }).click();
  await page.getByRole('button', { name: /Xiaomi TV Stick/ }).click();

  const steps = page.getByTestId('setup-steps');
  await expect(steps).toContainText('Downloader');
  await expect(steps).not.toContainText('Firesend');
  await expect(steps.getByRole('heading', { name: 'Get Downloader' })).toBeVisible();
});

test('an Android phone installs from the browser, not a store or Downloader', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android Devices/ }).click();
  await page.getByRole('button', { name: /Android Phone/ }).click();

  const steps = page.getByTestId('setup-steps');
  await expect(steps).toContainText('web browser');
  await expect(steps).not.toContainText('Downloader');
  // The app list gives addresses rather than bare codes on this route.
  await expect(page.getByTestId('setup-codes')).toContainText('aftv.news/617725');
});

test('a bare Smart TV is told it has to be registered by us', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /TVs & Sticks/ }).click();
  await page.getByRole('button', { name: /Smart TV, no stick/ }).click();

  const steps = page.getByTestId('setup-steps');
  await expect(steps).toContainText('MAC address');
  await expect(steps).toContainText('support@afristream.io');
  // No Downloader codes on this route — the apps come from the TV's own store.
  await expect(page.getByTestId('setup-codes')).not.toContainText('617725');
  await expect(page.getByTestId('setup-codes')).toContainText('IBO Player');
});

test('the app list always offers three apps to fall back through', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android Boxes/ }).click();
  await page.getByRole('button', { name: /Google TV box/ }).click();

  const codes = page.getByTestId('setup-codes');
  await expect(codes.locator('[data-setup-code-row]')).toHaveCount(3);
  await expect(codes).toContainText('617725');
  await expect(codes).toContainText('9469460');
  await expect(codes).toContainText('6573365');
  await expect(codes).toContainText('IBO Player');
});

test('the progress rail tracks the flow and walks back through it', async ({ page }) => {
  await openSetup(page);
  const rail = page.getByTestId('setup-rail');
  await expect(rail.getByRole('listitem')).toHaveCount(3);

  await page.getByRole('button', { name: /TVs & Sticks/ }).click();
  await page.getByRole('button', { name: /Xiaomi TV Stick/ }).click();
  await expect(page.getByTestId('setup-steps')).toBeVisible();
  await expect(page.getByTestId('setup-chosen')).toContainText('Xiaomi TV Stick 4K');

  // Back to step 2 keeps the family, drops the model.
  await rail.getByRole('button', { name: 'Back to Which one' }).click();
  await expect(page.getByTestId('setup-sub-picker')).toBeVisible();
  await expect(page.getByTestId('setup-chosen')).toContainText('TVs & Sticks');

  // Start again clears both.
  await page.getByRole('button', { name: 'Start again' }).click();
  await expect(page.getByTestId('setup-device-picker')).toBeVisible();
  await expect(page.getByTestId('setup-chosen')).toHaveCount(0);
});

test('Setup explains why a device is needed, and only at step 1', async ({ page }) => {
  await openSetup(page);
  const why = page.getByTestId('why-a-device');
  await expect(why).toBeVisible();
  await expect(why).toContainText('a login, not a box');
  await expect(why).toContainText('player app');
  await expect(why).toContainText('has to run on something');

  // Once you are in the flow the rationale is just clutter.
  await page.getByRole('button', { name: /Android Devices/ }).click();
  await expect(page.getByTestId('why-a-device')).toHaveCount(0);
});

test('the flow is three steps, with the app list folded into the last one', async ({ page }) => {
  await openSetup(page);
  await expect(page.getByTestId('setup-rail').getByRole('listitem')).toHaveCount(3);

  await page.getByRole('button', { name: /Android Devices/ }).click();
  await page.getByRole('button', { name: /Android Phone/ }).click();

  // One current step, and choosing an app sits inside it rather than being a
  // step of its own — it is part of installing, not separate from it.
  const current = page.getByTestId('setup-rail').locator('[data-rail-current="1"]');
  await expect(current).toHaveCount(1);
  await expect(current).toContainText('Install it');

  await expect(page.locator('[data-setup-step="3"]')).toContainText('Step 3 of 3');
  await expect(page.locator('[data-setup-step="4"]')).toHaveCount(0);
  await expect(page.locator('[data-setup-step="3"]').getByTestId('setup-apps')).toBeVisible();
});

test('the progress rail sticks below the top bar while the steps scroll', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await openSetup(page);
  await page.getByRole('button', { name: /TVs & Sticks/ }).click();
  await page.getByRole('button', { name: /Amazon Fire TV Stick/ }).click();

  await page.evaluate(() => window.scrollTo(0, 1400));
  const geom = await page.evaluate(() => {
    const rail = document.querySelector('.as-setup-rail');
    const header = document.querySelector('.afristream-portal header');
    const cs = getComputedStyle(rail);
    return {
      gapUnderHeader: Math.round(rail.getBoundingClientRect().top - header.getBoundingClientRect().bottom),
      paddingTop: cs.paddingTop,
      paddingBottom: cs.paddingBottom,
      opaque: cs.backgroundColor,
    };
  });

  expect(geom.gapUnderHeader).toBeLessThanOrEqual(1);
  // Even space above and below, and opaque so the steps do not show through.
  expect(geom.paddingTop).toBe(geom.paddingBottom);
  expect(geom.opaque).not.toBe('rgba(0, 0, 0, 0)');
});

test('the setup flow links back to the Account tab for the login details', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android Devices/ }).click();
  await page.getByRole('button', { name: /Android Tablet/ }).click();

  await page.getByRole('button', { name: 'Open Account' }).click();
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
});

test('the Download tab covers both mobile platforms', async ({ page }) => {
  await page.getByRole('button', { name: 'Download' }).click();
  await expect(page.getByRole('heading', { name: 'Add AfriStream to Your Device' })).toBeVisible();

  const ios = page.getByTestId('download-ios');
  await expect(ios).toContainText('Safari');
  await expect(ios).toContainText('Add to Home Screen');

  const android = page.getByTestId('download-android');
  await expect(android).toContainText('Chrome');
  await expect(android).toContainText('Add to Home screen');

  // It points at Setup for the thing it is not: installing the streaming app.
  await expect(page.locator('[data-screen-label="Download"]')).toContainText('Setup');
});

// ---------------------------------------------------------------- apps data

const APP_COSTS = ['free', 'free-tier'];
// Movies, Series and Sport only — Live TV and Documentaries were retired, and
// the directory is capped at four apps per category.
const APP_CONTENT_VALUES = ['Movies', 'Series', 'Sport'];
const APP_CONTENT_CAP = 4;
const APP_DEVICE_KEYS = ['smart-tv', 'consoles', 'sticks', 'tablets', 'phones'];

test('apps database is served and every entry matches the schema', async ({ request }) => {
  const res = await request.get('/data/apps.json');
  expect(res.ok()).toBeTruthy();

  const payload = await res.json();
  expect(payload.updated).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(Array.isArray(payload.apps)).toBe(true);
  expect(payload.apps.length).toBeGreaterThan(0);

  const ids = new Set();
  for (const app of payload.apps) {
    const where = `app "${app.id || app.name}"`;
    expect(app.id, where).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/);
    expect(ids.has(app.id), `${where} has a duplicate id`).toBe(false);
    ids.add(app.id);

    expect(typeof app.name, where).toBe('string');
    expect(app.name.length, where).toBeGreaterThan(0);
    expect(APP_COSTS, where).toContain(app.cost);
    expect(app.blurb.length, where).toBeGreaterThan(20);
    expect(app.url, where).toMatch(/^https:\/\//);
    expect(typeof app.availability, where).toBe('string');
    expect(app.availability.length, where).toBeGreaterThan(0);

    expect(app.content.length, where).toBeGreaterThan(0);
    for (const c of app.content) expect(APP_CONTENT_VALUES, where).toContain(c);

    expect(app.devices.length, where).toBeGreaterThan(0);
    for (const d of app.devices) expect(APP_DEVICE_KEYS, where).toContain(d);

    // The region filter is gone; `availability` is now the only place regional
    // scope is stated, so a leftover regions array is dead weight.
    expect(app.regions, `${where} still carries a retired regions array`).toBeUndefined();

    for (const key of Object.keys(app.install || {})) {
      expect(APP_DEVICE_KEYS, `${where} install key`).toContain(key);
      expect(app.devices, `${where} installs on a device it does not list`).toContain(key);
    }
  }
});

test('apps database covers every content type and every device class', async ({ request }) => {
  const { apps } = await (await request.get('/data/apps.json')).json();
  for (const c of APP_CONTENT_VALUES) {
    expect(apps.some((a) => a.content.includes(c)), `no app carries ${c}`).toBe(true);
  }
  for (const d of APP_DEVICE_KEYS) {
    expect(apps.some((a) => a.devices.includes(d)), `no app installs on ${d}`).toBe(true);
  }
});

test('no content category carries more than four apps', async ({ request }) => {
  const { apps } = await (await request.get('/data/apps.json')).json();
  for (const c of APP_CONTENT_VALUES) {
    const inCategory = apps.filter((a) => a.content.includes(c));
    expect(inCategory.length, `${c} carries ${inCategory.length} apps`).toBeLessThanOrEqual(APP_CONTENT_CAP);
  }
});

test('the Apps tab renders the app grid from the database', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Free Streaming' })).toBeVisible();

  const grid = page.getByTestId('apps-grid');
  await expect(grid).toBeVisible();

  const { apps } = await (await page.request.get('/data/apps.json')).json();
  await expect(grid.locator('[data-app-id]')).toHaveCount(apps.length);
  await expect(grid.locator('[data-app-id="tubi"]')).toBeVisible();
});

test('the Apps tab reports a failed database load instead of rendering an empty grid', async ({ page }) => {
  await page.route('**/data/apps.json', (route) => route.fulfill({ status: 500, body: 'boom' }));
  await page.goto('/');
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();

  await expect(page.getByTestId('apps-error')).toBeVisible();
  await expect(page.getByTestId('apps-grid')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
});

test('the content filter narrows the app grid to that category', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  const grid = page.getByTestId('apps-grid');
  await expect(grid).toBeVisible();

  await page.getByTestId('apps-content-filters').getByRole('button', { name: 'Sport', exact: true }).click();

  const { apps } = await (await page.request.get('/data/apps.json')).json();
  const expected = apps.filter((a) => a.content.includes('Sport'));
  expect(expected.length).toBeGreaterThan(0);
  await expect(grid.locator('[data-app-id]')).toHaveCount(expected.length);
  for (const a of expected) await expect(grid.locator(`[data-app-id="${a.id}"]`)).toBeVisible();
});

test('the region and device controls are both gone', async ({ page }) => {
  // Category is the only filter left. Devices are still shown on each card and
  // stepped through in the drawer — picking one is the Setup tab's job.
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await expect(page.getByTestId('apps-grid')).toBeVisible();
  await expect(page.getByLabel('Region')).toHaveCount(0);
  await expect(page.getByTestId('apps-device-filters')).toHaveCount(0);
});

test('the content filters offer only Movies, Series and Sport', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await expect(page.getByTestId('apps-content-filters').getByRole('button'))
    .toHaveText(['All', 'Movies', 'Series', 'Sport']);
});

test('a category with nothing behind it shows an empty state that resets', async ({ page }) => {
  // Every shipped category has four apps, so the empty state is only reachable
  // against a stub. This one carries Sport alone, leaving Movies with nothing.
  const stub = {
    updated: '2026-07-22',
    apps: [{
      id: 'stub-sport',
      name: 'Stub Sport',
      cost: 'free',
      blurb: 'A stub entry that exists only to leave one category empty.',
      content: ['Sport'],
      devices: ['phones'],
      availability: 'Nowhere - this is a test fixture',
      url: 'https://example.com',
    }],
  };
  await page.route('**/data/apps.json', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(stub) }));
  await page.goto('/');

  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await page.getByTestId('apps-content-filters').getByRole('button', { name: 'Movies', exact: true }).click();

  const empty = page.getByTestId('apps-empty');
  await expect(empty).toBeVisible();
  await expect(empty).toContainText('Movies');
  await expect(page.getByTestId('apps-grid')).toHaveCount(0);

  await page.getByRole('button', { name: 'Reset filters' }).click();
  await expect(page.getByTestId('apps-grid').locator('[data-app-id]')).toHaveCount(stub.apps.length);
});

test('the active content pill is marked pressed for assistive tech', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  const pills = page.getByTestId('apps-content-filters');
  await expect(pills.getByRole('button', { name: 'All', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await pills.getByRole('button', { name: 'Series', exact: true }).click();
  await expect(pills.getByRole('button', { name: 'Series', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(pills.getByRole('button', { name: 'All', exact: true })).toHaveAttribute('aria-pressed', 'false');
});

test('app cards show cost, blurb, content and devices', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  const card = page.getByTestId('apps-grid').locator('[data-app-id="tubi"]');
  await expect(card).toBeVisible();

  const { apps } = await (await page.request.get('/data/apps.json')).json();
  const tubi = apps.find((a) => a.id === 'tubi');

  await expect(card).toContainText('Tubi');
  await expect(card).toContainText(tubi.blurb);
  await expect(card).toContainText(tubi.cost === 'free' ? 'Free' : 'Free tier');
  for (const c of tubi.content) await expect(card).toContainText(c);
});

test('clicking an app card opens a drawer with install steps and an official link', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await page.getByTestId('apps-grid').locator('[data-app-id="tubi"]').click();

  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();

  const { apps } = await (await page.request.get('/data/apps.json')).json();
  const tubi = apps.find((a) => a.id === 'tubi');

  await expect(drawer).toContainText(tubi.blurb);
  await expect(drawer).toContainText(tubi.availability);
  await expect(drawer).toContainText('Install on');
  await expect(drawer.getByRole('link', { name: /Open / })).toHaveAttribute('href', tubi.url);
  await expect(drawer.getByRole('link', { name: /Open / })).toHaveAttribute('rel', /noopener/);
});

test('the app drawer lists a step for every device the app supports', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await page.getByTestId('apps-grid').locator('[data-app-id="tubi"]').click();

  const { apps } = await (await page.request.get('/data/apps.json')).json();
  const tubi = apps.find((a) => a.id === 'tubi');
  const steps = page.getByTestId('detail-drawer').locator('[data-install-device]');
  await expect(steps).toHaveCount(tubi.devices.length);
});

test('the app drawer shows an install override where one exists and the default elsewhere', async ({ page }) => {
  const { apps } = await (await page.request.get('/data/apps.json')).json();
  const app = apps.find((a) => a.id === 'red-bull-tv');
  // Guards the fixture: this test only means something while red-bull-tv overrides
  // exactly one device and leaves at least one on the shared default.
  expect(Object.keys(app.install || {})).toEqual(['consoles']);
  const plain = app.devices.filter((d) => d !== 'consoles');
  expect(plain.length).toBeGreaterThan(0);

  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await page.getByTestId('apps-grid').locator('[data-app-id="red-bull-tv"]').click();
  const drawer = page.getByTestId('detail-drawer');
  await expect(drawer).toBeVisible();

  // The overridden device shows the app's own wording...
  await expect(drawer.locator('[data-install-device="consoles"]')).toContainText(app.install.consoles);
  // ...and no other device borrows it.
  for (const d of plain) {
    await expect(drawer.locator(`[data-install-device="${d}"]`)).not.toContainText(app.install.consoles);
  }
  // A non-overridden device falls back to APP_INSTALL_DEFAULTS.
  await expect(drawer.locator('[data-install-device="phones"]')).toContainText('App Store');
});

test('the app drawer never requests a TMDB synopsis', async ({ page }) => {
  const detailCalls = [];
  page.on('request', (r) => { if (r.url().includes('/api/detail')) detailCalls.push(r.url()); });

  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  await page.getByTestId('apps-grid').locator('[data-app-id="tubi"]').click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();

  expect(detailCalls).toEqual([]);
});

test('the app drawer closes on Escape and returns focus to its card', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  const card = page.getByTestId('apps-grid').locator('[data-app-id="tubi"]');
  await card.click();
  await expect(page.getByTestId('detail-drawer')).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(page.getByTestId('detail-drawer')).toHaveCount(0);
  await expect(card).toBeFocused();
});

test('an app card opens its drawer from the keyboard', async ({ page }) => {
  await page.getByRole('button', { name: 'Free Streaming', exact: true }).click();
  const card = page.getByTestId('apps-grid').locator('[data-app-id="plex"]');
  await card.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByTestId('detail-drawer')).toContainText('Plex');
});

test('the Apps tab loads on its own when the portal boots straight into it', async ({ page }) => {
  // default_tab="apps" has its own load-at-mount trigger, separate from the
  // nav-click path every other apps test exercises — mount a portal root
  // pre-set to it, with no click on the Apps nav button anywhere in this test.
  await page.setContent(
    '<div class="afristream-portal" data-afristream-portal data-default-tab="apps" data-show-sport="true" data-apps-url="/data/apps.json"></div>' +
    '<script src="/assets/portal.js"></script>'
  );

  const grid = page.getByTestId('apps-grid');
  await expect(grid).toBeVisible();

  const { apps } = await (await page.request.get('/data/apps.json')).json();
  await expect(grid.locator('[data-app-id]')).toHaveCount(apps.length);
});

test('the portal stays inside the viewport when a theme nests it several levels deep', async ({ page }) => {
  // The full-width rule has to reach past the portal's immediate parent. Dashboard
  // shells (SureCart's customer dashboard, say) wrap shortcode output in several
  // containers, each with its own gutter. Fixing only the direct parent leaves
  // every wrapper above it adding padding on top of width:100% — which is what
  // pushes the document past the screen and makes a phone zoom the whole page out.
  // The flex wrapper covers the other half of it: a flex item defaults to
  // min-width:auto and refuses to shrink below its content.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(
    '<link rel="stylesheet" href="/assets/portal.css">' +
    '<div style="box-sizing:content-box;width:100%;padding:0 20px">' +
      '<div style="box-sizing:content-box;width:100%;padding:0 16px;display:flex">' +
        '<div class="dashboard-right" style="box-sizing:content-box;width:100%;padding:0 12px">' +
          '<div class="afristream-portal" data-afristream-portal data-default-tab="profile" data-show-sport="true"></div>' +
        '</div>' +
      '</div>' +
    '</div>' +
    '<script src="/assets/portal.js"></script>'
  );

  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();

  const overflow = await page.evaluate(() => {
    const de = document.documentElement;
    return de.scrollWidth - de.clientWidth;
  });
  expect(overflow, 'a nested theme wrapper widens the page past the viewport').toBe(0);
});

test('Editor Picks keeps filling in while the server reports a partial list', async ({ page }) => {
  // Resolving the watchlist through TMDB is time-budgeted server-side, so a cold
  // cache answers with only part of the list and flags it `partial`. The front end
  // has to keep asking — otherwise the visitor is stranded on the short version,
  // which is what made a 130-title watchlist show as 60.
  const pick = (i) => ({
    t: `Pick ${i + 1}`, id: 100 + i, genre: 'Drama', rating: 8, rank: i + 1,
    type: 'Movies', poster: null, meta: '2024', platform: '★ 8.0', country: '',
  });
  let calls = 0;
  await page.route('**/api/editor-picks*', async (route) => {
    calls += 1;
    const partial = calls === 1;
    await route.fulfill({
      json: {
        source: 'imdb',
        partial,
        picks: Array.from({ length: partial ? 3 : 7 }, (_, i) => pick(i)),
      },
    });
  });

  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();

  // Today's Pick is lifted out of the list, so the grid carries the remainder.
  const cards = page.getByTestId('editor-grid').locator('.as-editor-card');
  await expect(cards).toHaveCount(2);
  await expect(cards).toHaveCount(6, { timeout: 20000 });
  expect(calls).toBeGreaterThan(1);
});

test('the baked Editor Picks list is complete, well-formed and in the plugin payload', async ({ request }) => {
  // data/editor-picks.json is the watchlist already resolved through TMDB at
  // build time — the whole point being that serving it costs a file read rather
  // than one round-trip per title. It ships inside the plugin, so a malformed or
  // truncated bake is a deploy problem, not a runtime one.
  const ids = await (await request.get('/data/editor-picks-ids.txt')).text();
  const idCount = (ids.match(/tt\d+/g) || []).length;
  expect(idCount).toBeGreaterThan(0);

  const res = await request.get('/data/editor-picks.json');
  expect(res.ok()).toBeTruthy();
  const json = await res.json();

  expect(json.source).toBe('imdb');
  expect(Array.isArray(json.picks)).toBeTruthy();
  // Every ID should resolve; allow a small margin for a title TMDB genuinely
  // does not carry, but not for a run that quietly stopped part-way.
  expect(json.picks.length).toBeGreaterThanOrEqual(Math.floor(idCount * 0.9));

  for (const p of json.picks) {
    expect(typeof p.t).toBe('string');
    expect(p.t.length).toBeGreaterThan(0);
    expect(typeof p.id).toBe('number');
    expect(['Movies', 'Series']).toContain(p.type);
    if (p.rating !== null) {
      expect(p.rating).toBeGreaterThan(0);
      expect(p.rating).toBeLessThanOrEqual(10);
    }
  }
  // Ranks are gap-free and in order — the front end renders them as badges.
  expect(json.picks.map((p) => p.rank)).toEqual(json.picks.map((_, i) => i + 1));
  // A baked payload is complete by definition, so it must never ask the front
  // end to poll for more.
  expect(json.partial).toBeUndefined();
});

test('the watchlist sync only ever adds titles', () => {
  // IMDb shows at most 250 rows of a public watchlist, so a scrape is a window
  // onto the list. Replacing the file with that window is what silently dropped
  // 120 titles; merging has to keep everything outside it.
  const existing = parseEntries('tt0000001 8.0\ntt0000002 7.5\ntt0000003');

  // A scrape that sees only part of the list drops nothing.
  const partial = mergeEntries(existing, [{ id: 'tt0000002', rating: '7.5' }]);
  expect(partial.entries.map((e) => e.id)).toEqual(['tt0000001', 'tt0000002', 'tt0000003']);
  expect(partial.added).toBe(0);

  // An empty scrape is a no-op, not an erasure.
  expect(mergeEntries(existing, []).entries).toHaveLength(3);

  // New titles are appended, and existing order is preserved.
  const grown = mergeEntries(existing, [{ id: 'tt0000009', rating: '9.1' }]);
  expect(grown.entries.map((e) => e.id)).toEqual(['tt0000001', 'tt0000002', 'tt0000003', 'tt0000009']);
  expect(grown.added).toBe(1);
  expect(grown.entries[3].rating).toBe(9.1);

  // A changed rating is taken; a missing one never wipes the rating we hold,
  // because IMDb hides the score on some rows and that is not a change.
  const rerated = mergeEntries(existing, [
    { id: 'tt0000001', rating: '8.4' },
    { id: 'tt0000002', rating: null },
  ]);
  expect(rerated.entries[0].rating).toBe(8.4);
  expect(rerated.entries[1].rating).toBe(7.5);
  expect(rerated.updated).toBe(1);
});

test('every baked pick carries the IMDb id its resolve cache is keyed on', async ({ request }) => {
  // Without this a re-bake cannot tell which titles it has already resolved and
  // re-fetches the whole list from TMDB to add a handful of new films.
  const json = await (await request.get('/data/editor-picks.json')).json();
  for (const p of json.picks) {
    expect(p.imdb, `pick "${p.t}" has no imdb id`).toMatch(/^tt\d+$/);
  }
  // And the ids are unique, or the cache would collapse entries together.
  const ids = json.picks.map((p) => p.imdb);
  expect(new Set(ids).size).toBe(ids.length);
});

test('the Apps tab shows the unavailable notice when no apps URL is configured', async ({ page }) => {
  // No data-apps-url at all (an older host page) — must show the dedicated
  // "unavailable" notice, not the error state and not an empty grid, and the
  // rest of the portal must still work.
  await page.setContent(
    '<div class="afristream-portal" data-afristream-portal data-default-tab="apps" data-show-sport="true"></div>' +
    '<script src="/assets/portal.js"></script>'
  );

  await expect(page.getByTestId('apps-unavailable')).toBeVisible();
  await expect(page.getByTestId('apps-error')).toHaveCount(0);
  await expect(page.getByTestId('apps-grid')).toHaveCount(0);

  await page.getByRole('button', { name: 'Account', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
});

test('the affiliate tab is hidden from anyone who is not an affiliate', async ({ page }) => {
  await page.goto('/');
  // The payload says affiliate:false, so the nav must not carry the tab at all.
  await expect(page.getByRole('button', { name: 'Affiliates' })).toHaveCount(0);
  await expect(page.getByTestId('nav-affiliate')).toHaveCount(0);
});

test('a non-affiliate deep-linked to the affiliate tab lands on Account', async ({ page }) => {
  await page.goto('/preview/affiliate.html?fixture=0');
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
  await expect(page.getByTestId('nav-affiliate')).toHaveCount(0);
});

test('an affiliate gets the tab, their referral link and their rate', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  await expect(page.getByTestId('nav-affiliate')).toBeVisible();
  await expect(page.getByTestId('affiliate-card')).toBeVisible();
  await expect(page.getByTestId('affiliate-referral')).toHaveText('https://afristream.io/?ref=FIXTURE1');
  await expect(page.getByTestId('affiliate-rate')).toHaveText('You earn 30% of every payment, for as long as they stay subscribed.');
  await expect(page.getByTestId('affiliate-portal-link')).toHaveAttribute('href', 'https://afristream.surecart.com/affiliates/');
  await expect(page.getByTestId('affiliate-portal-link')).toHaveText('Open Dashboard ↗');

  // First of the three copy buttons on the card — the referral link's own.
  await page.getByRole('button', { name: 'Copy link' }).first().click();
  await expect(page.getByRole('button', { name: 'Copied!' })).toBeVisible();
});

test('the heading and the dashboard button share a line', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 900 });
  await page.goto('/preview/affiliate.html');

  const heading = await page.getByRole('heading', { name: 'Your affiliate dashboard' }).boundingBox();
  const button = await page.getByTestId('affiliate-portal-link').boundingBox();
  // Side by side: the button starts to the right of the heading, not under it.
  expect(button.x).toBeGreaterThan(heading.x + heading.width);
  expect(button.y).toBeLessThan(heading.y + heading.height + button.height);
});

test('on a narrow screen the dashboard button drops under the text', async ({ page }) => {
  await page.setViewportSize({ width: 380, height: 900 });
  await page.goto('/preview/affiliate.html');

  const rate = await page.getByTestId('affiliate-rate').boundingBox();
  const button = await page.getByTestId('affiliate-portal-link').boundingBox();
  expect(button.y).toBeGreaterThanOrEqual(rate.y + rate.height);
});

test('the buy links sit under the referral link carrying the referral code', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  const subscription = 'https://afristream.io/checkout/?line_items%5B0%5D%5Bprice_id%5D=e204f70c-35dc-498c-b2b4-e850e6d84ac8&line_items%5B0%5D%5Bquantity%5D=1&ref=FIXTURE1';
  const setup = 'https://afristream.io/checkout/?line_items%5B0%5D%5Bprice_id%5D=8b2a7b7a-cf23-4f96-97f6-47acfe925412&line_items%5B0%5D%5Bquantity%5D=1&ref=FIXTURE1';

  // Shown shortened so it can be read at a glance, but the link itself — and
  // what the copy button hands over — is the full, exact URL.
  await expect(page.getByTestId('affiliate-buy-subscription')).toHaveAttribute('href', subscription);
  await expect(page.getByTestId('affiliate-buy-subscription-setup')).toHaveAttribute('href', setup);
  await expect(page.getByTestId('affiliate-buy-subscription')).toHaveText('afristream.io/checkout/?…&ref=FIXTURE1');
  await expect(page.getByTestId('affiliate-buy-subscription-setup')).toHaveText('afristream.io/checkout/?…&ref=FIXTURE1');

  const buys = page.getByTestId('affiliate-buy-links');
  await expect(buys).toContainText('AfriStream Subscription (For users that have their own device)');
  await expect(buys).toContainText('AfriStream Subscription & Setup (For users that need us to buy a device for them)');

  const referralBox = await page.getByTestId('affiliate-referral').boundingBox();
  const buysBox = await buys.boundingBox();
  expect(buysBox.y).toBeGreaterThan(referralBox.y);

  // Three copy buttons on the card: referral, then the two buy links. Copying
  // a buy link turns that one — and only that one — into "Copied!".
  const copyButtons = page.getByRole('button', { name: 'Copy link' });
  await expect(copyButtons).toHaveCount(3);
  await copyButtons.nth(1).click();
  await expect(page.getByRole('button', { name: 'Copied!' })).toHaveCount(1);
  await expect(copyButtons).toHaveCount(2);
});
// Subscriptions are annual. £120 a year at 30% is £36 a renewal. Sign up five
// people a year and every one of them renews: year 1 is five payments (£180),
// year 10 is those five plus nine more years' worth still paying — 50
// payments, £1,800 — and the decade together is 36 × 5 × (1+2+…+10) = £9,900.
test('the calculator projects ten years of stacking recurring commission', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  await expect(page.getByTestId('affiliate-calculator')).toBeVisible();
  await page.getByTestId('aff-currency').selectOption('gbp');
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£36.00');
  await expect(page.getByTestId('aff-year-1')).toHaveText('£180.00');
  await expect(page.getByTestId('aff-year-10')).toHaveText('£1,800.00');
  await expect(page.getByTestId('aff-total')).toHaveText('£9,900.00');
  await expect(page.getByTestId('aff-chart').locator('[data-point]')).toHaveCount(10);
});

test('only annual plans are offered, and the headcount moves every figure', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  // The fixture store sells a monthly plan too; an annual projection cannot
  // honestly model it, so it is not in the picker.
  const plan = page.getByTestId('aff-plan');
  await expect(plan.locator('option')).toHaveCount(1);
  await expect(plan).toHaveValue('price_year');

  // Ten a year instead of five doubles every figure.
  await page.getByTestId('aff-currency').selectOption('gbp');
  await page.getByTestId('aff-per-year').fill('10');
  await expect(page.getByTestId('aff-year-1')).toHaveText('£360.00');
  await expect(page.getByTestId('aff-year-10')).toHaveText('£3,600.00');
  await expect(page.getByTestId('aff-total')).toHaveText('£19,800.00');
});

test('with no plans to pick from the calculator falls back to a sale value', async ({ page }) => {
  await page.goto('/preview/affiliate.html?fixture=noplans');

  await expect(page.getByTestId('aff-plan')).toHaveCount(0);
  await expect(page.getByTestId('aff-value-input')).toBeVisible();

  // £15 a year at 30% is £4.50 a renewal: £22.50 in year 1, and 50 payments —
  // £225.00 — by year 10.
  await page.getByTestId('aff-currency').selectOption('gbp');
  await page.getByTestId('aff-value-input').fill('15');
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£4.50');
  await expect(page.getByTestId('aff-year-1')).toHaveText('£22.50');
  await expect(page.getByTestId('aff-year-10')).toHaveText('£225.00');
});

// A one-off structure pays once and never again, so five sign-ups a year at
// £36 is £180 every year — flat, ten identical years totalling £1,800, not the
// £9,900 the recurring fixture produces from the same plan and headcount.
test('a one-off commission structure projects a flat line, not a climb', async ({ page }) => {
  await page.goto('/preview/affiliate.html?fixture=onceoff');

  await expect(page.getByTestId('affiliate-rate')).toHaveText('You earn 30% of the first payment each customer makes.');
  await page.getByTestId('aff-currency').selectOption('gbp');
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£36.00');
  await expect(page.getByTestId('aff-year-1')).toHaveText('£180.00');
  await expect(page.getByTestId('aff-year-10')).toHaveText('£180.00');
  await expect(page.getByTestId('aff-total')).toHaveText('£1,800.00');

  // Flat means every point at literally the same height, not just matching
  // ends with a bulge in between.
  const dots = page.getByTestId('aff-chart').locator('[data-point] circle');
  await expect(dots).toHaveCount(10);
  const ys = await dots.evaluateAll((els) => els.map((el) => el.getAttribute('cy')));
  expect(new Set(ys).size).toBe(1);
});

test('with no known rate the calculator is degraded, not wrong', async ({ page }) => {
  await page.goto('/preview/affiliate.html?fixture=norate');

  // The card is honest about the rate being unknown...
  await expect(page.getByTestId('affiliate-rate')).toHaveText('Your commission rate is set in SureCart — open your dashboard to see it.');

  // ...and the calculator does not invent numbers to go with it.
  await expect(page.getByTestId('aff-per-payment')).toHaveCount(0);
  await expect(page.getByTestId('aff-year-1')).toHaveCount(0);
  await expect(page.getByTestId('aff-year-10')).toHaveCount(0);
  await expect(page.getByTestId('aff-total')).toHaveCount(0);
  await expect(page.getByTestId('aff-chart')).toHaveCount(0);

  await expect(page.getByTestId('aff-rate-unknown')).toBeVisible();
  await expect(page.getByTestId('aff-rate-unknown')).toContainText('SureCart');
});

// £36 a renewal and £1,800 in year 10, read in Rands at the fixture's rate of
// 24 to the pound: R864.00 and R43,200.00. The plan price beside the picker stays
// in pounds, because that is what the customer is actually charged.
test('earnings can be read in another currency, converted from the store one', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  const currency = page.getByTestId('aff-currency');
  await expect(currency).toBeVisible();
  await expect(currency.locator('option')).toHaveText(['Rands', 'Dollars', 'Pounds', 'Euros']);
  // Opens in Rands, and says where the figures were converted from.
  await expect(currency).toHaveValue('zar');
  await expect(page.getByTestId('aff-per-payment')).toContainText('864.00');
  await expect(page.getByTestId('aff-year-10')).toContainText('43,200.00');
  await expect(page.getByTestId('aff-converted')).toContainText('SureCart still pays you in GBP');
  // The plan price stays in what the customer is actually charged.
  await expect(page.getByTestId('aff-plan')).toContainText('£120.00');

  // Switching back to the store's own currency drops the conversion note.
  await currency.selectOption('gbp');
  await expect(page.getByTestId('aff-per-payment')).toHaveText('£36.00');
  await expect(page.getByTestId('aff-converted')).toHaveCount(0);
});

// Typing "100" one key at a time used to come out "001": the panel re-renders
// on every keystroke, and a type="number" input cannot have its caret put back
// afterwards, so it silently returned to the start.
test('typing a headcount keeps the caret where it was', async ({ page }) => {
  await page.goto('/preview/affiliate.html');

  await page.getByTestId('aff-currency').selectOption('gbp');
  const box = page.getByTestId('aff-per-year');
  await box.click();
  await page.keyboard.press('Control+a');
  await page.keyboard.type('100', { delay: 30 });

  await expect(box).toHaveValue('100');
  // 100 sign-ups a year at £36 a renewal: £3,600 in year 1.
  await expect(page.getByTestId('aff-year-1')).toHaveText('£3,600.00');

  // The caret sits after what was typed, not in front of it.
  expect(await box.evaluate((el) => el.selectionStart)).toBe(3);
});

// A headcount above the old 1,000 ceiling used to give the same answer as
// 1,000 did. 5,000 a year at £36 a renewal is £180,000 in year 1, ten times
// that in year 10, and 36 × 5,000 × 55 = £9,900,000 across the decade.
test('a large headcount is not silently capped', async ({ page }) => {
  await page.goto('/preview/affiliate.html');
  await page.getByTestId('aff-currency').selectOption('gbp');

  await page.getByTestId('aff-per-year').fill('5000');
  await expect(page.getByTestId('aff-year-1')).toHaveText('£180,000.00');
  await expect(page.getByTestId('aff-year-10')).toHaveText('£1,800,000.00');
  await expect(page.getByTestId('aff-total')).toHaveText('£9,900,000.00');

  // Twice the sign-ups, twice the money — not the same figure again.
  await page.getByTestId('aff-per-year').fill('10000');
  await expect(page.getByTestId('aff-total')).toHaveText('£19,800,000.00');
});
