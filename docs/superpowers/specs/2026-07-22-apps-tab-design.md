# Apps Tab — Free Streaming Apps Database

**Date:** 2026-07-22
**Status:** Approved (design), pending implementation plan

## Problem

Portal users own a range of devices — Smart TVs, consoles, streaming sticks, tablets and
phones — and have no guidance on what else they can legitimately install to watch sport,
movies, series and documentaries at no cost. The portal's existing tabs cover the
AfriStream service itself (Profile, What to Watch, Editor Picks, Tips, Troubleshooting);
nothing covers the wider free app landscape.

## Goal

A sixth tab, **Apps**, backed by a curated database of free and free-tier streaming apps.
Each app states what it is, what it carries, which device types it installs on, where it
is available, and how to install it.

## Scope

**In scope:** fully free ad-supported services (Tubi, Pluto TV, Plex, Crackle, Roku
Channel, Rakuten TV, Samsung TV Plus, Red Bull TV, FIFA+, broadcaster catch-up apps) and
the genuinely free tier of otherwise-paid services (DAZN free content, Prime Video with
ads where free, YouTube). Every entry must be installable from the device's official app
store or the vendor's official site.

**Out of scope:** paid subscriptions with no free tier; sideloaded, unofficial or
piracy-adjacent apps; player apps for the AfriStream service itself (those stay in Tips &
Troubleshooting); an admin UI for editing the database; user accounts, ratings or
favourites.

## Data

### Source of truth

A single bundled file, `data/apps.json`. `data/` is already in the plugin build's
`INCLUDE` list ([scripts/build-plugin.mjs:13](../../../scripts/build-plugin.mjs)), so the
file ships with the plugin automatically.

Both runtimes read the same file over HTTP, so there is no PHP↔preview data duplication:

- **WordPress:** the shortcode adds `data-apps-url="<?php plugins_url( 'data/apps.json' ) ?>"`
  to the portal root div, alongside the existing `data-*-endpoint` attributes
  ([afristream-portal.php:79-87](../../../afristream-portal.php)).
- **Preview:** `scripts/preview-server.mjs` serves the repo's `data/apps.json` at the same
  relative path; `preview/index.html` sets `data-apps-url="/data/apps.json"`.

The front end reads `props.appsUrl` and fetches it once, on first entry to the Apps tab.
No REST endpoint — the data is static and public, and routing it through
`afristream/v1/*` would add a PHP layer that does nothing but re-serve a file.

### Schema

```json
{
  "updated": "2026-07-22",
  "apps": [
    {
      "id": "tubi",
      "name": "Tubi",
      "cost": "free",
      "blurb": "Free ad-supported movies and TV, no account needed to start watching.",
      "content": ["Movies", "Series", "Documentaries"],
      "devices": ["smart-tv", "consoles", "sticks", "tablets", "phones"],
      "regions": ["North America", "UK & Ireland", "Asia-Pacific"],
      "availability": "US, Canada, UK, Australia, New Zealand, Mexico",
      "url": "https://tubitv.com",
      "install": {
        "sticks": "Search the Amazon Appstore or Roku Channel Store for \"Tubi\"."
      }
    }
  ]
}
```

**Controlled vocabularies** — an entry using a value outside these lists is a schema
failure, not a new category:

| Field | Allowed values |
| --- | --- |
| `cost` | `free` (fully free, ad-supported), `free-tier` (free slice of a paid service) |
| `content` | `Sport`, `Movies`, `Series`, `Documentaries`, `Live TV` |
| `devices` | `smart-tv`, `consoles`, `sticks`, `tablets`, `phones` |
| `regions` | `Worldwide`, `Africa`, `Europe`, `UK & Ireland`, `North America`, `Latin America`, `Asia-Pacific`, `Middle East` |

**Field notes:**

- `blurb` — one or two sentences, present tense: what the app is and what it does. This is
  the card body and the drawer's opening line.
- `regions` vs `availability` — `regions` drives the filter and stays coarse (a
  country-level filter would mean a 100-entry dropdown). `availability` is free text shown
  on the card and in the drawer for precision. An app available essentially everywhere
  uses `["Worldwide"]` and an `availability` of `"Worldwide"`.
- `install` — optional, and keyed by device. Any device not present falls back to a shared
  default string per device type (e.g. sticks → "Search your stick's app store"). This
  keeps ~25 apps from needing ~125 hand-written strings while still allowing an override
  where the real steps differ.
- `id` — stable kebab-case slug, used to look an app back up when its card is activated,
  the same role `cardRegistry` indices play for poster cards.

### Device taxonomy

| Key | Label | Covers |
| --- | --- | --- |
| `smart-tv` | Smart TV | Samsung Tizen, LG webOS, Android TV / Google TV, Vizio |
| `consoles` | Consoles | PlayStation, Xbox |
| `sticks` | Sticks & Boxes | Fire TV Stick, Roku, Chromecast, Apple TV |
| `tablets` | Tablets | iPad, Android tablets |
| `phones` | Phones | iOS, Android |

### Building the database

The entries themselves require a research pass, not recall. Device support, regional
availability and free-tier status all change, and a wrong entry sends a user hunting for
an app their TV cannot install. The research step produces 25–30 verified entries with
coverage across all five content types and all five device classes, each with its official
URL confirmed. This is a distinct step in the implementation plan, ahead of the UI work.

## UI

### Navigation

Add `{ id: 'apps', label: 'Apps' }` to `NAV` and `apps: appsSection` to `SECTIONS`
([assets/portal.js:665-672](../../../assets/portal.js)). Add `'apps'` to the valid
`defaultTab` list ([assets/portal.js:249](../../../assets/portal.js)) so
`[afristream_portal default_tab="apps"]` works.

### Section layout

Device is the primary control, because it matches the question a user actually has ("what
can I put on my Fire Stick?"):

1. **Device pills** — All, Smart TV, Consoles, Sticks & Boxes, Tablets, Phones. Reuses the
   existing `subBtn` pill style.
2. **Content chips** — All, Sport, Movies, Series, Documentaries, Live TV.
3. **Region select** — the eight region groups, defaulting to All.
4. **App card grid** — responsive grid reusing the portal's existing card treatment.

Filters combine with AND. When a combination matches nothing, the grid shows an empty
state naming the active filters and offering a reset, rather than an unexplained blank.

### Cards

Each card shows the app's initial on a brand-toned gradient tile (the same
`bg()`/gradient approach the poster cards use), the name, a `Free` or `Free tier` badge,
the blurb, its content types, and the device types it supports.

App logos are deliberately **not** used: they are third-party trademarks, and loading them
would mean either bundling image assets or fetching from external hosts, neither of which
the portal does today.

### Detail drawer

Clicking or keyboard-activating a card opens the existing detail drawer
([assets/portal.js:770](../../../assets/portal.js)) in an app variant, preserving its
current behaviour — scroll lock, focus trap, ESC to close, focus returned to the card.

Drawer contents: name and cost badge, full blurb, content carried, region availability
(groups plus the `availability` text), every supported device, per-device install steps,
and a link out to the official site (`target="_blank" rel="noopener noreferrer"`).

The drawer's existing synopsis fetch (`fillSynopsis`, TMDB-backed) must not fire for app
items — the variant is selected by the item carrying an app `id` rather than a TMDB id.

### Disclaimer

A footer note on the tab: availability and free tiers change without notice, and
AfriStream is not affiliated with the services listed.

### Accessibility

Device pills and content chips are buttons with `aria-pressed`; the region select is a
labelled `<select>`; cards are keyboard-activatable with a descriptive `aria-label`, as
the poster cards already are ([assets/portal.js:732](../../../assets/portal.js)); the
drawer keeps its existing `role="dialog"` and focus trap.

## Error handling

| Case | Behaviour |
| --- | --- |
| `data-apps-url` absent | Tab renders an explanatory empty state; the rest of the portal is unaffected. |
| Fetch fails or returns non-JSON | Error state with a retry button. No duplicated fallback app list — the file ships inside the plugin, so a failure is a real fault worth surfacing, not something to paper over. |
| An entry has an unknown `device`/`content`/`region` value | Ignored for filtering, so one bad row cannot break the grid. The schema test catches it in CI. |
| Fetch in flight | Skeleton cards, matching how the watch catalog loads. |

## Testing

Playwright, in `tests/portal.spec.js` alongside the existing suite:

1. The Apps tab appears in the nav and renders the app grid.
2. A device pill narrows the grid, and every remaining card supports that device.
3. The region filter narrows the grid.
4. Device and content filters combine (AND), and an impossible combination shows the empty
   state with a working reset.
5. Clicking a card opens the drawer with the blurb, devices and install steps; ESC closes
   it and focus returns to the card.
6. The drawer does not issue a TMDB synopsis request for an app item.
7. Schema test over `data/apps.json`: every entry has the required fields, and every
   `cost`, `content`, `devices` and `regions` value is in the controlled vocabulary.

## Release

Minor bump to **0.10.0** (new feature, from the current 0.9.0) across
`afristream-portal.php` and `package.json`,
with a matching CHANGELOG entry, per the project's versioning rule.

## Decisions and rejected alternatives

| Decision | Rejected alternative | Why |
| --- | --- | --- |
| Bundled `data/apps.json` | WP admin-editable custom post type | No admin UI to build or keep in sync with the preview; updates ship with a version bump, which is how every other curated list in this portal works. |
| Static file fetched over HTTP | REST endpoint under `afristream/v1/` | The existing endpoints exist to reach external APIs and hide keys. This data is static and public. |
| Device-first pills | Content-type rows; flat searchable list | Matches the user's actual question, and reuses the sub-pill + grid patterns already in the portal. Content rows would list the same app several times. |
| Region groups + free-text note | Country-level filter | A country filter needs a ~100-entry dropdown for a list of ~25 apps. |
| Shared per-device install defaults | Per-app steps for every device | ~125 hand-written strings, most of them identical, all of them going stale. |
| Initial-on-gradient tiles | Real app logos | Third-party trademarks, plus either bundled binaries or external image loads. |
| Detail drawer reuse | Expand-in-place; link straight out | The drawer already handles scroll lock, focus trap and ESC; linking out gives the user nothing the vendor's own site does. |
