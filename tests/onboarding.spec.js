import { test, expect } from '@playwright/test';

// The flow is a modal on the landing page. These run against the preview
// mirror at /landing, which loads the same CSS and JS the plugin enqueues,
// and which represents a configured install: R1599 a year, a R499 setup fee.

test.beforeEach(async ({ page }) => {
  await page.goto('/landing');
});

const modal = (page) => page.getByTestId('onboarding');

test('the flow is closed until a Get Started button opens it', async ({ page }) => {
  await expect(modal(page)).toBeHidden();
  await page.getByTestId('header-cta').click();
  await expect(modal(page)).toBeVisible();
  await expect(page.getByTestId('ob-title')).toHaveText('What you get with AfriStream');
});

test('all five Get Started buttons open the flow', async ({ page }) => {
  const buttons = await page.locator('.as-landing [data-onboard]').all();
  expect(buttons.length).toBe(6); // five Get Started, plus the setup card's

  for (const button of buttons) {
    // The header's Get Started button and the mobile menu's copy of it are
    // never both visible: the header CTA is desktop-only and the menu one
    // only renders below the nav's 860px breakpoint, behind the burger.
    // Whichever this button is, switch to the state that shows it before
    // interacting with it.
    if (!(await button.isVisible())) {
      await page.setViewportSize({ width: 400, height: 900 });
      const burger = page.getByTestId('landing-burger');
      if (!(await button.isVisible()) && (await burger.isVisible())) {
        await burger.click();
      }
    }
    await button.scrollIntoViewIfNeeded();
    await button.click();
    await expect(modal(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(modal(page)).toBeHidden();
  }
});

test('every CTA still carries a working checkout href for a visitor without JS', async ({ page }) => {
  const hrefs = await page.locator('.as-landing [data-onboard]').evaluateAll((els) =>
    els.map((el) => el.getAttribute('href')));

  expect(hrefs).toHaveLength(6);
  hrefs.forEach((href) => expect(href, 'a CTA with no destination').toBeTruthy());
});

test('escape closes the flow and returns focus to the button that opened it', async ({ page }) => {
  const cta = page.getByTestId('header-cta');
  await cta.click();
  await expect(modal(page)).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(modal(page)).toBeHidden();
  await expect(cta).toBeFocused();
});

test('the backdrop closes the flow and the panel does not', async ({ page }) => {
  await page.getByTestId('header-cta').click();
  await page.locator('.as-ob-panel').click({ position: { x: 10, y: 10 } });
  await expect(modal(page)).toBeVisible();

  await page.locator('.as-ob-backdrop').click({ position: { x: 5, y: 5 } });
  await expect(modal(page)).toBeHidden();
});

test('focus moves into the dialog on open and is trapped there', async ({ page }) => {
  await page.getByTestId('header-cta').click();

  const inPanel = () => page.evaluate(() =>
    !!document.activeElement.closest('.as-ob-panel'));
  expect(await inPanel()).toBe(true);

  // Tab all the way round: focus must never escape to the page behind.
  for (let i = 0; i < 12; i += 1) {
    await page.keyboard.press('Tab');
    expect(await inPanel(), `focus left the dialog after ${i + 1} tabs`).toBe(true);
  }
});

test('the intro lists what the subscription includes and the annual price', async ({ page }) => {
  await page.getByTestId('header-cta').click();
  const step = page.locator('[data-ob-step="intro"]');

  await expect(step).toContainText('Access to over 20 000 live feeds');
  await expect(step).toContainText('R1599');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 5');
});
