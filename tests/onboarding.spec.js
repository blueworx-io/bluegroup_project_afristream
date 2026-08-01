import { test, expect } from '@playwright/test';

// en-ZA groups thousands with a non-breaking space, so assert on digits.
const digits = async (locator) => (await locator.innerText()).replace(/[^\d]/g, '');

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

const open = async (page) => {
  await page.getByTestId('header-cta').click();
  await expect(page.getByTestId('onboarding')).toBeVisible();
};

const step = (page, name) => page.locator(`[data-ob-step="${name}"]`);
const next = (page) => page.getByTestId('ob-next');
const back = (page) => page.getByTestId('ob-back');

test('continue moves from the intro to the device question', async ({ page }) => {
  await open(page);
  await next(page).click();

  await expect(step(page, 'intro')).toBeHidden();
  await expect(step(page, 'device')).toBeVisible();
  await expect(page.getByTestId('ob-title')).toHaveText('Do you already have a streaming device?');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 5');
});

test('the device question must be answered before continuing', async ({ page }) => {
  await open(page);
  await next(page).click();

  await expect(next(page)).toBeDisabled();
  await page.getByTestId('ob-device-yes').check();
  await expect(next(page)).toBeEnabled();
});

test('someone who has a device is never counted a step for the device offer', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
});

test('back returns to the previous step with the answer still selected', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();
  await next(page).click();

  await expect(step(page, 'subs')).toBeVisible();
  await back(page).click();
  await expect(step(page, 'device')).toBeVisible();
  await expect(page.getByTestId('ob-device-no')).toBeChecked();
});

test('back is not offered on the first step', async ({ page }) => {
  await open(page);
  await expect(back(page)).toBeHidden();
});

test('the subscription step is skippable and reports a running total', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();

  await expect(step(page, 'subs')).toBeVisible();
  await expect(next(page), 'nobody should be blocked from buying by an optional question').toBeEnabled();

  // Netflix Premium R2748 + Showmax + Premier League R1800.
  await step(page, 'subs').getByRole('button', { name: /Netflix Premium/ }).click();
  await step(page, 'subs').getByRole('button', { name: /Showmax \+ Premier League/ }).click();
  expect(await digits(page.getByTestId('ob-subs-total'))).toBe('4548');

  await page.getByTestId('ob-subs-other').fill('1000');
  expect(await digits(page.getByTestId('ob-subs-total'))).toBe('5548');
});

test('reopening the flow starts it over', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.keyboard.press('Escape');
  await open(page);

  await expect(step(page, 'intro')).toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 5');
});

const toOffer = async (page) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();
  await next(page).click();
  await next(page).click();
};

test('someone without a device is offered one, priced at the setup fee', async ({ page }) => {
  await toOffer(page);

  await expect(step(page, 'offer')).toBeVisible();
  await expect(step(page, 'offer')).toContainText('FireStick');
  await expect(step(page, 'offer')).toContainText('R499');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 4 of 5');
});

test('someone who has a device never sees the offer', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(step(page, 'offer')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 4 of 4');
});

test('the offer must be answered before continuing', async ({ page }) => {
  await toOffer(page);
  await expect(next(page)).toBeDisabled();
  await page.getByTestId('ob-offer-no').check();
  await expect(next(page)).toBeEnabled();
});

test('changing the device answer after seeing the offer drops the step', async ({ page }) => {
  // Going back and answering "yes" must not leave a device-offer step stranded
  // in the flow, nor a count that promises one.
  await toOffer(page);
  await back(page).click();
  await back(page).click();
  await page.getByTestId('ob-device-yes').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
  await next(page).click();
  await next(page).click();
  await expect(step(page, 'breakdown')).toBeVisible();
});

test('an install with no setup fee offers no device at all', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => el.setAttribute('data-setup-fee', '0'));
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
  await next(page).click();
  await next(page).click();
  await expect(step(page, 'offer')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
});

test('the setup card opens the flow with the device answered for them', async ({ page }) => {
  await page.getByTestId('plan-setup-cta').click();
  await expect(page.getByTestId('onboarding')).toBeVisible();

  await expect(step(page, 'subs'), 'the two questions it already answers are skipped').toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 3 of 5');
  await next(page).click();
  await expect(page.getByTestId('ob-offer-yes')).toBeChecked();
});
