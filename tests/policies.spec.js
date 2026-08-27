import { test, expect } from '@playwright/test';

// The four policy pages, against the preview mirrors. Each mirrors what
// afristream_policy_body() prints for one document, at the slug the plugin's
// footer links it from.
//
// These exist because a payment provider asked to see them before activating
// the business, so what is asserted here is what such a reviewer opens: the
// document is there, it is reachable from anywhere on the site, and it says
// the thing its name promises.

const DOCS = [
  { slug: 'terms', id: 'terms', title: 'Terms of Service' },
  { slug: 'privacy', id: 'privacy', title: 'Privacy Policy' },
  { slug: 'refund-policy', id: 'refunds', title: 'Refund Policy' },
  { slug: 'cancellation-policy', id: 'cancellation', title: 'Cancellation Policy' },
];

test.describe('every policy page', () => {
  for (const doc of DOCS) {
    test(`${doc.title} renders as its own page with the site around it`, async ({ page }) => {
      await page.goto(`/${doc.slug}`);

      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.locator('h1')).toHaveText(doc.title);
      await expect(page.getByTestId(`policy-${doc.id}`)).toBeVisible();
      await expect(page.getByTestId('policy-updated')).toContainText('Last updated');

      // Not a bare wall of text on a white page — it carries the site.
      await expect(page.getByTestId('landing-header')).toBeVisible();
      await expect(page.getByTestId('landing-footer')).toBeVisible();
    });

    test(`${doc.title} links the other three documents`, async ({ page }) => {
      await page.goto(`/${doc.slug}`);

      const others = DOCS.filter((other) => other.slug !== doc.slug);
      for (const other of others) {
        await expect(
          page.locator('.as-doc-foot').getByRole('link', { name: other.title })
        ).toHaveAttribute('href', `/${other.slug}/`);
      }
    });
  }
});

test.describe('reaching them', () => {
  test('the footer carries a Legal column on the landing page', async ({ page }) => {
    await page.goto('/landing');

    const legal = page.getByTestId('footer-legal');
    await expect(legal).toBeVisible();
    for (const doc of DOCS) {
      await expect(legal.getByRole('link', { name: doc.title })).toHaveAttribute('href', `/${doc.slug}/`);
    }
  });

  test('and on the policy pages too, so no document is a dead end', async ({ page }) => {
    await page.goto('/refund-policy');
    await expect(page.getByTestId('footer-legal').getByRole('link')).toHaveCount(4);
  });

  test('a policy page navigates back into the landing page, not at nothing', async ({ page }) => {
    await page.goto('/terms');

    const nav = page.getByTestId('landing-nav');
    await expect(nav.getByRole('link', { name: 'Pricing' })).toHaveAttribute('href', '/landing/#pricing');

    await nav.getByRole('link', { name: 'Pricing' }).click();
    await expect(page.getByTestId('landing-pricing')).toBeInViewport();
  });

  test('the currency switcher works on a policy page, since the terms quote a price', async ({ page }) => {
    await page.goto('/terms');

    const price = page.getByTestId('policy-terms').locator('[data-money]').first();
    await expect(price).toHaveText('R1599');

    await page.getByTestId('currency-header').locator('select').selectOption('USD');
    await expect(price).toHaveText('$88');
  });
});

test.describe('what they say', () => {
  test('the refund policy states the window, the condition and the exclusions', async ({ page }) => {
    await page.goto('/refund-policy');
    const doc = page.getByTestId('policy-refunds');

    await expect(doc).toContainText('within 14 days of payment');
    await expect(doc).toContainText('setup session has not taken place');
    await expect(doc).toContainText('device we have already ordered for you');
    await expect(doc).toContainText('support@afristream.io');
  });

  test('the cancellation policy says the paid year is honoured and nothing renews', async ({ page }) => {
    await page.goto('/cancellation-policy');
    const doc = page.getByTestId('policy-cancellation');

    await expect(doc).toContainText('end of the year you have already paid for');
    await expect(doc).toContainText('does not renew');
    await expect(doc).toContainText('Cancelling stops the next payment');
  });

  test('the privacy policy declares no tracking and names who handles payment', async ({ page }) => {
    await page.goto('/privacy');
    const doc = page.getByTestId('policy-privacy');

    await expect(doc).toContainText('no analytics');
    await expect(doc).toContainText('SureCart');
    await expect(doc).toContainText('card number never reaches AfriStream');
  });

  test('the terms hold the same line the rest of the site does', async ({ page }) => {
    await page.goto('/terms');
    const doc = page.getByTestId('policy-terms');

    await expect(doc).toContainText('does not host, stream, supply, share or resell');
    await expect(doc).toContainText('billed in South African Rand');
  });
});

test('the delivery timeline is on the pricing section, not only in the terms', async ({ page }) => {
  await page.goto('/landing');

  const delivery = page.getByTestId('delivery');
  await expect(delivery).toBeVisible();
  await expect(delivery).toContainText('the moment your payment clears');
  await expect(delivery).toContainText('within 2 business days');
  await expect(delivery).toContainText('5 to 10 business days');
});
