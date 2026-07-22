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
> Apps used via AfriStream. They do not provide any access to the Streaming Apps
> provided.

Styled as an amber advisory panel so it reads as a caveat rather than as more
credential chrome, and placed above the credentials so it is read before they
are copied. It renders in every credential state that shows credentials
(`ready` and `demo`), not in the `empty` or `error` states.

## Setup tab (new)

A two-step flow held in one section, driven by a single new state field
`setupDevice` (`''` until a device is chosen).

**Step 1 — choose your device.** A grid of six tiles:

| key | label |
|-----|-------|
| `firestick` | Amazon Fire TV / Firestick |
| `android-tv` | Android TV & TV Boxes |
| `android` | Android Phone or Tablet |
| `ios` | iPhone or iPad |
| `smart-tv` | Smart TV (Samsung / LG) |
| `desktop` | Windows or Mac |

**Step 2 — install instructions.** Once a device is chosen, the tiles collapse to
a compact selected-state row (with a "Change device" affordance) and numbered
install steps for that device render below, followed by a shared "Sign in"
panel telling the user to enter the username and password from the Account tab,
with a button that switches to it.

Step content is authored from material already in this repo — the Downloader
codes (`569138`, `6573365`, `617725`, `9469460`) and the login guidance in
`TROUBLESHOOTING` and `TIPS_DATA`. Two devices carry an extra warning:
Firestick and Android TV need "Apps from Unknown Sources" enabled first.

The steps are copy, not verified installation procedure. **Luke must confirm the
per-device steps before merge.**

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

### Region filter removed

The region control, `state.appsRegion`, the `apps-region` handler, the region
clause in `filteredApps()`, the region term in `activeFilterSummary()`, the
`APP_REGIONS` vocabulary and the `regions` key on every entry in
`data/apps.json` all come out. The human-readable `availability` string stays —
it is still shown in the detail drawer, which is now the only place regional
scope is communicated.

### Directory trimmed to 8 apps

The cap is **at most 4 apps per content category**. Because an app tagged both
Movies and Series consumes a slot in both buckets, this lands at 8 apps:

| App | Movies | Series | Sport |
|-----|:------:|:------:|:-----:|
| Tubi | • | • | |
| Plex | • | • | |
| Kanopy | • | | |
| ARTE | • | • | |
| BBC iPlayer | | • | • |
| DAZN | | | • |
| Red Bull TV | | | • |
| Olympics | | | • |
| **Total** | **4** | **4** | **4** |

The 22 other entries are removed from `data/apps.json`. YouTube is excluded by
explicit instruction. Blurbs that sold a service on its live-channel lineup
(Plex) are reworded to describe the on-demand catalogue instead, since Live TV
is no longer something the directory surfaces.

There is deliberately **no cap per device type**. Nearly every app runs on all
five device classes, so a 4-per-device cap would force the whole directory down
to four apps. The device filter therefore still returns up to 8 results.

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
- `bluegroup-project-afristream.php` — `default_tab` whitelist, docblock, admin
  help string, version
- `tests/portal.spec.js` — nav labels, apps schema and filters, new tab tests,
  rating band tests
- `CHANGELOG.md`, `package.json` — 0.16.0 (minor: new features)

## Testing

Playwright, against the local preview harness:

- Nav renders exactly the seven expected labels; Tips and Troubleshooting are
  absent from the nav but still reachable via `default_tab`.
- Account shows the disclaimer above the username.
- Setup: no steps before a device is picked; picking Firestick shows Firestick
  steps; changing device swaps them; the sign-in button lands on Account.
- Download: both platform panels render.
- Free Streaming: heading renamed, no region control, content filters are
  exactly All/Movies/Series/Sport, each category filter returns at most 4 cards.
- Apps schema test updated — no `regions` key, content values within the new
  vocabulary, at most 4 per category.
- Editor Picks: selecting ★ 8–8.9 returns only picks in that band, and a 9.x
  pick is excluded.
