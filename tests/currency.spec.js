import { test, expect } from '@playwright/test';

// The pricing section and the header currency switcher, against the preview
// mirror at /landing. The whole feature is display-only — SureCart bills in
// Rand and carries its own currency control on the checkout — so the thing
// most worth pinning down here is that nothing it does reaches a checkout.
//
// The mirror carries the baked-in fallback rates, which is what makes the
// converted figures below stable: 0.055 to the Dollar, 0.043 to the Pound,
// 1420 to the Đồng, and one-for-one to the Namibian Dollar.

test.describe('the pricing section', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/landing');
  });

  test('prices the subscription in Rand and lists what it buys', async ({ page }) => {
    const plan = page.getByTestId('plan');
    await expect(plan).toBeVisible();
    await expect(plan.locator('.as-plan-price')).toContainText('R1599');
    await expect(plan.locator('.as-plan-price')).toContainText('/ year');
    await expect(plan.locator('.as-plan-features li')).toHaveCount(5);
    await expect(plan.getByTestId('plan-cta')).toHaveText('Get Started');
  });

  test('sells the device beside it as a separate, optional, quoted-first thing', async ({ page }) => {
    const setup = page.getByTestId('plan-setup');
    await expect(setup.locator('.as-plan-price')).toContainText('R999');
    await expect(setup).toContainText('quoted and agreed in writing before anything is ordered');
    await expect(setup).toContainText('No content comes with the device');
  });

  test('is reachable from the nav it is named in', async ({ page }) => {
    await expect(page.getByTestId('landing-nav').getByRole('link', { name: 'Pricing' }))
      .toHaveAttribute('href', '#pricing');
    await expect(page.locator('#pricing')).toHaveCount(1);
  });
});

test.describe('the currency switcher', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/landing');
  });

  test('offers the five currencies and opens on the one the site bills in', async ({ page }) => {
    const select = page.getByTestId('currency-header').locator('select');
    await expect(select).toHaveValue('ZAR');
    await expect(select.locator('option')).toHaveCount(5);
    await expect(select.locator('option')).toHaveText([
      'GBP (£)',
      'NAD (N$)',
      'ZAR (R)',
      'USD ($)',
      'VND (₫)',
    ]);
  });

  test('converts every price on the page at once', async ({ page }) => {
    await page.getByTestId('currency-header').locator('select').selectOption('USD');

    await expect(page.getByTestId('plan').locator('.as-plan-price')).toContainText('$88');
    await expect(page.getByTestId('plan-setup').locator('.as-plan-price')).toContainText('$55');

    await page.getByTestId('currency-header').locator('select').selectOption('GBP');
    await expect(page.getByTestId('plan').locator('.as-plan-price')).toContainText('£69');
  });

  test('rounds Đồng to the nearest thousand and groups it, so it reads as a price', async ({ page }) => {
    await page.getByTestId('currency-header').locator('select').selectOption('VND');

    // 1599 × 1420 = 2 270 580, quoted as 2 271 000.
    await expect(page.getByTestId('plan').locator('.as-plan-price')).toContainText('₫2,271,000');
  });

  test('says what the customer is actually billed, and only when that differs', async ({ page }) => {
    const note = page.getByTestId('plan').locator('[data-fx-note]');
    await expect(note).toBeHidden();

    await page.getByTestId('currency-header').locator('select').selectOption('USD');
    await expect(note).toBeVisible();
    await expect(note).toContainText('billed in Rand');

    await page.getByTestId('currency-header').locator('select').selectOption('ZAR');
    await expect(note).toBeHidden();
  });

  test('never touches a checkout link', async ({ page }) => {
    const before = await page.locator('.as-landing a[href]').evaluateAll(
      (links) => links.map((a) => a.getAttribute('href')));

    await page.getByTestId('currency-header').locator('select').selectOption('VND');

    const after = await page.locator('.as-landing a[href]').evaluateAll(
      (links) => links.map((a) => a.getAttribute('href')));
    expect(after).toEqual(before);
  });

  test('carries the choice into the flow the Get Started button opens', async ({ page }) => {
    await page.getByTestId('currency-header').locator('select').selectOption('USD');
    await page.getByTestId('header-cta').click();

    await expect(page.getByTestId('ob-title')).toBeVisible();
    await expect(page.locator('[data-ob-step="intro"]')).toContainText('$88');
  });

  test('repaints a breakdown that is already on screen', async ({ page }) => {
    await page.getByTestId('header-cta').click();
    await page.getByTestId('ob-next').click();
    await page.getByTestId('ob-device-no').check();
    await page.getByTestId('ob-next').click();

    await expect(page.getByTestId('ob-total')).toHaveText('R1599');

    // The switcher in the mobile menu is behind the modal, so this is the one
    // route to it — and the breakdown's lines were written once, by script,
    // which is exactly the case that needs repainting rather than rewriting.
    await page.evaluate(() => {
      const select = document.querySelector('[data-fx-select]');
      select.value = 'USD';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    await expect(page.getByTestId('ob-total')).toHaveText('$88');
    await expect(page.getByTestId('ob-cost')).toContainText('$88');
  });

  test('both switchers stay on the same currency', async ({ page }) => {
    await page.getByTestId('currency-header').locator('select').selectOption('GBP');
    await expect(page.getByTestId('currency-menu').locator('select')).toHaveValue('GBP');
  });

  test('remembers the choice on the next visit', async ({ page }) => {
    await page.getByTestId('currency-header').locator('select').selectOption('USD');
    await page.reload();

    await expect(page.getByTestId('currency-header').locator('select')).toHaveValue('USD');
    await expect(page.getByTestId('plan').locator('.as-plan-price')).toContainText('$88');
  });
});

test.describe('opening currency by location', () => {
  const cases = [
    { timezoneId: 'Africa/Johannesburg', locale: 'en-ZA', expected: 'ZAR' },
    { timezoneId: 'Africa/Windhoek', locale: 'en-NA', expected: 'NAD' },
    // Chrome hands plenty of South African Windows machines Africa/Windhoek —
    // the two countries share an offset and neither keeps DST — so a UTC+2
    // visitor who is not saying they are Namibian gets the Rand.
    { timezoneId: 'Africa/Windhoek', locale: 'en-GB', expected: 'ZAR' },
    { timezoneId: 'Europe/London', locale: 'en-GB', expected: 'GBP' },
    { timezoneId: 'Asia/Ho_Chi_Minh', locale: 'vi-VN', expected: 'VND' },
    { timezoneId: 'America/New_York', locale: 'en-US', expected: 'USD' },
    // Nowhere we recognise: quoted in Dollars rather than in Rand, with the
    // billed-in-Rand note doing the rest of the work.
    { timezoneId: 'Australia/Sydney', locale: 'en-AU', expected: 'USD' },
  ];

  for (const { timezoneId, locale, expected } of cases) {
    test.describe(`${timezoneId} / ${locale}`, () => {
      test.use({ timezoneId, locale });

      test(`opens in ${expected}`, async ({ page }) => {
        await page.goto('/landing');
        await expect(page.getByTestId('currency-header').locator('select')).toHaveValue(expected);
      });
    });
  }

  test.describe('a timezone we do not place', () => {
    test.use({ timezoneId: 'Europe/Berlin', locale: 'en-GB' });

    test('falls back to the language tag before falling back to Dollars', async ({ page }) => {
      await page.goto('/landing');
      await expect(page.getByTestId('currency-header').locator('select')).toHaveValue('GBP');
    });
  });
});
