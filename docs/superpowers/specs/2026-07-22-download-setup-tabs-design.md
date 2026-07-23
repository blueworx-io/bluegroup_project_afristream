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

One tab, one flow. An earlier revision split this across a Setup tab and a
Devices tab; they carried two taxonomies for the same hardware under different
names, and nothing kept them in sync. Devices is now folded in, and its buying
advice lives at step 2 next to the thing being chosen.

### The flow

State is two fields, `setupFamily` and `setupSub`, and the step is derived:

| Step | Shows | Advances on |
|------|-------|-------------|
| 1 — Your device | "Why you need a device", then the three family tiles | `setup-family` |
| 2 — Which one | Sub-device tiles, buying advice and links, WiFi guidance | `setup-sub` |
| 3 — Install it | The method's stages, then its app list | — |

Choosing an app is part of installing rather than a step of its own, so the app
list sits inside step 3, below the stages that refer to it. It was briefly a
fourth step; that only made the rail claim a stage the customer could never
navigate to, because it shared a screen with step 3 anyway.

A progress rail across the top marks completed steps as buttons —
`setup-restart` back to step 1, `setup-back-sub` back to step 2 — so there is
always a way out that does not lose the whole flow.

### Taxonomy

Three families, eight sub-devices:

| Family | Sub-devices | Method |
|--------|-------------|--------|
| TVs & Sticks | Amazon Fire TV Stick ★ | `firesend` |
| | Xiaomi TV Stick 4K ★ | `downloader` |
| | Other Google TV stick ★ | `downloader` |
| | Smart TV, no stick | `assisted` |
| Android Boxes | Google TV box | `downloader` |
| | Android TV box | `downloader` |
| Android Devices | Android Phone | `browser` |
| | Android Tablet | `browser` |

★ = carries a "Recommended" badge. These are the three sticks we actively
recommend; everything else is described by specification rather than model,
but "which stick should I buy" deserves a real answer.

**`method` sits on the sub-device, not the family.** A stick and a bare Smart TV
install completely differently even though a customer thinks of both as "the
telly", and that is exactly why TVs & Sticks is one family in the picker but two
routes underneath.

**iPhone, iPad and Roku are deliberately out of the flow.** They are closed
platforms needing manual registration, and carrying three near-identical
walkthroughs for them cost more than it returned. Step 1 ends with a line
pointing those customers at support.

### Methods

Four methods serve all eight sub-devices. Adding hardware is a row in
`DEVICE_FAMILIES` pointing at an existing method, not another walkthrough.

| Method | Route | Used by |
|--------|-------|---------|
| `firesend` | Developer Options → Firesend room `10325` → Downloader → app code | Fire TV |
| `downloader` | Play Store Downloader → app code | Google TV / Android TV sticks and boxes |
| `browser` | Browser → `aftv.news/<code>` → install the APK | Android phones and tablets |
| `assisted` | TV's own store → email us the MAC and device key | Bare Smart TV |

Method copy may contain a `{store}` token, filled from the sub-device, so the
one `assisted` method serves any TV brand without being written twice.

A step is a plain string or `{ text, code }`; `code` renders oversized and
monospaced, because these get typed on a TV remote from across a room.

The app list always offers three apps to fall back through — IBO Player `617725`,
Smarters `9469460`, `6573365` — rendered as bare codes for Downloader routes,
as `aftv.news/` addresses on the browser route, and as plain app names on the
assisted route where there is no code to type.

### Buying advice and WiFi

`family.look` renders at step 2 as "Buying one? What to look for", with
`WIFI_TIPS` below it.

**Written for customers, not for people who read spec sheets.** "3GB of RAM,
dual-band 5GHz" reads as "at least 3GB of memory" and "look for WiFi 6 or
dual-band on the box" — the jargon survives only where it is literally the
wording printed on the packaging, because that is what makes it useful in a
shop. The WiFi advice talks about walls and the network name ending in 5G,
not bands and line of sight.

**Everything leads on WiFi.** Virtually every customer watches over wireless,
and virtually every picture complaint we are asked about is a WiFi problem
wearing a device problem's clothes. Ethernet is mentioned once, as a reason to
prefer a box over a stick, rather than being the headline advice.

### Purchase links

`family.buy` gives sticks and boxes two options each, from Takealot and Amazon
South Africa, rendered under "Ones we know work". Phones and tablets carry none
— customers already own those.

Every URL was checked against the retailer's own product data rather than
trusted from a search result: Takealot's `product-details` API for the title and
an `is_add_to_cart_available` stock check, and the Amazon product page for the
title. Four of the ten candidates found by search were dead listings or out of
stock and were discarded. A note under the links says stock changes and points
back at the specification list, so the panel degrades gracefully as listings
expire.

One buying warning is called out on its own, on the Fire TV sub-device: Amazon
is moving its newest sticks to software that can only install from Amazon's own
store, so our app will not go on them. The 4K Max and 4K Plus are the last
models that work. It deliberately avoids naming Vega OS — the customer needs the
model numbers, not the codename. Getting this wrong costs a customer the price
of a stick.

## "Why you need a device"

`WHY_A_DEVICE` holds three points, rendered at step 1 of Setup only:

1. AfriStream is a login, not a box — no hardware in the post, no cable.
2. A player app turns that login into television, and the app has to be
   installed somewhere.
3. That somewhere is your device, because most televisions cannot install the
   app themselves.

It is the commonest misunderstanding at sign-up. It shows at step 1 and nowhere
else — once someone is in the flow, the rationale is clutter.

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
- Setup gates each step: no sub-picker before a family, no steps before a
  sub-device. Exactly three families; three of the four TVs & Sticks entries
  carry the Recommended badge.
- Each method is checked through a device that uses it — Fire TV shows the
  Firesend stages, room code and Vega warning; a Google TV stick skips Firesend
  entirely; an Android phone installs from the browser with no Downloader
  anywhere; a bare Smart TV is told it must be registered and gets no code.
- Step 2 carries the buying advice, the purchase links and the WiFi guidance;
  step 3 always offers three apps to fall back through.
- The progress rail walks back a step at a time, and "Start again" clears both
  fields.
- "Why you need a device" shows at step 1 and is gone by step 2.
- The Devices tab is absent from the nav.
- Download: both platform panels render and the page points at Setup.
- Free Streaming: heading renamed, neither the region nor the device control is
  present, content filters are exactly All/Movies/Series/Sport, and the category
  filter narrows the grid to that category.
- Apps schema test updated — no `regions` key, content values within the new
  vocabulary, at most 4 per category.
- Editor Picks: each band is isolated in turn against a five-pick fixture
  (6.4, 7.6, 8.1, 8.5, 9.1) and asserted to exclude the bands either side.
