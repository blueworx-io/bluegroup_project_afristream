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

test('the full-width CTAs sit inside the cards that hold them', async ({ page }) => {
  // width:100% on a content-box button adds its own padding and border on top
  // of the container's content width, so both CTAs spilled past the card
  // border. Measure the gap on each side rather than trusting the width.
  // Every match, not the first: there are two plan cards once a setup fee is
  // configured, and only one of them was ever measured before.
  for (const sel of ['.as-calc-cta', '.as-plan-cta']) {
    const all = await page.locator(sel).evaluateAll(els => els.map(el => {
      const b = el.getBoundingClientRect();
      const p = el.parentElement.getBoundingClientRect();
      return { left: b.left - p.left, right: p.right - b.right };
    }));
    expect(all.length, `${sel} matched nothing`).toBeGreaterThan(0);
    all.forEach((gaps, i) => {
      expect(gaps.left, `${sel}[${i}] spills past the left edge`).toBeGreaterThanOrEqual(0);
      expect(gaps.right, `${sel}[${i}] spills past the right edge`).toBeGreaterThanOrEqual(0);
      expect(Math.abs(gaps.left - gaps.right), `${sel}[${i}] is not centred`).toBeLessThan(1);
    });
  }
});

test('the six platform tiles in the constellation are all the same square', async ({ page }) => {
  // Two-line labels used to grow their tile taller: aspect-ratio gives way to
  // the content's minimum height unless the overflow is clipped.
  // offsetWidth/Height, not getBoundingClientRect: the tiles are mid-animation
  // at staggered scales, and the invariant under test is the layout box.
  const boxes = await page.locator('.as-tile').evaluateAll(els =>
    els.map(el => ({ w: el.offsetWidth, h: el.offsetHeight })));
  expect(boxes).toHaveLength(6);
  for (const box of boxes) {
    expect(box.w).toBe(boxes[0].w);
    expect(box.h).toBe(boxes[0].h);
    expect(box.h).toBe(box.w);
  }
});

test('the constellation runs the design’s three scenes in order', async ({ page }) => {
  // Arrive (0-2.4s) tiles only, Connect (2.4-4.6s) hub then wires, Converge
  // (4.6-8s) pulse, dim, wordmark. Scrubbing the timeline is the only way to
  // assert a sequence — a live screenshot catches one arbitrary frame.
  const frames = await page.evaluate(() => {
    const anims = document.getAnimations();
    const o = sel => +getComputedStyle(document.querySelector(sel)).opacity;
    const off = sel => parseFloat(getComputedStyle(document.querySelector(sel)).strokeDashoffset);
    return [1000, 3500, 5300, 7000].map(t => {
      anims.forEach(a => { a.pause(); a.currentTime = t; });
      return {
        t,
        tile: o('.as-tile-1'), hub: o('.as-hub'), word: o('.as-wordmark'),
        wire: o('.as-wire-1'), wireOff: off('.as-wire-1'), pulse: o('.as-pulse-1')
      };
    });
  });
  const [arrive, connect, converge, land] = frames;

  // Arrive: tiles are up, nothing else is.
  expect(arrive.tile).toBe(1);
  expect(arrive.hub).toBe(0);
  expect(arrive.wire).toBe(0);

  // Connect: the hub is up and the wires are part-drawn.
  expect(connect.hub).toBe(1);
  expect(connect.wire).toBeGreaterThan(0);
  expect(connect.wireOff).toBeGreaterThan(0);
  expect(connect.wireOff).toBeLessThan(1);

  // Converge: wires fully drawn, a pulse running them, tiles still lit.
  expect(converge.wireOff).toBe(0);
  expect(converge.pulse).toBeGreaterThan(0.5);

  // Land: tiles dimmed, wordmark arriving.
  expect(land.tile).toBeLessThan(0.3);
  expect(land.word).toBeGreaterThan(0);
});

test('with reduced motion preferred, the constellation is a still assembled diagram', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/landing');
  const state = await page.evaluate(() => {
    const cs = sel => getComputedStyle(document.querySelector(sel));
    return {
      tile: +cs('.as-tile-1').opacity,
      hub: +cs('.as-hub').opacity,
      wire: +cs('.as-wire-1').opacity,
      wireOff: parseFloat(cs('.as-wire-1').strokeDashoffset),
      pulse: +cs('.as-pulse-1').opacity,
      running: document.getAnimations().length
    };
  });
  expect(state.tile).toBe(1);
  expect(state.hub).toBe(1);
  expect(state.wire).toBeGreaterThan(0);
  expect(state.wireOff).toBe(0); // drawn, not mid-draw
  expect(state.pulse).toBe(0);
  expect(state.running).toBe(0);
});

test('the savings card sticks beside the checkboxes but never on top of them', async ({ page }) => {
  // The mobile override was authored but sat above the base rule at equal
  // specificity, so it lost on source order and the card stayed sticky —
  // covering the list it is meant to summarise. Check both sides.
  const position = () => page.locator('.as-calc-card')
    .evaluate(el => getComputedStyle(el).position);

  await page.setViewportSize({ width: 1200, height: 900 });
  expect(await position()).toBe('sticky');

  await page.setViewportSize({ width: 400, height: 900 });
  expect(await position()).toBe('static');
});

test('both teaser rows show eight posters and none of them is a link', async ({ page }) => {
  for (const id of ['watch', 'picks']) {
    const section = page.getByTestId(`landing-teaser-${id}`);
    await expect(section).toBeVisible();
    await expect(section.locator('[data-teaser-card]')).toHaveCount(8);
    // Teasers are proof the catalogue exists, not a way into it.
    await expect(section.locator('a')).toHaveCount(0);
  }
});

test('teaser posters keep one row of equal cards, clipped rather than scrollable', async ({ page }) => {
  const row = page.getByTestId('landing-teaser-picks').locator('.as-teaser-row');
  const box = await row.evaluate((el) => {
    const cards = [...el.querySelectorAll('[data-teaser-card]')];
    const tops = new Set(cards.map(c => Math.round(c.getBoundingClientRect().top)));
    return {
      rows: tops.size,
      widths: new Set(cards.map(c => Math.round(c.getBoundingClientRect().width))).size,
      // The section clips the row; the row itself must not become a scroller.
      scrollable: el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX !== 'visible'
    };
  });
  expect(box.rows).toBe(1);
  expect(box.widths).toBe(1);
  expect(box.scrollable).toBe(false);
});

test('the SALE badge sits off the Pricing link’s top corner without underlining or colliding', async ({ page }) => {
  const state = await page.locator('.as-nav-full a.as-nav-sale').evaluate((link) => {
    const badge = link.querySelector('.as-sale');
    const lb = link.getBoundingClientRect();
    const bb = badge.getBoundingClientRect();
    const hits = [...link.parentElement.querySelectorAll('a')]
      .filter(a => a !== link)
      .filter(a => {
        const r = a.getBoundingClientRect();
        return bb.right > r.left && bb.left < r.right && bb.bottom > r.top && bb.top < r.bottom;
      })
      .map(a => a.textContent.trim());
    return {
      decoration: getComputedStyle(link).textDecorationLine,
      // Above the link's own top edge, i.e. superscripted rather than seated.
      risesAboveLink: bb.top < lb.top,
      atRightEdge: Math.abs(bb.right - lb.right) < 1,
      // The badge is absolutely positioned, so the link has to reserve its
      // width or it lands on top of whatever follows it.
      collidesWith: hits
    };
  });
  expect(state.decoration).toBe('none');
  expect(state.risesAboveLink).toBe(true);
  expect(state.atRightEdge).toBe(true);
  expect(state.collidesWith).toEqual([]);
});

test('the Dashboard button and the portal home pill round-trip', async ({ page }) => {
  // Both halves resolve their target at render time — the landing page from
  // the portal-page setting, the portal from home_url() — so a link that is
  // merely present proves nothing. Follow both and check where they land.
  await page.getByTestId('landing-header').getByRole('link', { name: 'Dashboard' }).click();
  await expect(page.locator('.afristream-portal')).toBeVisible();

  await page.getByTestId('header-home').click();
  await expect(page.locator('h1')).toContainText('Every stream. One app.');
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

test('pricing shows the annual plan at R1599 with its five features', async ({ page }) => {
  const plan = page.getByTestId('plan');
  await expect(plan).toContainText('Limited Time Offer!');
  await expect(plan).toContainText('R1599');
  await expect(plan).toContainText('/ year');
  await expect(plan.locator('[data-plan-feature]')).toHaveCount(5);
  await expect(plan).toContainText('14 Day Money Back Guarantee');
});

test('the setup price point sits beside the plain one, priced and linked separately', async ({ page }) => {
  const setup = page.getByTestId('plan-setup');
  await expect(setup).toContainText('Annual Plan + Setup');
  await expect(setup).toContainText('R1599');
  await expect(setup).toContainText('+ R499 once-off setup');
  // The extra feature is what the customer is paying the fee for.
  await expect(setup.locator('[data-plan-feature]')).toHaveCount(6);
  await expect(setup).toContainText('Guided setup done for you');

  // Its own checkout, not the plain plan's — a shared href would make the whole
  // price point pointless.
  const setupHref = await page.getByTestId('plan-setup-cta').getAttribute('href');
  const planHref = await page.getByTestId('plan-cta').getAttribute('href');
  expect(setupHref).toBe('https://pay.example.test/afristream-setup');
  expect(setupHref).not.toBe(planHref);
});

test('both price points sit side by side on desktop and stack on mobile', async ({ page }) => {
  const boxes = async () => ({
    a: await page.getByTestId('plan').boundingBox(),
    b: await page.getByTestId('plan-setup').boundingBox(),
  });

  await page.setViewportSize({ width: 1280, height: 900 });
  let { a, b } = await boxes();
  expect(b.x, 'the cards should be columns, not stacked, at 1280px').toBeGreaterThan(a.x + a.width - 1);

  await page.setViewportSize({ width: 390, height: 844 });
  ({ a, b } = await boxes());
  expect(b.y, 'the cards should stack at 390px').toBeGreaterThan(a.y + a.height - 1);
  expect(b.x).toBeCloseTo(a.x, 0);
});

test('every Get Started button shares one destination, none pointing at another CTA', async ({ page }) => {
  // The mirror carries the empty-setting fallback, so what matters here is that
  // all five agree — in WordPress they all resolve through the same setting.
  // The setup card's button is deliberately excluded: it has its own setting.
  const hrefs = await page.locator('.as-landing a:not(.as-plan-cta-setup)').evaluateAll((links) =>
    links
      .filter((a) => /^(Get Started|Get AfriStream)/.test(a.textContent.trim()))
      .map((a) => a.getAttribute('href')));

  expect(hrefs).toHaveLength(5);
  expect([...new Set(hrefs)]).toHaveLength(1);
  expect(hrefs[0]).not.toBe('#signup');
});

test('the testimonials section is gone', async ({ page }) => {
  await expect(page.getByTestId('landing-testimonials')).toHaveCount(0);
  await expect(page.locator('.as-landing figure')).toHaveCount(0);
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

test('the closing section asks for the sale rather than for an email address', async ({ page }) => {
  const signup = page.getByTestId('landing-signup');
  await expect(signup).toContainText('Unlock the power of AfriStream today');
  await expect(signup).toContainText('14 Day Money Back Guarantee');

  const cta = page.getByTestId('signup-cta');
  await expect(cta).toBeVisible();
  await expect(cta).toHaveText(/Get AfriStream for R1599/);

  // Every "Get Started" on the page leads here, so this button must never be
  // the one that goes nowhere.
  const href = await cta.getAttribute('href');
  expect(href).toBeTruthy();
  expect(href).not.toBe('#signup');

  // The newsletter embed is gone: no form controls left in this section.
  await expect(signup.locator('input, form, textarea')).toHaveCount(0);
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

test('sections below the fold rise into view as you scroll, and none is left hidden', async ({ page }) => {
  const faq = page.getByTestId('landing-faq').locator('[data-reveal]');

  // Off-screen at load, so it starts hidden and animates in.
  await expect(faq).toHaveCSS('opacity', '0');

  await page.getByTestId('landing-faq').scrollIntoViewIfNeeded();
  await expect(faq).toHaveCSS('opacity', '1');

  // Whatever happens, nothing is left invisible at the bottom of the page.
  await page.keyboard.press('End');
  await expect(page.getByTestId('landing-signup').locator('[data-reveal]')).toHaveCSS('opacity', '1');
});

test('with reduced motion preferred, nothing is hidden waiting to be revealed', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/landing');

  // Every section is visible immediately — the reveal never runs.
  for (const id of ['landing-features', 'landing-calculator', 'landing-pricing', 'landing-faq', 'landing-signup']) {
    await expect(page.getByTestId(id).locator('[data-reveal]')).toHaveCSS('opacity', '1');
  }
});
