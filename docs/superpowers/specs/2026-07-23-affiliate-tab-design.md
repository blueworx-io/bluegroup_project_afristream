# Affiliate Tab — SureCart-gated dashboard link and profit calculator

**Date:** 2026-07-23
**Status:** Approved (design), pending implementation plan

## Problem

Affiliates are recruited and paid through SureCart's affiliate platform
(`/wp-admin/admin.php?page=sc-affiliates`), and the portal's only acknowledgement of them
is a public "Affiliates" link in the nav pointing at the hosted SureCart portal
([assets/portal.js:1474-1476](../../../assets/portal.js)). That link is shown to every
subscriber, whether or not they are an affiliate, and it answers none of the question an
affiliate actually has: *what do I earn if I sign people up?*

Commission is recurring for the life of the subscription, which is the strongest part of
the offer and the hardest to picture — a 30% cut of one £15 subscription reads as small
until you see it repeating every month while the next referral stacks on top of it.

## Goal

A portal tab, visible only to approved SureCart affiliates, with two things on it:

1. a top card that sends them to their SureCart affiliate portal, shows their referral
   link, and states their commission rate in plain English;
2. below it, a profit calculator that projects lifetime recurring commission over the
   first twelve months.

## Scope

**In scope:** affiliate detection from SureCart, the tab and its two panels, live plan
prices from SureCart, a store-default commission rate setting, removal of the existing
public Affiliates nav link, preview fixtures and Playwright coverage.

**Out of scope:** affiliate sign-up or approval flows (Luke adds affiliates in SureCart by
hand); live earnings, payout history or click statistics; per-product commission overrides
(`affiliation_products`); marketing assets, banners or coupon codes; anything that writes
to SureCart.

## Gating

### Detection

A new REST route, `afristream/v1/affiliate`, resolves the current user against SureCart's
affiliations collection:

```php
\SureCart\Models\Affiliation::where(
    array( 'email' => $user->user_email, 'active' => true )
)->with( array( 'commission_structure' ) )->first();
```

Verified against SureCart's OpenAPI spec (`/v1/affiliations`): the collection filters on
`email` and `active`, and an affiliation carries `code`, `referral_url`, `portal_url`,
`total_commission_amount` and an expandable `commission_structure`. The commission
structure carries `percent_commission`, `amount_commission`,
`recurring_commissions_enabled` and `recurring_commission_days`.

The route is available to logged-in users only, nonce-checked like the existing
credentials route, and always describes the caller — it takes no user parameter, so one
affiliate can never read another's referral code.

### Fail closed

The tab appears only on an affirmative answer. No SureCart plugin, a `WP_Error` from the
API, no matching affiliation, or an inactive one all produce `{"affiliate": false}` and no
tab. A missing SureCart is not an error state to display — a subscriber who is not an
affiliate should see exactly what they see today, minus the outbound link.

### Response shape

```json
{
  "affiliate": true,
  "code": "7D201A4D",
  "referral_url": "https://afristream.io/?ref=7D201A4D",
  "portal_url": "https://afristream.surecart.com/affiliates/",
  "commission": { "percent": 30, "amount": null, "currency": "gbp", "recurring": true, "recurring_days": null },
  "plans": [
    { "id": "price_123", "name": "AfriStream — 1 Month", "amount": 1500, "currency": "gbp", "interval": "month", "interval_count": 1 }
  ]
}
```

`{"affiliate": false}` is the entire body for everyone else — no commission rate, no plans,
nothing worth harvesting.

### Caching

A per-user transient, `afristream_affiliate_<user_id>`, for five minutes. Long enough that
tab switching costs nothing; short enough that approving an affiliate in SureCart shows up
while Luke is still looking at the screen. Negative answers are cached too, so a portal
full of ordinary subscribers does not hammer the SureCart API.

### Front end

`NAV` becomes a function of state rather than a constant. The affiliate row is appended
only when `affiliateState === 'ready' && affiliate.affiliate === true`. The fetch fires
once on mount, alongside credentials. Consequences accepted:

- the tab appears a beat after load rather than being present in the first paint. It is
  the last tab in the strip, so nothing shifts under a click;
- `default_tab="affiliate"` falls back to `profile` when the answer is negative, matching
  how unknown tab names already behave ([assets/portal.js:320](../../../assets/portal.js)).

The existing outbound `{ id: 'affiliates', href: … }` nav entry is deleted, along with the
special-case anchor rendering if nothing else uses it.

## Top card

One panel in the established portal style — white, 18px radius, hairline border — holding:

- **Heading:** "Your affiliate dashboard".
- **Primary button** to `portal_url`, opening in a new tab. Falls back to
  `https://afristream.surecart.com/affiliates/` if SureCart returns none.
- **Referral link** in a monospaced field with a copy button, reusing the copy affordance
  already built for the profile credentials. Copy failure falls back to selecting the
  text.
- **Rate, in plain English:** "You earn 30% of every payment, for as long as they stay
  subscribed." A fixed-amount structure reads "You earn £4.50 on every payment"; a
  non-recurring structure drops the trailing clause; a structure limited by
  `recurring_commission_days` says "for the first 12 months" using that value.

## Calculator

### Inputs

- **Plan** — a picker built from `plans`, which the REST route assembles from
  `\SureCart\Models\Price::where( array( 'archived' => false ) )->with( array( 'product' ) )->get()`,
  keeping only recurring prices. Labelled
  with product name and price ("AfriStream — 1 Month · £15/month"). Defaults to the
  cheapest monthly plan, which is what most referrals actually buy.
- **New customers per month** — a number input, 1–100, defaulting to 5.

Nothing else. Churn, tax and payout timing are all real, and all guesses; adding them
would make the number look precise without making it truer.

### Maths

Commission per payment is `percent × plan amount` (or the fixed `amount_commission`).
A customer signed up in month *m* pays in month *m*, then every `interval_count` months
after that for a monthly plan, or every 12 months for an annual one. Twelve months of
accrual, with `n` new customers each month:

```
earned(month) = commission × n × count of cohorts whose renewal falls in that month
```

Annual plans therefore show a flat line for the first year — honest, and worth seeing next
to a monthly plan's staircase.

### Outputs

- commission per payment;
- month 1 income;
- monthly income at month 12 — the headline number;
- total earned across the twelve months;
- a twelve-bar chart of monthly income, bars scaled to the largest month;
- one caveat line: figures assume customers stay subscribed, and commission continues for
  as long as they do.

All money is formatted from the plan's currency via `Intl.NumberFormat`, since AfriStream
sells globally.

### Degraded mode

If `plans` comes back empty — SureCart unreachable for prices, or no recurring prices
configured — the plan picker is replaced by a "value per sale" number box and the same
projection runs off that. The tab stays useful rather than showing an error.

## Store default commission rate

`commission_structure` is null on an affiliation that uses the store default, and the
store's own default lives on the affiliation protocol rather than on the affiliate. The
plugin reads it opportunistically if a protocol model is available, and otherwise falls
back to a new number field on the existing settings screen, **Default affiliate commission
(%)**, which Luke sets once to match SureCart. With neither available the top card states
the rate as unknown and the calculator opens in degraded mode.

## Files

- `includes/affiliates.php` — new. SureCart lookup, caching, the REST route, and the
  settings field. Kept out of `licenses.php`, which is ACF and licence concerns only.
- `bluegroup-project-afristream.php` — require the new file, pass
  `data-affiliate-endpoint` on the portal root, register the settings field.
- `assets/portal.js` — affiliate fetch and state, `NAV` as a function, `affiliateSection`.
- `scripts/preview-server.mjs` — `/api/affiliate` fixture, affiliate and non-affiliate.
- `preview/fixture.html`, `preview/index.html` — the new data attribute.
- `tests/portal.spec.js` — coverage below.

`assets/portal.js` is already past 2,000 lines. The affiliate section is written as one
self-contained `affiliateSection()` plus a pure `projectEarnings()` helper, so the maths is
testable without a DOM and the panel can be lifted into its own file later without
untangling it.

## Testing

Playwright, against the preview harness:

- non-affiliate fixture — no Affiliates tab anywhere in the nav;
- affiliate fixture — the tab appears, and opening it shows the portal button, referral
  link and rate;
- `default_tab="affiliate"` with a non-affiliate fixture lands on Account;
- calculator maths — the default plan and 5 customers a month produce the expected month 1,
  month 12 and year-one totals;
- changing the plan or the customer count updates all four figures;
- degraded mode — an empty `plans` array renders the value-per-sale box.

## Risks

- **SureCart model names.** `Affiliation` is documented in SureCart's PHP model list and
  the endpoint is confirmed in the OpenAPI spec, but the affiliation protocol model is not
  documented. Handled by guarding every SureCart call with `class_exists()` and falling
  back to the settings field.
- **Email mismatch.** The lookup joins on email, so an affiliate whose SureCart record uses
  a different address to their WordPress login will not see the tab. Accepted for now;
  the fix, if it bites, is a per-user affiliation ID override.
- **API latency on the portal's first paint.** Mitigated by the transient and by the tab
  being appended rather than reserved.
