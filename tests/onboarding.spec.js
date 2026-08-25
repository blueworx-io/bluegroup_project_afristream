import { test, expect } from '@playwright/test';

// Some figures are only worth asserting as a number, whatever wraps them.
const digits = async (locator) => (await locator.innerText()).replace(/[^\d]/g, '');

// The flow is a modal on the landing page. These run against the preview
// mirror at /landing, which loads the same CSS and JS the plugin enqueues,
// and which represents a configured install: R1599 a year, a R999 device fee.

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

test('every Get Started button opens the flow', async ({ page }) => {
  const buttons = await page.locator('.as-landing [data-onboard]').all();
  expect(buttons.length).toBe(4); // header, mobile menu, hero, closing section

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

  expect(hrefs).toHaveLength(4);
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

test('the intro lists what the subscription includes, the price, and what it is not', async ({ page }) => {
  await page.getByTestId('header-cta').click();
  const step = page.locator('[data-ob-step="intro"]');

  await expect(step).toContainText('Guided setup for your device, with a real person');
  await expect(step).toContainText('R1599');
  await expect(step).not.toContainText('20 000');
  await expect(page.getByTestId('ob-disclaimer'))
    .toContainText('does not host, stream, supply or resell');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 3');
});

const open = async (page) => {
  await page.getByTestId('header-cta').click();
  await expect(page.getByTestId('onboarding')).toBeVisible();
};

const step = (page, name) => page.locator(`[data-ob-step="${name}"]`);
const next = (page) => page.getByTestId('ob-next');
const back = (page) => page.getByTestId('ob-back');

test('continue moves from the intro to the one device question', async ({ page }) => {
  await open(page);
  await next(page).click();

  await expect(step(page, 'intro')).toBeHidden();
  await expect(step(page, 'device')).toBeVisible();
  await expect(page.getByTestId('ob-title')).toHaveText('Shall we source a device for you?');
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 3');
});

test('the device question is asked once, priced, and says no content comes with it', async ({ page }) => {
  // It used to be two steps: "do you have one?" and then "shall we send you
  // one?" — the same question twice.
  await open(page);
  await next(page).click();

  const device = step(page, 'device');
  await expect(device).toContainText('R999');
  await expect(device).toContainText('No content comes with it');
  await expect(device.locator('input[type="radio"]')).toHaveCount(2);
  await expect(step(page, 'offer')).toHaveCount(0);
});

test('the device question must be answered before continuing', async ({ page }) => {
  await open(page);
  await next(page).click();

  await expect(next(page)).toBeDisabled();
  await page.getByTestId('ob-device-yes').check();
  await expect(next(page)).toBeEnabled();
});

test('answering the device question does not add or drop a step', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 3');
});

test('back returns to the previous step with the answer still selected', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();
  await next(page).click();

  await expect(step(page, 'breakdown')).toBeVisible();
  await back(page).click();
  await expect(step(page, 'device')).toBeVisible();
  await expect(page.getByTestId('ob-device-no')).toBeChecked();
});

test('back is not offered on the first step', async ({ page }) => {
  await open(page);
  await expect(back(page)).toBeHidden();
});

test('the flow never asks what the customer already spends', async ({ page }) => {
  // The subscription picker went with the savings calculator: AfriStream does
  // not replace any of those subscriptions, so totting them up only invited a
  // savings claim the service cannot make.
  await open(page);
  await expect(page.locator('[data-ob-step="subs"]')).toHaveCount(0);
  await expect(page.locator('[data-sub-price]')).toHaveCount(0);

  await next(page).click();
  await page.getByTestId('ob-device-no').check();
  await next(page).click();
  await expect(step(page, 'breakdown')).toBeVisible();
});

test('reopening the flow starts it over', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.keyboard.press('Escape');
  await open(page);

  await expect(step(page, 'intro')).toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 3');
});

/** Through the flow to the breakdown, taking the device or turning it down. */
const toBreakdown = async (page, wantsDevice) => {
  await open(page);
  await next(page).click();
  await page.getByTestId(wantsDevice ? 'ob-device-yes' : 'ob-device-no').check();
  await next(page).click();
};


test('an install with no setup fee never asks the device question', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => el.setAttribute('data-setup-fee', '0'));
  await open(page);

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 2');
  await next(page).click();
  await expect(step(page, 'device')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
});


const CHECKOUT = 'https://pay.example.test/afristream-setup';

test('taking the device puts the fee on the bill and routes to the setup checkout', async ({ page }) => {
  await toBreakdown(page, true);

  const bill = page.getByTestId('ob-cost');
  await expect(bill).toContainText('R1599');
  await expect(bill).toContainText('R999');
  await expect(page.getByTestId('ob-total')).toHaveText('R2598');
  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', CHECKOUT);
});

test('declining the device leaves the fee off the bill and off the checkout', async ({ page }) => {
  await toBreakdown(page, false);

  await expect(page.getByTestId('ob-cost')).not.toContainText('R999');
  await expect(page.getByTestId('ob-total')).toHaveText('R1599');
  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#setup');
});


test('the breakdown quotes the bill and nothing about savings', async ({ page }) => {
  await toBreakdown(page, false);

  const panel = page.locator('.as-ob-panel');
  await expect(page.getByTestId('ob-cost')).toContainText('R1599');
  await expect(panel).not.toContainText('save');
  await expect(panel).not.toContainText('What you save');
});



test('the checkout button takes Continue’s place rather than sitting below it', async ({ page }) => {
  // Both live in the nav row, one swapped for the other. The row itself sits
  // at the foot of a panel that grows with its content, so what is asserted is
  // the slot — same container, same right edge, same line as Back — not an
  // absolute position the panel height would move anyway.
  await open(page);
  await next(page).click();
  const before = await next(page).boundingBox();
  const backBox = await back(page).boundingBox();

  await page.getByTestId('ob-device-no').check();
  await next(page).click();

  await expect(next(page)).toBeHidden();
  await expect(back(page)).toBeVisible();

  const checkout = page.getByTestId('ob-checkout');
  await expect(checkout).toBeVisible();
  expect(await checkout.evaluate((el) => !!el.closest('.as-ob-nav')),
    'the checkout button left the nav row').toBe(true);

  const after = await checkout.boundingBox();
  const backAfter = await back(page).boundingBox();
  expect(Math.abs((after.x + after.width) - (before.x + before.width)),
    'it does not end where Continue ended').toBeLessThan(2);
  expect(Math.abs(after.y - backAfter.y), 'it dropped off Back’s line').toBeLessThan(2);
  expect(Math.abs(after.height - before.height), 'it is not the same height as Continue').toBeLessThan(2);
  expect(backAfter.y).toBeGreaterThan(backBox.y - 1);
});

// -- Final review findings ---------------------------------------------------

test('focus stays inside the dialog after a keyboard-activated step change disables Continue', async ({ page }) => {
  await open(page);

  // Navigate by keyboard alone, focusing Continue and pressing Enter each
  // time, so nextBtn is document.activeElement at the instant render() hides
  // or disables it — this is exactly the sequence that used to drop focus to
  // <body>.
  await next(page).focus();
  await page.keyboard.press('Enter'); // intro -> device, where Continue starts disabled

  await expect(step(page, 'device')).toBeVisible();
  await expect(next(page)).toBeDisabled();

  const inPanel = () => page.evaluate(() =>
    !!document.activeElement && !!document.activeElement.closest('.as-ob-panel'));
  expect(await inPanel(), 'focus should have moved back into the panel, not to <body>').toBe(true);

  for (let i = 0; i < 6; i += 1) {
    await page.keyboard.press('Tab');
    expect(await inPanel(), `focus left the dialog after ${i + 1} tabs`).toBe(true);
  }
});

test('the terminal checkout button closes the modal before following a fragment link', async ({ page }) => {
  await toBreakdown(page, false);

  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#setup');
  await page.getByTestId('ob-checkout').click();

  await expect(modal(page)).toBeHidden();
  await expect(page.locator('#setup')).toBeInViewport();
});

test('the device offer is withheld when the setup checkout is not distinct from the plain one', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => {
    el.setAttribute('data-setup-cta', el.getAttribute('data-cta'));
  });
  await open(page);

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 2');
  await next(page).click();
  await expect(step(page, 'device')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
});


test('a modifier-clicked CTA is left to the browser, not intercepted', async ({ page }) => {
  await expect(modal(page)).toBeHidden();
  await page.getByTestId('header-cta').click({ modifiers: ['Control'] });
  await expect(modal(page)).toBeHidden();
});


test('a missing price falls back to the plugin default, not R0', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => el.removeAttribute('data-price'));
  await toBreakdown(page, false);

  await expect(page.getByTestId('ob-total')).toHaveText('R1599');
});

test('opening the flow does not clobber a pre-existing inline overflow style', async ({ page }) => {
  await page.evaluate(() => { document.body.style.overflow = 'scroll'; });
  await open(page);
  await page.keyboard.press('Escape');
  await expect(modal(page)).toBeHidden();

  const overflow = await page.evaluate(() => document.body.style.overflow);
  expect(overflow).toBe('scroll');
});

test('computed prices are written the same way as the ones PHP prints', async ({ page }) => {
  // The device question reads "R999" and the intro reads "R1599", both from
  // PHP. A breakdown that answered with "R2 598" put two spellings of the same
  // currency on adjacent screens of one flow.
  await toBreakdown(page, true);

  await expect(page.getByTestId('ob-total')).toHaveText('R2598');
  await expect(page.getByTestId('ob-cost')).toContainText('R1599');
  await expect(page.getByTestId('ob-cost')).toContainText('R999');
});

test('the page behind the modal is inert while it is open', async ({ page }) => {
  const header = page.locator('.as-landing > header');
  await expect(header).not.toHaveAttribute('inert', /.*/);

  await open(page);
  await expect(header).toHaveAttribute('inert', '');
  // The modal itself must stay reachable — inerting its own wrapper would
  // take the flow down with the page behind it.
  await expect(modal(page)).not.toHaveAttribute('inert', /.*/);
  await expect(next(page)).toBeEnabled();

  await page.keyboard.press('Escape');
  await expect(header).not.toHaveAttribute('inert', /.*/);
});
