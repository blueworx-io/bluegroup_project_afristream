import { test, expect } from '@playwright/test';

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
  await page.getByRole('button', { name: 'Troubleshooting' }).click();
  await expect(page.getByRole('heading', { name: 'AfriStream Troubleshooting Guide' })).toBeVisible();
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

test('tips section links through to troubleshooting', async ({ page }) => {
  await page.getByRole('button', { name: 'Tips & Tricks' }).click();
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
  const rowCount = () => page.locator('[data-dragscroll]').first().evaluate((el) => el.children.length);

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

test('editor picks filters by minimum rating', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  const ratingRow = section.getByRole('group', { name: 'Filter by IMDb rating' });

  await expect(ratingRow.getByRole('button')).toHaveText(['Any', '★ 7+', '★ 8+', '★ 9+']);

  // 9+ leaves only the 9.1 pick; the 7.6 and 8.1 picks drop out.
  await ratingRow.getByRole('button', { name: '★ 9+' }).click();
  await expect(page.getByText('Fixture Pick Four')).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);
  await expect(page.getByText('Fixture Pick Two')).toHaveCount(0);

  await ratingRow.getByRole('button', { name: 'Any' }).click();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();
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
  await page.getByRole('button', { name: 'Troubleshooting' }).click();

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
  await page.getByRole('button', { name: 'Profile', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
  await expect(page.getByText('afri_fixture', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Copy', exact: true }).first().click();
  await expect(page.getByRole('button', { name: 'Copied!' })).toBeVisible();

  // Switching profile tabs swaps to the second license's credentials.
  await page.getByRole('button', { name: 'Profile 2' }).click();
  await expect(page.getByText('afri_fixture_tv')).toBeVisible();
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

  for (const tab of ['Profile', 'What to Watch', 'Editor Picks', 'Tips & Tricks', 'Troubleshooting']) {
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

test('the Affiliates tab is an outbound link that opens in a new tab', async ({ page, context }) => {
  const link = page.getByTestId('nav-affiliates');
  await expect(link).toBeVisible();

  // Last in the tab list, and a link rather than a section button.
  const labels = await page.locator('.as-tabs > *').evaluateAll((els) => els.map((e) => e.textContent.trim()));
  expect(labels[labels.length - 1]).toContain('Affiliates');
  await expect(link).toHaveAttribute('href', 'https://afristream.surecart.com/affiliates/');
  // target=_blank hands the opened page a window.opener back to this one
  // unless it is disclaimed.
  await expect(link).toHaveAttribute('rel', /noopener/);

  // Stubbed so the suite stays hermetic — we care that the browser was sent
  // to that URL in a new tab, not what SureCart serves back.
  await context.route('https://afristream.surecart.com/**', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<title>stub</title>' }));

  const [tab] = await Promise.all([context.waitForEvent('page'), link.click()]);
  await tab.waitForLoadState();
  expect(tab.url()).toBe('https://afristream.surecart.com/affiliates/');
  await tab.close();

  // The portal itself stays where it was rather than navigating away.
  await expect(page.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
});

// ---------------------------------------------------------------- apps data

const APP_COSTS = ['free', 'free-tier'];
const APP_CONTENT_VALUES = ['Sport', 'Movies', 'Series', 'Documentaries', 'Live TV'];
const APP_DEVICE_KEYS = ['smart-tv', 'consoles', 'sticks', 'tablets', 'phones'];
const APP_REGION_VALUES = [
  'Worldwide', 'Africa', 'Europe', 'UK & Ireland',
  'North America', 'Latin America', 'Asia-Pacific', 'Middle East',
];

test('apps database is served and every entry matches the schema', async ({ request }) => {
  const res = await request.get('/data/apps.json');
  expect(res.ok()).toBeTruthy();

  const payload = await res.json();
  expect(payload.updated).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(Array.isArray(payload.apps)).toBe(true);
  expect(payload.apps.length).toBeGreaterThanOrEqual(25);

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

    expect(app.regions.length, where).toBeGreaterThan(0);
    for (const r of app.regions) expect(APP_REGION_VALUES, where).toContain(r);

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
