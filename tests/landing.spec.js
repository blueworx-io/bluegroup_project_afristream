import { test, expect } from '@playwright/test';

// The landing page is a plugin page template: it renders the whole document,
// so there is no theme header or footer around it. These run against the
// preview mirror at /landing, which loads the same CSS and JS the plugin
// enqueues.

test.beforeEach(async ({ page }) => {
  await page.goto('/landing');
});

test('the page renders one h1 and its own header and footer', async ({ page }) => {
  await expect(page.getByTestId('landing-header')).toBeVisible();
  await expect(page.getByTestId('landing-footer')).toBeVisible();
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('h1')).toContainText('Every stream. One app.');
});

test('the header links to the portal and to the pricing section', async ({ page }) => {
  const nav = page.getByTestId('landing-nav');
  await expect(nav.getByRole('link', { name: 'Features' })).toHaveAttribute('href', '#features');
  await expect(nav.getByRole('link', { name: 'FAQ' })).toHaveAttribute('href', '#faq');
  await expect(page.getByTestId('landing-header').getByRole('link', { name: 'Dashboard' }))
    .toHaveAttribute('href', '/portal/');
});

test('below 860px the nav collapses into a burger menu that opens and closes', async ({ page }) => {
  await page.setViewportSize({ width: 400, height: 900 });
  await page.goto('/landing');

  const burger = page.getByTestId('landing-burger');
  await expect(burger).toBeVisible();
  await expect(burger).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByTestId('landing-menu')).toBeHidden();

  await burger.click();
  await expect(burger).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByTestId('landing-menu')).toBeVisible();

  // Choosing a destination closes it, rather than leaving it covering the page.
  await page.getByTestId('landing-menu').getByRole('link', { name: 'Pricing' }).click();
  await expect(page.getByTestId('landing-menu')).toBeHidden();
  await expect(burger).toHaveAttribute('aria-expanded', 'false');
});

test('the desktop nav is not rendered as a burger', async ({ page }) => {
  await expect(page.getByTestId('landing-nav')).toBeVisible();
  await expect(page.getByTestId('landing-burger')).toBeHidden();
});

test('the hero leads with the headline, two CTAs and three proof points', async ({ page }) => {
  const hero = page.getByTestId('landing-hero');
  await expect(hero.getByText('20 000+ feeds, live now')).toBeVisible();
  await expect(hero.locator('h1')).toContainText('Every stream. One app.');
  await expect(hero).toContainText('Save thousands with AfriStream');

  await expect(hero.getByRole('link', { name: 'Calculate Savings' })).toHaveAttribute('href', '#calculate');
  await expect(hero.getByRole('link', { name: 'View Pricing' })).toHaveAttribute('href', '#pricing');
  await expect(hero.locator('[data-proof]')).toHaveCount(3);
});

test('the integrations ticker lists the platforms twice, for a seamless loop', async ({ page }) => {
  const ticker = page.getByTestId('landing-ticker');
  await expect(ticker).toContainText('Netflix');
  await expect(ticker).toContainText('SuperSport');
  // Thirteen platforms, doubled: the second copy is what lets the marquee
  // restart without a visible jump.
  await expect(ticker.locator('[data-platform]')).toHaveCount(26);
});

test('the features section lists four blocks', async ({ page }) => {
  const features = page.getByTestId('landing-features');
  await expect(features.locator('[data-feature]')).toHaveCount(4);
  await expect(features).toContainText('Multiple platforms in one');
  await expect(features).toContainText('Save time, money, effort');
});

test('pricing shows one annual plan at R1599 with its five features', async ({ page }) => {
  const pricing = page.getByTestId('landing-pricing');
  await expect(pricing).toContainText('Limited Time Offer!');
  await expect(pricing).toContainText('R1599');
  await expect(pricing).toContainText('/ year');
  await expect(pricing.locator('[data-plan-feature]')).toHaveCount(5);
  await expect(pricing.getByRole('link', { name: 'Get Started' })).toHaveAttribute('href', '#signup');
  await expect(pricing).toContainText('14 Day Money Back Guarantee');
});

test('three testimonials render with their attributions', async ({ page }) => {
  const quotes = page.getByTestId('landing-testimonials').locator('figure');
  await expect(quotes).toHaveCount(3);
  await expect(quotes.first()).toContainText('cancelled four subscriptions');
  await expect(quotes.first().locator('figcaption')).toContainText('Thandi M.');
});

// Prices are annualised Rand. The maths under test: saving is what is left
// after AfriStream's own R1599, and never negative.
const pick = (page, name) => page.getByTestId('landing-calculator').getByRole('button', { name });

// en-ZA groups thousands with a non-breaking space, so assert on digits.
const digits = async (locator) => (await locator.innerText()).replace(/[^\d]/g, '');

test('the calculator starts at zero and prompts for a selection', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await expect(calc.getByTestId('calc-saving')).toHaveText(/R\s*0$/);
  await expect(calc.getByTestId('calc-basis')).toContainText('your selection below');
  await expect(calc).toContainText('R1599 / year');
});

test('selecting subscriptions adds up and subtracts the AfriStream price', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');

  await pick(page, /Netflix Premium/).click();
  await pick(page, /HBO Max/).click();

  // 2748 + 4968 = 7716, less AfriStream's 1599 = 6117.
  expect(await digits(calc.getByTestId('calc-total'))).toBe('7716');
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('6117');
  await expect(calc.getByTestId('calc-basis')).toContainText('2 subscriptions selected');

  // A chosen subscription reads as pressed, for assistive tech.
  await expect(pick(page, /Netflix Premium/)).toHaveAttribute('aria-pressed', 'true');
});

test('the other-subscriptions figure joins the total', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await pick(page, /Netflix Premium/).click();
  await pick(page, /HBO Max/).click();
  await calc.getByTestId('calc-other').fill('1000');

  expect(await digits(calc.getByTestId('calc-saving'))).toBe('7117');
});

test('deselecting returns the saving to zero', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  await pick(page, /Netflix Premium/).click();
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('1149');

  await pick(page, /Netflix Premium/).click();
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('0');
  await expect(pick(page, /Netflix Premium/)).toHaveAttribute('aria-pressed', 'false');
});

test('a selection worth less than AfriStream shows no saving, not a negative one', async ({ page }) => {
  const calc = page.getByTestId('landing-calculator');
  // Amazon Prime at R399 is well under AfriStream's R1599.
  await pick(page, /Amazon Prime/).click();
  expect(await digits(calc.getByTestId('calc-saving'))).toBe('0');
});

test('the FAQ opens with the first answer showing and toggles the rest', async ({ page }) => {
  const faq = page.getByTestId('landing-faq');
  const items = faq.locator('[data-faq]');
  await expect(items).toHaveCount(7);

  const first = items.first();
  await expect(first.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  await expect(first.locator('[data-faq-answer]')).toBeVisible();

  const third = items.nth(2);
  await expect(third.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  await expect(third.locator('[data-faq-answer]')).toBeHidden();

  await third.getByRole('button').click();
  await expect(third.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  await expect(third.locator('[data-faq-answer]')).toBeVisible();
  await expect(third).toContainText('BT, SKY, BBC');

  // Opening one does not close another — these are independent, not a
  // single-open accordion.
  await expect(first.getByRole('button')).toHaveAttribute('aria-expanded', 'true');

  await third.getByRole('button').click();
  await expect(third.locator('[data-faq-answer]')).toBeHidden();
});

test('the signup section hosts the newsletter embed', async ({ page }) => {
  const signup = page.getByTestId('landing-signup');
  await expect(signup).toContainText('Unlock the power of AfriStream today');
  await expect(signup.locator('#surecontact-form-afristream-newsletter-sign-up')).toHaveCount(1);
  await expect(signup).toContainText('14 Day Money Back Guarantee');
});

test('every call to action on the page resolves to a section that exists', async ({ page }) => {
  const hrefs = await page.locator('.as-landing a[href^="#"]').evaluateAll(
    (links) => links.map((a) => a.getAttribute('href'))
  );
  expect(hrefs.length).toBeGreaterThan(0);
  for (const href of [...new Set(hrefs)]) {
    await expect(page.locator(href), `${href} has no target`).toHaveCount(1);
  }
});
