# Subscription + setup fee price point

Issue #21. The landing page's pricing section offers one plan. This adds a
second price point — the same annual subscription plus a one-off setup fee —
sitting beside it, with its own call to action leading to its own checkout.

## Why the amounts move into settings

The price is currently the constant `AFRISTREAM_LANDING_PRICE`, printed in
three places: the pricing card, the calculator's comparison row and CTA, and
the closing section's CTA. The setup fee has to be configurable — nobody wants
a code change and a redeploy to alter a price — and leaving the subscription
price hardcoded beside a configurable fee would be an odd split. Both become
options; the constant stays as the subscription price's default, so an
untouched install renders exactly what it renders today.

## Settings

Three fields join the existing "Landing page" section on the plugin's settings
screen.

| Option | Type | Default | Notes |
| --- | --- | --- | --- |
| `afristream_landing_price` | int | `1599` (`AFRISTREAM_LANDING_PRICE`) | Annual subscription, in Rand. `absint()`. |
| `afristream_landing_setup_fee` | int | `0` | One-off setup fee, in Rand. `absint()`. |
| `afristream_landing_setup_cta_url` | string | `''` | Checkout for the bundle. Same `afristream_landing_sanitize_cta_url()` as the existing URL: http, https, mailto and tel only, and a refused value keeps the stored one. |

Accessors:

- `afristream_landing_price()` — the stored price, or the constant. A stored
  `0` also falls back to the constant: a free headline price is never what
  someone meant, and a blanked field should read as "use the default".
- `afristream_landing_setup_fee()` — the stored fee, or `0`.
- `afristream_landing_setup_cta_url()` — the stored URL, or
  `afristream_landing_cta_url()`. Never inert, matching the rule the existing
  CTA already follows.

## The second card

`afristream_landing_pricing()` wraps its card in a new `.as-plans` grid and
emits a second `.as-plan`:

- name `Annual Plan + Setup`
- price `R{price}` `/ year`, with a `.as-plan-setup` line reading
  `+ R{fee} once-off setup`
- the same five features plus `Guided setup done for you`
- CTA `data-testid="plan-setup-cta"`, classes `as-plan-cta as-plan-cta-setup`,
  pointing at `afristream_landing_setup_cta_url()`

**A setup fee of `0` omits the card entirely.** An unconfigured install must
not advertise a setup option at R0, and must not show a second button leading
to the same checkout as the first.

The existing card keeps its markup, its badge and its `plan-cta` test id
unchanged.

## Layout

`.as-plans` is a grid: one column by default, two above the section's existing
desktop breakpoint, with the cards top-aligned so the taller one does not
stretch the other. `.as-plan` keeps its own `width: min(420px, 100%)` and
centres within its grid cell, so the single-card case looks exactly as it does
now. `.as-plan-setup` is a small muted line under the price.

## Tests

PHP (`tests/php/test-landing.php`):

- an unset setup fee leaves the pricing section with one card and one CTA
- a configured fee renders the second card, its amount, and its own URL
- a configured fee with no setup URL falls back to the Get Started URL
- the "every Get Started button uses the configured URL" count test states that
  it runs with the fee unset, and gains a configured-fee case expecting six

Playwright (`tests/landing.spec.js`), against the preview mirror:

- both cards render, the second showing the once-off setup line
- the setup CTA points at its own href, not the first card's
- both cards' CTAs stay inside their card (the existing spill test, widened to
  every `.as-plan-cta`)
- the existing five-feature assertion is scoped to the first card

`preview/landing.html` is a hand-kept mirror of what
`afristream_landing_body()` prints; it gains the second card so the mirror
represents a configured site.
