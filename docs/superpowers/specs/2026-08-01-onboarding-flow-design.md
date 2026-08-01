# Onboarding flow behind the "Get Started" buttons

Closes #22, #23, #24, #25, #26, #27 (milestone: Frontend Onboarding Process).

Depends on the `add-setup-fee-price-point` branch, which introduced the setup
fee and the second checkout URL this flow chooses between.

## The problem

Every "Get Started" button on the landing page goes straight to a checkout. The
page now advertises two price points — subscription, and subscription plus a
one-off setup fee — and expects the customer to work out which one applies to
them from two cards side by side. It also asks them nothing: someone who already
owns a device is shown a card selling them one, and someone who pays for four
streaming services sees the same savings pitch as someone who pays for none.

The flow asks the two questions that decide the price, shows the customer what
they will pay and what they will save, and then sends them to the matching
checkout.

## Placement

A modal overlay on the landing page, not a separate page.

Every Get Started button keeps the real `href` it has today and gains a
`data-onboard` attribute. `assets/onboarding.js` intercepts the click, calls
`preventDefault()` and opens the modal. With JavaScript off or broken, the
button still buys — the same rule the page already follows, that a call to
action must never be inert.

No new WordPress page to create, no setting to point at it, and the flow is
reachable from the header and the mobile menu as well as the pricing cards.

## Configuration

`includes/onboarding.php` renders the modal markup into the landing body,
hidden. Its root element carries the configuration as data attributes:

- `data-price` — the annual price, from `afristream_landing_price()`
- `data-setup-fee` — from `afristream_landing_setup_fee()`
- `data-cta` — from `afristream_landing_cta_url()`
- `data-setup-cta` — from `afristream_landing_setup_cta_url()`

Data attributes rather than `wp_localize_script` because `preview/landing.html`
is static HTML that mirrors the PHP output by hand, and the savings calculator
already passes its prices the same way. A localised script object would work in
WordPress and leave the preview harness — and therefore the Playwright suite —
with no way to reproduce it.

## The steps

**Step 1 — intro (#22).** Plain-language summary of what the subscription
includes, drawn from the same `AFRISTREAM_LANDING_PLAN_FEATURES` list the
pricing cards use, with the annual price and a Continue action.

**Step 2 — existing device (#23).** "Do you already have a streaming device?"
Yes / no. The answer decides whether step 4 is shown.

**Step 3 — existing subscriptions (#24).** The chip picker from the page's
savings calculator, plus the "any other subscriptions?" amount field. Feeds the
savings figure in step 5. Skippable — a customer who does not want to itemise
their subscriptions must not be blocked from buying.

**Step 4 — device offer (#25).** Offers the FireStick, priced at the setup fee,
with an opt in / opt out. Shown only when step 2 was "no" *and* the setup fee is
configured above zero. A customer who already has a device is not sold one, and
an install that has not priced the fee does not advertise it — the same rule
that already keeps the setup pricing card off the page.

**Step 5 — cost and savings breakdown (#26).** Three blocks: what you get, what
it costs (the subscription, plus the setup fee as a separate once-off line when
they opted in), and what you save. The savings figure is the total of their
step 3 subscriptions less the annual price, floored at zero. Its button is the
route to checkout.

**Step 6 — checkout (#27).** Not a screen. The step 5 button opens
`data-setup-cta` when the customer opted into the device at step 4, and
`data-cta` otherwise.

Issues #26 and #27 read slightly differently on where checkout is triggered —
#27 from the device confirmation, #26 from the breakdown. This spec takes step 4
as *deciding* the destination and the breakdown as the last screen before it, so
that the customer sees the total before being asked to pay it.

### Progress

The step counter reflects the steps that customer will actually see: "Step 2 of
5" for someone without a device, "Step 2 of 4" for someone with one. A counter
that promises a step the flow then skips reads as a bug.

## Shared savings data

`AFRISTREAM_LANDING_SUBS` and the savings arithmetic become one source used by
both the page calculator and the modal. The standalone calculator section stays
— it sells the product to visitors who are not ready to click a CTA — but it
stops owning its own copy of the maths.

Related defect in the same code, fixed here: `assets/landing.js` hardcodes
`AFRISTREAM_PRICE = 1599`. Since the annual price became a setting, an install
that changes it gets a calculator quoting savings against the old figure. The
calculator will read the price from a data attribute like everything else.

## What is not stored

Answers live in JavaScript memory for the length of the visit. The flow's only
output is which checkout URL to open. No REST endpoint, no table, no personal
data captured before payment — SureCart already records what the customer
actually bought.

## Accessibility

- `role="dialog"`, `aria-modal="true"`, labelled by the step heading
- Focus moves to the modal on open and is trapped inside it
- Escape closes the flow and returns focus to the button that opened it
- The overlay backdrop closes on click; the panel does not
- Answers are real radio groups and buttons with `aria-pressed`, not divs
- Reduced-motion respected, as the page's reveal animation already does

## Files

New:

- `includes/onboarding.php` — modal markup, configuration attributes
- `assets/onboarding.js` — step machine, savings, routing, focus management
- `assets/onboarding.css` — overlay, panel, step layout
- `tests/onboarding.spec.js` — Playwright, against the preview harness
- `tests/php/test-onboarding.php` — markup and configuration rendering

Changed:

- `includes/landing.php` — `data-onboard` on the five CTAs, render the modal,
  expose the price to the calculator
- `assets/landing.js` — read the price from the DOM, use the shared savings
  helper
- `preview/landing.html` — mirror the new markup
- `CHANGELOG.md`, plugin version — minor bump

## Testing

Playwright, against `preview/landing.html`:

- each of the five Get Started buttons opens the flow
- with the script removed, each still carries a working checkout href
- answering "yes" to the device question skips the device offer, and the step
  counter says so
- answering "no" and opting in produces a breakdown containing the setup fee,
  and a checkout button pointing at the setup URL
- answering "no" and opting out points at the plain URL
- a setup fee of zero skips step 4 for everyone
- selected subscriptions produce the same savings figure as the page calculator
  for the same selection
- Escape closes the flow and returns focus to the opening button

PHP, against the rendered markup:

- the four configuration attributes carry the configured values
- an unconfigured setup fee renders `data-setup-fee="0"`
- the setup checkout attribute falls back to the Get Started URL
