import { test, expect } from '@playwright/test';

// The portal's sections inside the BlueWorx Labs customer dashboard, against
// preview/dashboard.html — a stand-in for the Labs shell with one portal mount
// per panel, the way includes/dashboard.php hands them over.

const panel = (page, key) => page.locator(`.blueworx-store__panel[data-view="${key}"]`);

test('each panel shows its one section, with no tab bar or header of its own', async ({ page }) => {
  await page.goto('/preview/dashboard.html?view=streaming');

  const streaming = panel(page, 'streaming');
  await expect(streaming.getByRole('heading', { name: 'Your AfriStream App Profile Details' })).toBeVisible();
  await expect(streaming.getByText('afri_fixture', { exact: true })).toBeVisible();

  // Labs draws the nav: nothing of the portal's own may be doubled up.
  await expect(page.locator('[data-testid^="nav-"]')).toHaveCount(0);
  await expect(page.getByTestId('header-home')).toHaveCount(0);

  await page.getByRole('link', { name: 'What to Watch' }).click();
  await expect(streaming).toBeHidden();
  await expect(panel(page, 'watch').getByRole('heading').first()).toBeVisible();
});

test('a button that jumps sections opens that section\'s panel', async ({ page }) => {
  await page.goto('/preview/dashboard.html?view=setup');
  const setup = panel(page, 'setup');
  await setup.getByRole('button', { name: /Google TV or Android TV stick/ }).click();
  await setup.getByTestId('setup-next').click();
  await setup.getByTestId('setup-next').click();
  await setup.getByTestId('setup-next').click();

  await setup.getByRole('button', { name: 'See what to watch' }).click();

  await expect(setup).toBeHidden();
  await expect(panel(page, 'watch')).toBeVisible();
  await expect(page).toHaveURL(/view=watch/);
});

test('the Affiliates panel shows the affiliate tools', async ({ page }) => {
  await page.goto('/preview/dashboard.html?view=affiliates');
  await expect(panel(page, 'affiliates').getByRole('heading', { name: 'Your affiliate dashboard' })).toBeVisible();
  await expect(panel(page, 'affiliates').getByTestId('affiliate-unavailable')).toHaveCount(0);
  await expect(panel(page, 'affiliates').getByText('Your AfriStream App Profile Details')).toHaveCount(0);
});

test('an Affiliates panel that cannot load says so, and never shows logins', async ({ page }) => {
  await page.route('**/api/affiliate*', (route) => route.fulfill({ json: { affiliate: false } }));
  await page.goto('/preview/dashboard.html?view=affiliates');

  const affiliates = panel(page, 'affiliates');
  await expect(affiliates.getByTestId('affiliate-unavailable')).toContainText('not available');
  await expect(affiliates.getByText('Your AfriStream App Profile Details')).toHaveCount(0);
});

test('panels only fetch the data they show', async ({ page }) => {
  const hits = [];
  page.on('request', (req) => {
    const path = new URL(req.url()).pathname;
    if (path.startsWith('/api/')) hits.push(path);
  });
  await page.goto('/preview/dashboard.html');
  await expect(panel(page, 'watch').locator('[data-afristream-portal] main')).toHaveCount(1);

  // One mount per panel, each asking only for its own data.
  const count = (p) => hits.filter((h) => h === p).length;
  expect(count('/api/credentials')).toBe(1);
  expect(count('/api/watch')).toBe(1);
  expect(count('/api/affiliate')).toBe(1);
  expect(count('/api/editor-picks')).toBe(0);
});
