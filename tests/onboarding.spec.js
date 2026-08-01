import { test, expect } from '@playwright/test';

// Some figures are only worth asserting as a number, whatever wraps them.
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

const CHECKOUT = 'https://pay.example.test/afristream-setup';

test('taking the device puts the fee on the bill and routes to the setup checkout', async ({ page }) => {
  await toOffer(page);
  await page.getByTestId('ob-offer-yes').check();
  await next(page).click();

  const bill = page.getByTestId('ob-cost');
  await expect(bill).toContainText('R1599');
  await expect(bill).toContainText('R499');
  await expect(page.getByTestId('ob-total')).toHaveText('R2098');
  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', CHECKOUT);
});

test('declining the device leaves the fee off the bill and off the checkout', async ({ page }) => {
  await toOffer(page);
  await page.getByTestId('ob-offer-no').check();
  await next(page).click();

  await expect(page.getByTestId('ob-cost')).not.toContainText('R499');
  await expect(page.getByTestId('ob-total')).toHaveText('R1599');
  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#pricing');
});

test('someone who has a device goes to the plain checkout', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#pricing');
});

test('the savings section reflects the subscriptions they ticked', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();

  // Netflix Premium R2748 + HBO Max R4968 = R7716, less R1599 = R6117.
  await step(page, 'subs').getByRole('button', { name: /Netflix Premium/ }).click();
  await step(page, 'subs').getByRole('button', { name: /HBO Max/ }).click();
  await next(page).click();

  expect(await digits(page.getByTestId('ob-saving'))).toBe('6117');
  await expect(page.getByTestId('ob-savings')).toContainText(/R\s*7\D?716/);
});

test('someone who ticked nothing sees no invented saving', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(page.getByTestId('ob-savings'))
    .toContainText('Tell us what you pay for today');
  await expect(page.getByTestId('ob-saving')).toHaveCount(0);
});

test('the breakdown agrees with the page calculator for the same selection', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await calc.getByRole('button', { name: /Disney Plus Premium/ }).click();
  const fromCalc = await digits(calc.getByTestId('calc-saving'));

  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await step(page, 'subs').getByRole('button', { name: /Disney Plus Premium/ }).click();
  await next(page).click();

  expect(await digits(page.getByTestId('ob-saving'))).toBe(fromCalc);
});

test('the checkout button is the last step, with no Continue beside it', async ({ page }) => {
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(next(page)).toBeHidden();
  await expect(back(page)).toBeVisible();
  await expect(page.getByTestId('ob-checkout')).toBeVisible();
});

// -- Final review findings ---------------------------------------------------

test('focus stays inside the dialog after a keyboard-activated step change disables Continue', async ({ page }) => {
  await open(page);

  // Navigate by keyboard alone, focusing Continue and pressing Enter each
  // time, so nextBtn is document.activeElement at the instant render() hides
  // or disables it — this is exactly the sequence that used to drop focus to
  // <body>.
  await next(page).focus();
  await page.keyboard.press('Enter'); // intro -> device
  await page.getByTestId('ob-device-no').check();
  await next(page).focus();
  await page.keyboard.press('Enter'); // device -> subs
  await next(page).focus();
  await page.keyboard.press('Enter'); // subs -> offer, Continue starts disabled

  await expect(step(page, 'offer')).toBeVisible();
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
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

  await expect(page.getByTestId('ob-checkout')).toHaveAttribute('href', '#pricing');
  await page.getByTestId('ob-checkout').click();

  await expect(modal(page)).toBeHidden();
  await expect(page.locator('#pricing')).toBeInViewport();
});

test('the device offer is withheld when the setup checkout is not distinct from the plain one', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => {
    el.setAttribute('data-setup-cta', el.getAttribute('data-cta'));
  });
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-no').check();

  await expect(page.getByTestId('ob-progress')).toHaveText('Step 2 of 4');
  await next(page).click();
  await next(page).click();
  await expect(step(page, 'offer')).toBeHidden();
  await expect(step(page, 'breakdown')).toBeVisible();
});

test('the setup card asks the device question normally when its checkout is not distinct', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => {
    el.setAttribute('data-setup-cta', el.getAttribute('data-cta'));
  });
  await page.getByTestId('plan-setup-cta').click();
  await expect(page.getByTestId('onboarding')).toBeVisible();

  // With no distinct setup checkout the preset must not fire: the flow starts
  // from the intro like any other CTA, rather than skipping straight to subs
  // with the device offer silently pre-ticked to "yes".
  await expect(step(page, 'intro')).toBeVisible();
  await expect(page.getByTestId('ob-progress')).toHaveText('Step 1 of 4');
});

test('a modifier-clicked CTA is left to the browser, not intercepted', async ({ page }) => {
  await expect(modal(page)).toBeHidden();
  await page.getByTestId('header-cta').click({ modifiers: ['Control'] });
  await expect(modal(page)).toBeHidden();
});

test('with the savings helper unavailable, CTAs fall back to their href instead of opening a half-built modal', async ({ page }) => {
  await page.route('**/assets/savings.js', (route) =>
    route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* savings.js failed to load */' }));
  await page.goto('/landing');

  await page.getByTestId('header-cta').click();
  await expect(page.getByTestId('onboarding')).toBeHidden();
});

test('a missing price falls back to the same default the calculator uses, not R0', async ({ page }) => {
  await page.getByTestId('onboarding').evaluate((el) => el.removeAttribute('data-price'));
  await open(page);
  await next(page).click();
  await page.getByTestId('ob-device-yes').check();
  await next(page).click();
  await next(page).click();

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
  // The offer step reads "R499" and the pricing cards read "R1599", both from
  // PHP. A breakdown that answered with "R2 098" put two spellings of the same
  // currency on adjacent screens of one flow.
  await toOffer(page);
  await page.getByTestId('ob-offer-yes').check();
  await next(page).click();

  await expect(page.getByTestId('ob-total')).toHaveText('R2098');
  await expect(page.getByTestId('ob-cost')).toContainText('R1599');
  await expect(page.getByTestId('ob-cost')).toContainText('R499');
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
