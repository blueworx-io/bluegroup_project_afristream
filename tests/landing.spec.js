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
  await expect(page.locator('h1')).toContainText('Set it up. Then find it.');
});

test('the header links to the sections it names, and to the portal', async ({ page }) => {
  const nav = page.getByTestId('landing-nav');
  await expect(nav.getByRole('link', { name: 'What we do' })).toHaveAttribute('href', '#features');
  await expect(nav.getByRole('link', { name: 'Setup guides' })).toHaveAttribute('href', '#setup');
  await expect(nav.getByRole('link', { name: 'Services we cover' })).toHaveAttribute('href', '#services');
  await expect(nav.getByRole('link', { name: 'FAQ' })).toHaveAttribute('href', '#faq');
  await expect(page.getByTestId('landing-header').getByRole('link', { name: 'Dashboard' }))
    .toHaveAttribute('href', '/portal/');
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

test('the device picker sticks beside the guide on desktop and wraps into chips on mobile', async ({ page }) => {
  // The picker is a sticky column next to a long guide at desktop width, and a
  // wrapping row above it once there is no room for two columns. A sticky row
  // would cover the steps it is meant to lead into.
  const picker = page.locator('.as-setup-picker');

  await page.setViewportSize({ width: 1200, height: 900 });
  expect(await picker.evaluate((el) => getComputedStyle(el).position)).toBe('sticky');

  await page.setViewportSize({ width: 400, height: 900 });
  expect(await picker.evaluate((el) => getComputedStyle(el).position)).toBe('static');
  expect(await picker.evaluate((el) => getComputedStyle(el).flexDirection)).toBe('row');
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


test('the Dashboard button and the portal home pill round-trip', async ({ page }) => {
  // Both halves resolve their target at render time — the landing page from
  // the portal-page setting, the portal from home_url() — so a link that is
  // merely present proves nothing. Follow both and check where they land.
  await page.getByTestId('landing-header').getByRole('link', { name: 'Dashboard' }).click();
  await expect(page.locator('.afristream-portal')).toBeVisible();

  await page.getByTestId('header-home').click();
  await expect(page.locator('h1')).toContainText('Set it up. Then find it.');
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
  await page.getByTestId('landing-menu').getByRole('link', { name: 'Setup guides' }).click();
  await expect(page.getByTestId('landing-menu')).toBeHidden();
  await expect(burger).toHaveAttribute('aria-expanded', 'false');
});

test('the desktop nav is not rendered as a burger', async ({ page }) => {
  await expect(page.getByTestId('landing-nav')).toBeVisible();
  await expect(page.getByTestId('landing-burger')).toBeHidden();
});

test('the hero leads with the headline, two CTAs and three proof points', async ({ page }) => {
  const hero = page.getByTestId('landing-hero');
  await expect(hero.getByText('Device setup & content discovery')).toBeVisible();
  await expect(hero.locator('h1')).toContainText('Set it up. Then find it.');
  await expect(hero).toContainText('helps you find which of the services you already pay for');

  await expect(hero.getByTestId('hero-cta')).toHaveText('Get Started');
  await expect(hero.getByRole('link', { name: 'Setup guides' })).toHaveAttribute('href', '#setup');
  await expect(hero.locator('[data-proof]')).toHaveCount(3);
});

test('the services ticker lists the platforms twice, for a seamless loop', async ({ page }) => {
  const ticker = page.getByTestId('landing-ticker');
  await expect(ticker).toContainText('Netflix');
  await expect(ticker).toContainText('SuperSport');
  // Thirteen platforms, doubled: the second copy is what lets the marquee
  // restart without a visible jump.
  await expect(ticker.locator('[data-platform]')).toHaveCount(26);
  // The affiliation disclaimer is the footer's job and the FAQ's — the ticker
  // is a row of names, not a place to read a paragraph.
  await expect(ticker.locator('p')).toHaveCount(0);
});

test('the features section lists four blocks, one of them the no-content promise', async ({ page }) => {
  const features = page.getByTestId('landing-features');
  await expect(features.locator('[data-feature]')).toHaveCount(4);
  await expect(features).toContainText('Guided device setup');
  await expect(features).toContainText('No content, ever');
});

test('the setup guides open on one device and switch to another when picked', async ({ page }) => {
  const setup = page.getByTestId('landing-setup');
  const tabs = setup.locator('[data-setup-tab]');
  await expect(tabs).toHaveCount(8);

  // One guide open, the rest closed — never all eight stacked, never none.
  await expect(setup.locator('[data-setup-panel]:not([hidden])')).toHaveCount(1);
  await expect(setup.locator('[data-setup-panel="firetv"]')).toBeVisible();
  await expect(setup.locator('[data-setup-panel="firetv"]')).toContainText('Appstore');

  await setup.getByRole('tab', { name: 'Apple TV' }).click();
  await expect(setup.locator('[data-setup-panel="appletv"]')).toBeVisible();
  await expect(setup.locator('[data-setup-panel="firetv"]')).toBeHidden();
  await expect(setup.locator('[data-setup-panel]:not([hidden])')).toHaveCount(1);
});

test('the device picker is a tablist a keyboard can drive', async ({ page }) => {
  const setup = page.getByTestId('landing-setup');
  const first = setup.getByRole('tab', { name: 'Fire TV Stick' });

  await expect(first).toHaveAttribute('aria-selected', 'true');
  // Roving tabindex: the whole picker is one stop in the tab order.
  await expect(setup.locator('[data-setup-tab][tabindex="0"]')).toHaveCount(1);

  await first.focus();
  await page.keyboard.press('ArrowDown');
  await expect(setup.getByRole('tab', { name: 'Apple TV' })).toHaveAttribute('aria-selected', 'true');
  await expect(setup.locator('[data-setup-panel="appletv"]')).toBeVisible();

  // And it wraps rather than dead-ending at either edge.
  await page.keyboard.press('ArrowUp');
  await expect(first).toHaveAttribute('aria-selected', 'true');
});

test('the sourcing guide prices the device apart and promises a quote first', async ({ page }) => {
  const setup = page.getByTestId('landing-setup');
  await setup.getByRole('tab', { name: 'No device yet' }).click();

  const panel = setup.locator('[data-setup-panel="none"]');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('Nothing is ordered until you say yes');
  await expect(panel).toContainText('separate cost');
  await expect(panel).toContainText('We do not supply content with it');
});

test('every Get Started button shares one destination, none pointing at another CTA', async ({ page }) => {
  // The mirror carries the empty-setting fallback, so what matters here is that
  // they all agree — in WordPress they all resolve through the same setting.
  // The device card is the one exception and is meant to be: it sells a
  // different thing and bills through its own checkout.
  const hrefs = await page.locator('.as-landing a').evaluateAll((links) =>
    links
      .filter((a) => a.textContent.trim() === 'Get Started')
      .filter((a) => a.dataset.testid !== 'plan-setup-cta')
      .map((a) => a.getAttribute('href')));

  expect(hrefs).toHaveLength(5);
  expect([...new Set(hrefs)]).toHaveLength(1);
  expect(hrefs[0]).not.toBe('#signup');

  const setup = await page.getByTestId('plan-setup-cta').getAttribute('href');
  expect(setup).not.toBe(hrefs[0]);
});

test('the page never claims to carry, bundle or undercut anyone else’s content', async ({ page }) => {
  // The whole point of the rewrite. Asserted on the rendered text rather than
  // section by section, so a claim reintroduced anywhere fails this.
  const text = await page.locator('.as-landing').innerText();
  for (const claim of ['IPTV', '20 000', '19 000', 'Save thousands', 'Money Back', 'cancel your other subscriptions']) {
    expect(text, `the page should not say "${claim}"`).not.toContain(claim);
  }

  await expect(page.getByTestId('landing-testimonials')).toHaveCount(0);
  await expect(page.locator('.as-landing figure')).toHaveCount(0);
});

test('the footer carries the standing disclaimer', async ({ page }) => {
  const note = page.getByTestId('landing-disclaimer');
  await expect(note).toContainText('do not host, stream, supply, share or resell');
  await expect(note).toContainText('not affiliated with, endorsed by or acting for');
  await expect(note).toContainText('trademarks of their respective owners');
});

test('the FAQ opens with the first answer showing and toggles the rest', async ({ page }) => {
  const faq = page.getByTestId('landing-faq');
  const items = faq.locator('[data-faq]');
  await expect(items).toHaveCount(8);

  const first = items.first();
  await expect(first.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  await expect(first.locator('[data-faq-answer]')).toBeVisible();

  const third = items.nth(2);
  await expect(third.getByRole('button')).toHaveAttribute('aria-expanded', 'false');
  await expect(third.locator('[data-faq-answer]')).toBeHidden();

  await third.getByRole('button').click();
  await expect(third.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  await expect(third.locator('[data-faq-answer]')).toBeVisible();
  await expect(third).toContainText('You keep and pay for whatever services you choose');

  // Opening one does not close another — these are independent, not a
  // single-open accordion.
  await expect(first.getByRole('button')).toHaveAttribute('aria-expanded', 'true');

  await third.getByRole('button').click();
  await expect(third.locator('[data-faq-answer]')).toBeHidden();
});

test('the FAQ answers the questions a reviewer asks', async ({ page }) => {
  const faq = page.getByTestId('landing-faq');
  await expect(faq).toContainText('Do you supply any content, channels or subscriptions?');
  await expect(faq).toContainText('Are you affiliated with Netflix, Disney+, Showmax or anyone else?');
  await expect(faq).toContainText('Can you help me get round regional restrictions?');

  // And the answers are the ones that matter, not a soft version of them.
  for (const q of ['Do you supply any content', 'Are you affiliated with', 'Can you help me get round']) {
    const item = faq.locator('[data-faq]').filter({ hasText: q });
    await item.getByRole('button').click();
    await expect(item.locator('[data-faq-answer]')).toContainText('No');
  }

  // The plainest statement of it lives here now, not in the hero.
  const supply = faq.locator('[data-faq]').filter({ hasText: 'Do you supply any content' });
  await expect(supply.locator('[data-faq-answer]'))
    .toContainText('We do not host, supply, stream or resell any content');
});

test('the setup guide step numbers are circles, not stretched lozenges', async ({ page }) => {
  // The number is a grid item, so it used to grow to the height of a two-line
  // step and read as a pill rather than a numbered marker.
  const boxes = await page.locator('.as-setup-panel:not([hidden]) .as-setup-steps li')
    .evaluateAll((items) => items.map((li) => {
      const before = getComputedStyle(li, '::before');
      return { h: parseFloat(before.height), w: parseFloat(before.width), li: li.getBoundingClientRect().height };
    }));

  expect(boxes.length).toBeGreaterThan(0);
  // At least one step wraps, or the test proves nothing.
  expect(boxes.some((b) => b.li > b.h * 1.5), 'no step is tall enough to test against').toBe(true);
  for (const box of boxes) {
    expect(box.h).toBeCloseTo(box.w, 0);
  }
});

test('the closing sub-line sits on one line on a desktop screen', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  const lines = await page.getByTestId('landing-signup').locator('.as-sec-head p').evaluate((el) => {
    const height = el.getBoundingClientRect().height;
    return Math.round(height / parseFloat(getComputedStyle(el).lineHeight));
  });
  expect(lines).toBe(1);
});

test('the closing section asks for the sale, without a price or a guarantee', async ({ page }) => {
  const signup = page.getByTestId('landing-signup');
  await expect(signup).toContainText('Let us get you set up');
  await expect(signup).toContainText('You keep your own subscriptions, with the providers');

  const cta = page.getByTestId('signup-cta');
  await expect(cta).toBeVisible();
  await expect(cta).toHaveText('Get Started');

  // Every "Get Started" on the page leads here, so this button must never be
  // the one that goes nowhere.
  const href = await cta.getAttribute('href');
  expect(href).toBeTruthy();
  expect(href).not.toBe('#signup');

  // No form controls, and no price: the money is quoted in the flow the button
  // opens, once we know what the customer actually needs.
  await expect(signup.locator('input, form, textarea')).toHaveCount(0);
  await expect(signup).not.toContainText('R1599');
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
  for (const id of ['landing-features', 'landing-setup', 'landing-faq', 'landing-signup']) {
    await expect(page.getByTestId(id).locator('[data-reveal]')).toHaveCSS('opacity', '1');
  }
});

