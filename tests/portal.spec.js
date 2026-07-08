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
  expect(['tmdb', 'fallback']).toContain(json.source);
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

test('troubleshooting accordion and device tabs work', async ({ page }) => {
  await page.getByRole('button', { name: 'Troubleshooting' }).click();

  // First item is open by default; clicking another swaps the open panel.
  await expect(page.getByText('Most playback issues clear up')).toBeVisible();
  await page.getByRole('button', { name: /Restart the App/ }).click();
  await expect(page.getByText('Fully close AfriStream')).toBeVisible();

  await page.getByRole('button', { name: 'Firestick', exact: true }).click();
  await expect(page.getByText('Fixes for the Fire TV Stick, Stick 4K and Fire TV Cube.')).toBeVisible();
});
