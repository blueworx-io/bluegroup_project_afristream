# Landing page and simplified setup wizard

Source: the Claude Design handoff in `Afristream hero redesign directions` —
`AfriStream Landing.dc.html` (the public marketing page) and
`AfriStream Dashboard.dc.html` (which, despite its filename, is the simplified
setup wizard).

## Goal

Two independent pieces of work, delivered as two pull requests:

1. **Setup wizard** — replace the portal's existing three-level Setup tab
   (family → sub-device → method) with the handoff's flatter flow: pick a
   device, walk three screens of steps, done.
2. **Landing page** — bring the public marketing page into the plugin as a page
   template, so Elementor can be dropped from the site entirely.

The setup wizard ships first: it is smaller, self-contained, and swaps out one
section of an existing file.

---

# PR 1 — Setup wizard

## What it replaces

`SETUP_METHODS`, `SETUP_STEPS`, `familyOf()` and `setupSection` in
[assets/portal.js](../../../assets/portal.js) (roughly lines 1145–1560), plus
the `setup-family`, `setup-sub`, `setup-back-sub` and `setup-restart` cases in
the click handler and the `setupFamily` / `setupSub` state keys.

The old flow asks three questions before showing a single instruction: which
family of device, which specific device, then which install method. The new
flow asks one.

## The flow

Four stages, tracked by two state keys — `setupDevice` (string, `''` until
picked) and `setupScreen` (integer index) — plus a derived stage:

| Stage | When | Shows |
| --- | --- | --- |
| `device` | `setupDevice === ''` | The six device cards |
| `steps` | device picked and supported | One screen of numbered steps |
| `support` | device picked and unsupported | The "we do this for you" card |
| `done` | user advances past the last screen | The completion card |

A progress bar sits under the page heading throughout, with four labels: *Your
device*, *Get ready*, *Downloader*, *AfriStream app*. Fill percentages: 12% on
`device`, then 40% / 68% / 96% across the three step screens, 100% on `support`
and `done`.

The heading block above it is three lines — eyebrow, title, sub — driven by
stage:

- `device`: "Step 1 of 4" / "Which of these do you have?" / "Pick the device you
  will be watching on and we will show you only the steps that apply to it.
  Nothing here needs any technical know-how."
- `steps`: "Step N of 4" (N = `setupScreen + 2`) / the screen's title / the
  screen's sub.
- `support`: "Step 1 of 4" / "We set this one up for you" / "This device cannot
  install the app on its own, so the profile is created at our end."
- `done`: "All done" / "That is setup finished" / "Nothing left to install —
  your app is ready to watch."

## Devices

Six cards in a responsive grid, each with an SVG icon, a title, a body line and
a "Set this up →" affordance. Google TV carries an "EASIEST" badge.

| Key | Title | Route |
| --- | --- | --- |
| `fire` | Amazon Fire TV Stick | steps |
| `googletv` | Google TV or Android TV stick (badged EASIEST) | steps |
| `box` | Android box | steps |
| `android` | Android phone or tablet | steps |
| `smarttv` | Smart TV, nothing plugged in | support |
| `other` | iPhone, iPad or Roku | support |

Body copy is taken verbatim from the handoff's `devices` array.

Below the grid, a collapsible "Buying a device? What to look for" card holds the
six buying-advice bullets from the handoff. Collapsed by default; state key
`setupHelpOpen`.

### Device icons

Six line icons drawn as inline SVG in the plugin, in the accent colour, sized
to fill the card's image area. No photos, no uploads, no third-party assets:

`fire` (stick + remote), `googletv` (stick + HDMI plug), `box` (small cube on a
cable), `android` (phone and tablet), `smarttv` (TV on a stand), `other`
(phone + Roku-style puck).

## Screens

Each supported device maps to three screens, each `{ title, sub, steps[], note }`.
`steps[]` entries are `{ t, hint?, code? }`.

Three screen definitions are shared between devices, exactly as in the handoff:

- **`googleReady`** — WiFi, Unknown sources, Google account. Used by `googletv`
  and (with an extra first step about plugging the box in) `box`.
- **`playStore`** — install Downloader from the Play Store. Used by `googletv`,
  `box` and `android`.
- **`installApp`** — install the player via Downloader code `6573365` and sign
  in. Used by every supported device as the final screen.

Per-device first screens: `fire` gets "Unlock your stick first" then its own
Downloader screen (room code `10325`); `android` gets "One minute of getting
ready".

Steps carrying a `code` render the code in a monospace pill next to a **Copy
code** button that switches to "Copied" for 1.5s. Reuses the existing `copy()`
helper and `copied` state in portal.js.

A screen's `note`, when present, renders as a tinted panel below the steps.

Navigation: **Back** and a primary button labelled "Done, what is next" — or
"Done — I am watching" on the last screen. Back from screen 0 returns to the
device grid. A "Change device" control sits above the steps alongside the chosen
device's label.

### Fire TV wording

The handoff deliberately does not name the Fire TV store app, saying "the store
app named in your welcome email" where the current portal says "Firesend". Keep
the handoff's wording.

### Fallback players

`installApp`'s note lists the alternatives — IBO Player `617725`, Sky App
`9469460`, Sky Live `569138` — and states that the same username and password
work for all of them. This replaces the old "Which app to install" table.

## Support branch

A single card: "We do this part for you", three lines of explanation from the
handoff, an **Email support** button (`mailto:support@afristream.io`) and a
**Change device** button.

## Done stage

A gradient card — "Setup complete" eyebrow, "You are all set — now find
something to watch", explanatory line, then **See what to watch** (switches the
portal to the `watch` section) and **Back to the steps**. Below it, the WiFi
troubleshooting line with the support email.

A persistent footer line runs under every stage: "Stuck on a step? Email
support@afristream.io with your device and the step number — we reply within one
business day."

## Styling

The wizard adopts the portal's existing light palette (surface `#fff` on
`#f7f5fa`, accent `#a30fd0`, hairline `#e4dfec`) rather than the handoff's own
token set, which differs only marginally. No new CSS file — additions go in
[assets/portal.css](../../../assets/portal.css) alongside the existing section
styles.

## Files touched (PR 1)

- `assets/portal.js` — new setup data and section, old ones removed
- `assets/portal.css` — wizard styles, device icon sizing
- `tests/` — new Playwright spec
- `CHANGELOG.md`, `bluegroup-project-afristream.php` (version constant),
  `package.json` (version)

## Testing (PR 1)

Playwright, against the preview harness:

1. Setup tab lands on the device grid with six cards and a 4-label progress bar.
2. Picking Google TV shows screen 1 of its three screens; the heading reads
   "Step 2 of 4".
3. Advancing three times reaches the done card; Back from there returns to the
   last step screen.
4. The Downloader code step shows `6573365` and the Copy code button flips to
   "Copied".
5. Picking "iPhone, iPad or Roku" shows the support card, not steps.
6. "Change device" from any stage returns to the grid.
7. The buying-advice panel is collapsed on load and expands on click.

---

# PR 2 — Landing page

## Delivery

A new `includes/landing.php`, required from the main plugin file, that:

- Registers a page template named **AfriStream Landing** via the
  `theme_page_templates` filter and serves it through `template_include`. The
  template renders the whole document — `wp_head()`, the landing markup,
  `wp_footer()` — so no theme header or footer loads and no theme stylesheet
  fights the design.
- Registers `[afristream_landing]` as a fallback for anyone who wants the
  sections inside an existing page. Same markup, minus the `<html>` scaffolding.
- Registers and enqueues `assets/landing.css` and `assets/landing.js` only when
  the template or shortcode is in play, following the existing
  register-then-enqueue pattern in `afristream_portal_register_assets()`.

Fonts: Geist (400/500/600) and Space Mono, registered as a Google Fonts
stylesheet handle the way `bluegroup-project-afristream-fonts` already is.

## Portal link setting

A **Portal page** dropdown joins the existing *Live data sources* settings
screen under Settings → AfriStream Portal, in a new *Landing page* section.
Registered as `afristream_portal_page_id` (integer, `absint` sanitiser,
default 0). When unset, the plugin resolves the first published page whose
content contains `[afristream_portal]` and caches the result in a transient
cleared on save. If neither resolves, the header and footer "Dashboard" links
are omitted rather than pointing nowhere.

## Sections

Server-rendered PHP, one function per section, in document order:

1. **Header** — sticky, blurred, hairline underline. Logo (bundled
   `assets/afristream-icon.svg`), nav (Features, Integrations, Calculate,
   Pricing with a SALE tag, FAQ), Dashboard link, primary "Get Started" button.
   Below 860px the nav collapses to a burger that toggles a stacked panel.
2. **Hero** (`#top`) — dimmed EPG grid backdrop, two gradient scrims, eyebrow
   "20 000+ feeds, live now", h1 "Every stream. One app.", sub, two CTAs, three
   proof pills, then the animated constellation frame: six platform tiles wiring
   into a central AfriStream hub that scales up as the wordmark rises. Pure
   CSS/SVG animation, ported as-is and wrapped in a `prefers-reduced-motion`
   media query that pins every keyframe to its resting state.
3. **Integrations** (`#integrations`) — an "Integrations" label beside a
   marquee of 13 platform names, doubled for a seamless loop, masked at both
   edges. Paused under `prefers-reduced-motion`.
4. **Features** (`#features`) — section header plus four feature blocks, each
   with an inline SVG icon.
5. **Calculate** (`#calculate`) — the savings calculator. A sticky result card
   (potential saving, subscriptions total, AfriStream at R1599/year, a CTA and
   the money-back line) beside a 16-item grid of toggleable subscriptions and a
   free-text "any other subscriptions" number input. Saving = max(0, selected
   total + other − 1599), formatted `en-ZA`. On mobile the grid becomes one
   column and the card stops being sticky.
6. **Pricing** (`#pricing`) — one card: "Limited Time Offer!" badge, Annual
   Plan, R1599 / year, five features, Get Started CTA, footnote.
7. **Testimonials** (`#testimonials`) — three quotes in a responsive grid.
8. **FAQ** (`#faq`) — seven accordion items, the first open by default.
9. **Signup** (`#signup`) — section header, then the SureContact embed.
10. **Footer** — logo, tagline, copyright, and three link columns.

Section copy, the 16 subscription prices, the five pricing features and all
seven FAQ pairs are baked into the PHP exactly as the handoff has them. Changing
them is a branch → PR → version bump, like any other change in this plugin.

### Currency

Rand throughout, as designed: R1599/year, subscription prices in Rand, totals
formatted with `en-ZA` grouping.

### Testimonials

The three quotes in the handoff (Thandi M. / Pieter v.d. W. / Naledi K.) read as
placeholder copy. They ship in a single clearly-commented constant marked as
requiring real quotes before the page goes public, so replacing them — or
deleting the section — is a one-place edit. Flagged again at PR review.

## SureContact signup

The signup section renders:

```html
<div id="surecontact-form-afristream-newsletter-sign-up"></div>
```

`https://app.surecontact.com/embed/forms.js` is enqueued as a registered script
handle on the landing page only, with an inline `SureContactForms.render()` call
attached to it (form ID `6e6876be-416c-4cd4-b109-3a603af6be79`, container
`#surecontact-form-afristream-newsletter-sign-up`) so ordering is guaranteed.

The designed email field and its success state are dropped — the embed brings
its own. Landing CSS styles the container (width, centring, spacing) and applies
what it can to the embed's fields; the vendor owns its internals, so the match
will be close rather than exact.

Every CTA on the page — hero, calculator card, pricing card, header — is an
anchor to `#signup`.

## JavaScript

`assets/landing.js` is the only script and handles four things:

- **Mobile menu** — burger toggle below 860px, closing on any nav click.
- **Scroll reveal** — elements marked `data-reveal` fade and rise into view;
  anything already on screen at load is never hidden. Skipped entirely under
  `prefers-reduced-motion`.
- **Calculator** — toggling subscription chips and the "other" input,
  recomputing the three figures.
- **FAQ** — accordion open/close with correct `aria-expanded` and
  `aria-controls`.

No build step, no framework, matching how `portal.js` already works.

## Accessibility

Real `<button>` elements for every toggle, `aria-expanded` on the burger and FAQ
items, a labelled number input in the calculator, alt text on the logo, one
`<h1>` with `<h2>` section headings below it, and visible focus rings that
survive the dark background. Motion respects `prefers-reduced-motion` throughout.

## Preview harness

`preview/landing.html` joins the existing preview pages, loading `landing.css`
and `landing.js` against static markup mirroring the PHP output, so the page can
be developed and tested without WordPress. `scripts/preview-server.mjs` serves
it at `/landing`.

## Files touched (PR 2)

- `includes/landing.php` (new) — template registration, shortcode, settings
  field, portal-page resolution, all section renderers
- `assets/landing.css`, `assets/landing.js`, `assets/afristream-icon.svg` (new)
- `bluegroup-project-afristream.php` — require the new include, register assets,
  version constant
- `preview/landing.html`, `scripts/preview-server.mjs`
- `scripts/build-plugin.mjs` — include the new assets in the staged zip
- `tests/` — new Playwright spec
- `CHANGELOG.md`, `package.json`, `README.md`

## Testing (PR 2)

Playwright, against the preview harness:

1. The page renders all eight section landmarks and a single `<h1>`.
2. Calculator: selecting Netflix Premium (R2748) and HBO Max (R4968) shows a
   total of R7 716 and a saving of R6 117; adding 1000 in the "other" field
   moves the saving to R7 117; deselecting returns the saving to R0.
3. The saving never goes negative when the selection totals under R1599.
4. FAQ: the first item is open on load; clicking the third opens it and sets
   `aria-expanded="true"`.
5. Mobile menu: at 400px wide the nav is hidden and the burger is visible;
   clicking it reveals the links, and clicking a link closes the panel.
6. The signup section contains the SureContact container div.
7. Every in-page CTA resolves to an anchor on the page (no dead `#` links).

## Out of scope

- Checkout wiring. CTAs scroll to the newsletter form; no SureCart checkout URL
  is involved.
- Migrating or preserving anything from the existing Elementor page.
- Admin-editable landing copy.
- Any change to the portal's other sections.
