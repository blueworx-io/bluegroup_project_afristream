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
  await expect(drawer.locator('button[data-key="genre"][data-val="Football"]')).toBeVisible();
  await expect(drawer.locator('button[data-key="genre"][data-val="Tennis"]')).toBeVisible();
  // Country narrows sport too: Australia keeps the tennis fixture, drops the football one.
  await drawer.locator('button[data-key="country"][data-val="Australia"]').click();
  await drawer.getByRole('button', { name: /^Show \d+ results?$/ }).click();
  await expect(page.getByText('A. Player vs B. Player')).toBeVisible();
  await expect(page.getByText('Fixture FC vs Test United')).not.toBeVisible();
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

test('editor picks filters by type and hides the hero while filtering', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');

  // Default view: the No.1 hero plus every pick.
  await expect(section.getByText(/Editors.*No\.1/)).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();

  // Type + Sort chip rows are present.
  await expect(section.getByRole('button', { name: 'Movies', exact: true })).toBeVisible();
  await expect(section.getByRole('button', { name: 'Series', exact: true })).toBeVisible();
  await expect(section.getByRole('button', { name: 'Top Rated', exact: true })).toBeVisible();

  // Filter to Series: hero collapses, only the one series pick remains.
  await section.getByRole('button', { name: 'Series', exact: true }).click();
  await expect(section.getByText(/Editors.*No\.1/)).toHaveCount(0);
  await expect(page.getByText('Fixture Pick Two')).toBeVisible();
  await expect(page.getByText('Fixture Pick One')).toHaveCount(0);
  await expect(page.getByText('Fixture Pick Three')).toHaveCount(0);

  // Reset restores the hero and all picks.
  await section.getByRole('button', { name: 'Reset filters' }).click();
  await expect(section.getByText(/Editors.*No\.1/)).toBeVisible();
  await expect(page.getByText('Fixture Pick Three')).toBeVisible();
});

test('editor picks A–Z sort reorders the grid', async ({ page }) => {
  await page.goto('/preview/fixture.html');
  await page.getByRole('button', { name: 'Editor Picks' }).click();
  const section = page.locator('[data-screen-label="Editor Picks"]');
  const cards = section.locator('[data-testid="editor-grid"] .as-editor-card');

  // Default (editor order): the hero is Pick One, so the grid starts at Pick Two.
  await expect(cards.first()).toContainText('Fixture Pick Two');

  // A–Z: sorting collapses the hero and shows all picks alphabetically —
  // "Fixture Pick One" now leads the grid.
  await section.getByRole('button', { name: 'A–Z', exact: true }).click();
  await expect(cards.first()).toContainText('Fixture Pick One');
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
