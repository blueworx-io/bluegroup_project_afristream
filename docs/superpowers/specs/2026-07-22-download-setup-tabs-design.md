# Download & Setup tabs, Free Streaming trim, Editor Picks rating bands

Date: 2026-07-22

## Goal

Reshape the portal navigation around getting a new customer onto their device
quickly: a guided **Setup** flow, a **Download** page for adding the portal to a
phone home screen, a much smaller and more focused **Free Streaming** directory,
and correct rating filtering on Editor Picks.

## Navigation

`NAV` in `assets/portal.js` becomes:

| id | label | note |
|----|-------|------|
| `profile` | Account | renamed from "Profile" |
| `setup` | Setup | new — sits immediately right of Account |
| `devices` | Devices | new — approved hardware, right of Setup |
| `watch` | What to Watch | unchanged |
| `editor` | Editor Picks | unchanged |
| `apps` | Free Streaming | renamed from "Free Apps" |
| `download` | Download | new |
| `affiliates` | Affiliates | unchanged outbound link |

**Tips & Tricks and Troubleshooting are hidden, not deleted.** They come out of
`NAV` only. `tipsSection()`, `helpSection()`, `TIPS_DATA`, `TROUBLESHOOTING`,
their entries in `SECTIONS`, and the `[troubleshooting_guide]` shortcode in
`includes/shortcodes.php` all stay in place, so restoring them later is a
one-line change. They stay reachable by `default_tab="tips"` / `"help"` on the
shortcode.

The internal section id stays `profile` even though the label is now "Account",
so existing `[afristream_portal default_tab="profile"]` shortcodes on live pages
keep working.

## Account tab

Label change only for the nav; the page heading stays "Your AfriStream App
Profile Details".

A notice is added directly above the Active Username field, inside the white
card:

> The following usernames and passwords are to be used in conjunction with your
> Apps used via AfriStream. They do not provide any access to the Free Streaming
> Apps provided.

A second notice sits alongside it, covering the connection rules — one
connection plays one screen at a time; two or more must be used in the same
household on the same internet connection, the one exception being home WiFi
plus mobile data. Breaking this gets a line removed automatically, so it belongs
next to the credentials rather than in a support thread.

Both are styled as amber advisory panels so they read as caveats rather than as
more credential chrome, and both sit above the credentials so they are read
before anything is copied. They render in every state that shows credentials
(`ready` and `demo`), not in the `empty` or `error` states.

## Setup tab (new)

A two-step flow held in one section, driven by a single new state field
`setupDevice` (`''` until a device is chosen).

**Step 1 — choose your device.** A grid of six tiles:

| key | label | route |
|-----|-------|-------|
| `firestick` | Amazon Fire TV / Firestick | Firesend → Downloader → code |
| `android-tv` | Android TV Box or Stick | Downloader → code |
| `android` | Android Phone or Tablet | browser → `aftv.news/<code>` |
| `ios` | iPhone or iPad | App Store, then registered by us |
| `smart-tv` | Smart TV (Samsung / LG) | TV app store, then registered by us |
| `roku` | Roku TV or Device | Roku Channel Store, then registered by us |

Windows/Mac is deliberately absent: nothing in the source material covers a
desktop route, and inventing one would be worse than omitting it.

**Step 2 — install instructions.** Once a device is chosen, the tiles collapse to
a compact selected-state row (with a "Change device" affordance) and that
device's instructions render below, followed by a shared "Sign in" panel
pointing at the Account tab, with a button that switches to it.

Instructions are **staged**, not one flat list: each device holds an ordered
list of stages, each with a title and its own numbered steps. A stage may carry
a `note`, rendered as a highlighted caveat beneath its steps. A step is either a
plain string or `{ text, code }`; `code` renders oversized and monospaced,
because these are read from a sofa and typed on a TV remote.

Content is drawn from Luke's own guide plus the support-channel material he
supplied: the Firesend poster, the "Setting up your Firestick" sheet, the
Downloader code screenshots and the `#android-phone` channel instructions.

Devices that use Downloader (`firestick`, `android-tv`) and the phone route
(`android`) also render a `DOWNLOADER_CODES` reference block beneath the steps,
listing all three known apps. On the phone the same codes render as
`aftv.news/<code>` web addresses, because that route has no Downloader at all —
the browser fetches the APK directly.

| Code | App | Position |
|------|-----|----------|
| `617725` | IBO Player | Lead recommendation — currently the most reliable |
| `9469460` | Smarters | First fallback |
| `6573365` | Alternative player | Second fallback |

The three closed platforms — iOS, Smart TV and Roku — get a two-stage flow
instead: install a paid store app, then send the MAC address and device key to
support so the device can be registered. This is an accurate reflection of how
those platforms actually work, not a placeholder.

## Devices tab (new)

Static, no state. Approved hardware, grouped into three tiers by `DEVICE_TIERS`:

- **Recommended** — Android TV boxes, Android TV sticks, Android phones, Android
  tablets. Self-served in about twenty minutes.
- **Works, with a caveat** — Amazon Fire TV sticks. They work, but need
  Developer Options, and Amazon keeps tightening what it allows onto the
  platform. Carries a note saying: keep the one you own, but buy Android next.
- **We set these up for you** — Smart TVs, iPhones and iPads, Roku. Closed
  platforms; paid store app, registered at our end.

Each of the eight entries carries a summary, an optional note, and a "What to
look for" list. **The list is specifications, never model names** — OS, RAM,
storage, ethernet, WiFi generation, screen size — so the page does not go stale
each time a manufacturer refreshes a range. Buying guidance was checked against
current reviews: 3–4GB RAM, 16–32GB storage, WiFi 6 and an ethernet port are the
2026 marks for a box.

Closes with a "Whatever you buy" panel (wire it to the router, prefer 5GHz,
leave free storage, one screen per connection, avoid underpowered hardware) and
a button through to Setup.

## Download tab (new)

Static, no state. Explains how to add the portal to a phone or tablet home
screen so it opens like an app.

Two panels side by side (stacking on mobile):

- **iPhone & iPad** — open in Safari, tap Share, Add to Home Screen, Add.
  Explicitly notes Safari is required; Chrome on iOS has no Add to Home Screen.
- **Android** — open in Chrome, tap ⋮, tap "Install app" or "Add to Home
  screen", confirm.

A closing note explains this creates a home-screen icon that opens the portal
full screen, and that no app-store download is involved.

**Known limitation:** this repo ships no web app manifest or service worker, so
what is created is a home-screen shortcut rather than an installed PWA. On
Android the menu item may read "Add to Home screen" instead of "Install app".
The copy is written to be accurate either way. Adding a real manifest would need
changes at the WordPress theme/site level, outside this plugin, and is out of
scope here.

## Free Streaming tab

Renamed from "Free Apps" in the nav, the `<h1>`, and the section label.

### Content categories

`APP_CONTENT` drops from `['Sport','Movies','Series','Documentaries','Live TV']`
to `['Movies','Series','Sport']`. Live TV and Documentaries are gone as filters
and as tags. `APP_CONTENT_HUE` is reduced to match.

### Region and device filters removed

Content category is the only filter left on the tab.

The region control, `state.appsRegion`, the `apps-region` handler and its
`change` listener, the `APP_REGIONS` vocabulary and the `regions` key on every
entry in `data/apps.json` all come out. The human-readable `availability` string
stays — it is still shown in the detail drawer, which is now the only place
regional scope is communicated.

The device pills, `state.appsDevice` and the `apps-device` handler come out
too. `APP_DEVICES`, `APP_DEVICE_LABEL` and `APP_INSTALL_DEFAULTS` all stay:
devices are still listed in each card's footer and stepped through in the app
drawer's "Install on" block. They just no longer narrow the grid — choosing a
device is what the Setup tab is for, and duplicating that choice here only
split the same job across two tabs.

`filteredApps()` and `activeFilterSummary()` reduce to the content clause alone.

### Directory trimmed to 8 apps

The cap is **at most 4 apps per content category**. Because an app tagged both
Movies and Series consumes a slot in both buckets, this lands at 8 apps:

| App | Movies | Series | Sport |
|-----|:------:|:------:|:-----:|
| Tubi | • | • | |
| Plex | • | • | |
| Kanopy | • | | |
| ARTE.tv | • | • | |
| BBC iPlayer | | • | • |
| DAZN | | | • |
| Red Bull TV | | | • |
| Olympics | | | • |
| **Total** | **4** | **4** | **4** |

The 22 other entries are removed from `data/apps.json`. YouTube is excluded by
explicit instruction. Blurbs that sold a service on its live-channel lineup
(Plex) are reworded to describe the on-demand catalogue instead, since Live TV
is no longer something the directory surfaces.

A consequence worth noting: every category has four apps behind it, so the "no
apps match these filters" empty state is no longer reachable from the shipped
data. Its test drives a stubbed directory instead.

There is no cap per device type, because there is no longer a device filter to
cap. Nearly every app runs on all five device classes, which is what made the
device filter close to useless here and a 4-per-device cap impossible without
gutting the directory.

## Editor Picks rating filter

Current behaviour is a minimum threshold, so "★ 7+" includes everything 8 and 9
as well. It becomes a set of exclusive bands.

`EDITOR_RATING_STEPS` goes from `[7, 8, 9]` to `[6, 7, 8, 9]`, and matching
changes from `rating >= r` to `rating >= r && rating < r + 1`, except for the
top step, which stays open-ended so 10.0 is not stranded:

| Button | Matches |
|--------|---------|
| Any | everything |
| ★ 9+ | 9.0 and above |
| ★ 8–8.9 | 8.0 to 8.99 |
| ★ 7–7.9 | 7.0 to 7.99 |
| ★ 6–6.9 | 6.0 to 6.99 |

`state.editorRating` keeps its `0`-means-any contract and still stores the band
floor, so no other call site changes. The existing logic that only offers a step
when some pick actually falls in it is updated to test band membership rather
than the threshold, so an empty band is never offered.

## Files touched

- `assets/portal.js` — nav, Account notice, `setupSection()`, `downloadSection()`,
  apps constants and filters, rating bands, state, event handlers
- `data/apps.json` — trimmed to 8 entries, `regions` removed, tags reduced
- `scripts/preview-server.mjs` — a fifth editor-picks fixture at 6.4, so every
  rating band has something to isolate
- `bluegroup-project-afristream.php` — `default_tab` whitelist, docblock, admin
  help string, version
- `tests/portal.spec.js` — nav labels, apps schema and filters, new tab tests,
  rating band tests
- `CHANGELOG.md`, `package.json` — 0.16.0 (minor: new features)

## Testing

Playwright, against the local preview harness:

- Nav renders exactly the six expected buttons; Tips and Troubleshooting are
  absent from the nav but still reachable via `default_tab`.
- Account shows the disclaimer, and it sits above the username.
- Setup: no steps before a device is picked; Firestick shows all four stages,
  both codes and the developer-mode note; changing device swaps the steps; the
  sign-in button lands on Account.
- Download: both platform panels render and the page points at Setup.
- Free Streaming: heading renamed, neither the region nor the device control is
  present, content filters are exactly All/Movies/Series/Sport, and the category
  filter narrows the grid to that category.
- Apps schema test updated — no `regions` key, content values within the new
  vocabulary, at most 4 per category.
- Editor Picks: each band is isolated in turn against a five-pick fixture
  (6.4, 7.6, 8.1, 8.5, 9.1) and asserted to exclude the bands either side.
