# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.19.0] - 2026-07-23

### Added

- **Buy links on the affiliate card.** The two AfriStream checkout links sit under the referral link with the affiliate's referral code already appended, so a sale made through one is credited to them. Each is labelled with who it is for — **AfriStream Subscription** (for users that have their own device) and **AfriStream Subscription & Setup** (for users that need us to buy a device for them). The code is read off their own referral link rather than hardcoded, so if SureCart renames the tracking parameter the buy links follow it instead of quietly attributing to nobody.

  The link is shown shortened — `afristream.io/checkout/?…&ref=YOURCODE` — because the real one is a wall of percent-encoded price ids that reads as noise and cannot be checked at a glance. Shortened it still shows the two things that matter: that it goes to AfriStream's checkout, and that it carries their code. It is a live link, so it can be opened and tested, and Copy link puts the full, exact URL on the clipboard.

- **Sub-categories on Editor Picks.** A Category row offers Action, Adventure, Animation, Comedy, Romance and Thriller, alphabetically, alongside the existing type and rating filters. Every pick answers to exactly one of them: TMDB returns a far wider genre list than six, so crime, mystery, horror and drama fold into Thriller, the speculative genres and family into Adventure, war and westerns into Action, and anything with no near neighbour — documentaries, history, music — falls back to Adventure rather than dropping out of the filter. Cards still show their real genre.

### Changed

- The affiliate card's heading, rate line and dashboard button share one line, and the button now reads **Open Dashboard**. Below roughly 380px the button drops under the text rather than squeezing the rate sentence.

## [0.18.1] - 2026-07-23

### Fixed

- **The calculator no longer caps the headcount at a thousand people a year.** Anything above it produced the same answer as a thousand, which reads as the calculator being broken rather than as a limit. The figure is only floored at one now.

### Changed

- The affiliate calculator opens in **Rands**, which is where most affiliates are, falling back to the store's own currency if the rate feed cannot offer them. The switcher still moves it to Dollars, Pounds or Euros.

- The affiliate calculator's tiles say what they are measuring. The total is now **10 year total earnings** rather than "Total potential earnings", and the two yearly figures are **Year 1 annual revenue** and **Year 10 annual revenue**, so neither can be read as a running total.

## [0.18.0] - 2026-07-23

### Changed

- **The affiliate calculator now works the way the product actually sells.** Subscriptions run by the year, so the projection does too: it asks how many people you sign up in a year rather than in a month, offers only annual plans, and projects ten years instead of twelve months. A **Total potential earnings** figure sits alongside year one and year ten.

- **The chart is a line rather than a row of bars.** Ten years of recurring commission is a shape — each year's sign-ups sitting on top of everyone still paying from the years before — and a climbing line says that, where separate bars only invite you to compare their heights. Point labels are shortened ("£1.8K") because the exact figures are in the tiles above.

### Fixed

- **Typing in the calculator's number boxes no longer reverses what you type.** The panel re-renders on each keystroke and puts the caret back where it was, but a `type="number"` input refuses the browser API that does it, so the caret silently returned to the start and "100" came out "001". Both boxes are now text inputs with a numeric input mode, which keeps the phone keypad and fixes the caret.

## [0.17.1] - 2026-07-23

### Fixed

- **A newly approved affiliate no longer waits five minutes for their tab.** The portal caches SureCart's answer per user so that clicking between tabs does not call the API every time, but five minutes was long enough to be mistaken for the feature being broken — approve someone, refresh, and nothing happens. The cache is now a minute, which is ample for tab switching and short enough that an approval feels immediate.

- **Saving the default commission rate now takes effect at once**, rather than waiting out the cache. The per-user cache keys are namespaced by a counter that saving the setting bumps, so every cached answer is abandoned on the next page load.

## [0.17.0] - 2026-07-23

### Added

- **An Affiliates tab**, shown only to people SureCart confirms are active affiliates. It carries a link straight to their SureCart affiliate dashboard, their referral URL with a copy button, and their commission rate written as a sentence — including whether it keeps paying on renewals, which is the part a bare percentage never says.

  Below that, a profit calculator. Pick the plan a referral signs up to (pulled live from the store's own recurring prices, so it always matches what you charge), say how many people you sign up a month, and it projects each payment, month one, month twelve and the first year, with a chart of the staircase recurring commission builds. Annual plans are modelled on their real renewal month rather than smeared across twelve. Custom per-affiliate rates come through from SureCart automatically; a **Default affiliate commission (%)** setting stands in for the store default, which SureCart does not expose to plugins.

  Earnings can be read in **Rands, Dollars, Pounds or Euros**, converted from the store's own currency at the European Central Bank's published rates through a keyless, permanently free feed, cached for a day. The plan price beside the picker stays in the currency the customer is actually charged, and the panel says plainly that SureCart still pays out in the store currency, so what lands in the account moves with the exchange rate. If the rate feed cannot be reached the switcher simply does not appear — a converted figure nobody can stand behind is worse than none.

### Changed

- The public **Affiliates** link has been removed from the portal nav. It advertised the programme to every subscriber; the tab that replaces it appears only for affiliates, and only after SureCart says so.

### Fixed

- The calculator now honours a one-off or time-limited commission structure instead of always projecting as if commission runs forever, and shows a plain message pointing at SureCart — rather than a wall of £0.00 — when no rate is known yet.

## [0.16.1] - 2026-07-23

### Changed

- In "Why you need a device", the step number now sits on the same line as its heading rather than stacked above it, so each of the three points reads as one title instead of a number floating on its own row.

## [0.16.0] - 2026-07-22

### Added

- **A Setup tab**, sitting immediately right of Account — one four-step flow: pick your device, tell us which one, install the app, choose your app. A progress rail across the top shows where you are and walks back a step at a time.

  Three device families: **TVs & Sticks**, **Android Boxes** and **Android Devices**. Under TVs & Sticks sit the three sticks we recommend — Amazon Fire TV Stick, Xiaomi TV Stick 4K, and any other Google TV stick — plus a bare Smart TV for people with nothing plugged in. iPhone, iPad and Roku are deliberately off the flow, handled by a line pointing at support.

  Four install routes, shared across the eight sub-devices rather than written out per device. **Fire TV:** Developer Options → Firesend room `10325` → Downloader → app code. **Google TV and Android TV:** Downloader from the Play Store → app code. **Android phone or tablet:** straight from the browser at `aftv.news/<code>` — no Downloader and nothing in the Play Store. **Bare Smart TV:** install a paid app from the TV's own store, then send us the MAC address and device key so we can register the set.

  Step 4 always offers three apps to fall back through — IBO Player `617725`, Smarters `9469460` and `6573365` — so a customer whose app will not connect can try the next without opening a ticket. Codes render oversized and monospaced because they get typed on a TV remote from across a room.

  Buying advice sits at step 2, next to the thing being chosen, written as specifications rather than model numbers so it holds as ranges refresh. It leads on WiFi throughout, since that is how virtually everyone watches, with a dedicated panel on 5GHz versus 2.4GHz, line of sight, and sticks suffocating behind a large television.

  One warning worth calling out on its own: **Amazon has confirmed that future Fire TV Sticks move to its own Vega system, which cannot install apps from outside the Amazon store at all.** The 4K Max and 4K Plus are the last models that work with us, and the Fire TV route says so before anyone buys the wrong one.

- **Setup opens by explaining why a device is needed at all** — that the subscription is a login rather than a box, that a player app turns that login into television, and that the app has to run on something. It is the commonest misunderstanding at sign-up and nothing said it before. It shows at step 1 only; once you are in the flow the rationale is just clutter.

- **The Account tab now states the connection rules**, next to the credentials rather than buried in a support thread: one connection plays on one screen at a time, and two or more must be used in the same household on the same internet connection, the sole exception being one on home WiFi and one on mobile data.

- **A Download tab**, explaining how to add the portal to an iPhone or Android home screen so it opens like an app. No app store and nothing to update — this adds a home-screen shortcut, since the portal ships no web app manifest.

- **The Account tab now says what the credentials are not for**, in a notice placed above the username so it is read before anything is copied: they work with the apps used via AfriStream and give no access to the Free Streaming apps listed elsewhere in the portal.

### Changed

- **The top bar keeps the active tab centred.** On a phone the tab strip always
  started at "Account", so the tab you were actually on was often off-screen. It
  now scrolls the active tab to the middle, clamped at both ends — the first tab
  stays against the left edge and the last against the right, rather than being
  dragged into the centre with dead space beside them. Scroll snapping is gone,
  since a proximity snap tugged the tab back off centre the moment the scroll
  settled.

- **The top bar can be dragged with a mouse.** It reuses the same pointer
  handler as the poster rows, so it gets the 5px threshold and click
  suppression for free — dragging across a tab scrolls the bar instead of
  navigating. The grab cursor only appears when there is somewhere to drag to.

- **The Setup progress rail sticks below the top bar** as you scroll a long
  walkthrough, with even space above and below, so you keep track of which of
  the three steps you are on. It scrolls sideways as one row rather than
  wrapping, which on a phone would have taken a third of the screen.

- **Setup is three steps, not four.** Choosing an app is part of installing, not
  a stage of its own, so the app list moved inside step 3 underneath the install
  stages that refer to it.

- **The buying advice is written for customers, not for people who read spec
  sheets.** "3GB of RAM, dual-band 5GHz" became "at least 3GB of memory" and
  "look for WiFi 6 or dual-band on the box"; the WiFi guidance talks about walls
  and the network name ending in 5G rather than bands and line of sight. The
  Fire TV warning no longer names Vega OS — it says Amazon is changing the
  software on its newest sticks so our app will not install, and that the 4K Max
  and 4K Plus are the last ones that work.

- **Sticks and boxes link straight to something you can buy.** Two options each,
  from Takealot and Amazon South Africa, checked against the retailers' own
  product data so every link resolves to a real listing that was in stock at the
  time of writing: the Fire TV Stick 4K Max and Xiaomi TV Stick 4K (2nd Gen) for
  sticks, and the Xiaomi TV Box S in 3rd and 2nd generation for boxes. Phones
  and tablets get none — customers already own those.

- **"Profile" is now "Account" and "Free Apps" is now "Free Streaming".** The internal section id stays `profile`, so any page already pinned with `default_tab="profile"` keeps working.

- **Tips & Tricks and Troubleshooting are hidden from the nav.** Both sections, their content and the `[troubleshooting_guide]` shortcode are untouched and still reachable through `default_tab="tips"` / `"help"`, so bringing them back is a two-line change.

- **Free Streaming is down to eight apps, capped at four per category.** Live TV and Documentaries are retired as categories, leaving Movies, Series and Sport with four apps each: Tubi, Plex, Kanopy and ARTE.tv for films, plus BBC iPlayer for series, and DAZN, Red Bull TV and the Olympics app for sport.

- **Category is now the only filter on Free Streaming.** The device pills and the region dropdown are both gone, and the `regions` array comes off every entry. Devices are still listed on each card and stepped through in the app drawer — they just no longer narrow the grid, since choosing a device is what the Setup tab is for. Regional scope is stated once, in the `availability` line inside the drawer, instead of being both a filter and a sentence.

### Fixed

- **Setup looked as though step 4 could never be reached.** Steps 3 and 4 share
  a screen — the install steps refer to "the app you pick in step 4 below" — but
  the progress rail only ever marked step 3 as current, so step 4 read as a
  place you could not get to. Both are now marked current together, and each
  section on the page carries a "Step N of 4" label so the page and the rail
  agree.

- **Editor Picks rating filters were thresholds pretending to be bands.** Choosing "★ 7+" returned everything at 7 and above, so the 8s and 9s came with it and narrowing the filter barely narrowed the grid. Each band is now exclusive — ★ 8–8.9 returns only the 8s — with ★ 9+ left open-ended so a perfect 10 is not stranded outside every band, and a new ★ 6–6.9 band at the bottom. Bands with nothing behind them are still not offered.

## [0.15.1] - 2026-07-22

### Fixed

- **The watchlist sync only ever read the first page.** IMDb renders at most 250 rows per page and puts the rest behind `?page=N`; the scrape scrolled that first page to exhaustion and stopped, so a 302-title watchlist came back as 250 no matter how patiently it scrolled. It now walks the pages until the reported total is covered.

  The total is also read properly at last. IMDb renders it as a two-item inline list — "1 – 250" then "302 titles" — whose combined `textContent` reads "1 - 250302 titles"; the items are now read separately instead of regexing the run-together string, which is where the nonsense six-figure total came from.

  Editor Picks now carries all **302** titles (301 resolved; `tt0096548` has no TMDB entry).

## [0.15.0] - 2026-07-22

### Added

- **The watchlist is now an accumulating local copy.** `npm run sync-watchlist` merges each scrape into `data/editor-picks-ids.txt` instead of replacing it: new titles are appended, ratings are refreshed, and nothing is ever dropped. IMDb only renders 250 rows of a public watchlist, so a scrape is a window onto the list rather than all of it — merging lets the list grow past that ceiling as titles are added, and makes a failed or partial scrape a no-op instead of data loss. Removing a title is now a deliberate edit to the file.

  A scrape that returns nothing, or only a handful of rows, can no longer shrink the list at all.

- **Resolved titles are cached between bakes.** Each baked pick records the IMDb ID it came from, so `npm run bake-picks` re-uses everything it resolved last time and only calls TMDB for titles it has not seen. Adding a few films to a 250-title list is now a 2-second build step rather than 250 round-trips. `npm run bake-picks -- --refresh` forces the full re-resolve when artwork and TMDB metadata should genuinely be redone.

## [0.14.0] - 2026-07-22

### Changed

- **Editor Picks are now resolved through TMDB at build time**, not on the live site. `npm run sync-watchlist` scrapes the IMDb watchlist as before, then hands the IDs to a new `npm run bake-picks` step that resolves each one and writes `data/editor-picks.json` into the plugin — the same pattern `apps.json` and `sports-listings.json` already use. Serving Editor Picks is now a file read (~30ms for 130 titles) instead of one TMDB round-trip per title, so the page is complete on first paint with no filling-in and no polling.

  The runtime resolver, the partial/poll handling and the resolve lock all stay, because the WP admin override box can hold hand-entered IDs that have no baked copy. `bake-picks` can also be re-run on its own to refresh artwork and ratings without re-scraping IMDb.

### Fixed

- **The watchlist sync silently truncated the list.** The scroll loop trusted IMDb's count label to decide when it had everything, and that label is not dependable — it has read as "1 – 25 of 130 titles" (taking the leading `1` as the total ends the loop on its first pass) and as a nonsense six-figure number. The label is now advisory only: the loop scrolls until the row count genuinely stops growing, and the sync refuses to overwrite the ID list with a shorter scrape at all, so a partial render can no longer destroy IDs that only a working scrape can recover.

  Fixing it recovered **120 titles the previous scrape had been silently dropping** — Editor Picks goes from 130 to 249 resolved titles (the current list is a strict superset of the old one; one title has no TMDB entry).

## [0.13.0] - 2026-07-22

### Changed

- **Renamed the plugin to "BlueGroup | AfriStream Portal"**, and the plugin slug from `afristream-portal` to `bluegroup-project-afristream` to match the repo. The main file, the plugin folder inside the zip, the text domain, the registered style/script handles, the settings page slug and the deployment artifact (`bluegroup-project-afristream.zip`) all follow.

  The `[afristream_portal]` shortcode, the `afristream/v1` REST namespace, the `afristream_*` option names and the `.afristream-portal` CSS class are deliberately unchanged — pages using the shortcode keep working and saved settings carry across. Because WordPress keys plugins on the folder name, this installs alongside the old one rather than over it: deactivate and delete **AfriStream Customer Portal** after activating the new plugin.

### Fixed

- **Editor Picks stopped short of the full watchlist.** Resolving titles through TMDB is time-budgeted so a cold cache can't blow PHP's execution limit, and a truncated run was flagged `partial` — but the front end asked once and the truncated payload was cached and re-served, so a 130-title watchlist showed as roughly 60 and stayed there. The front end now keeps polling while the server reports `partial`, each pass resuming from the warm per-title cache, and a resolve lock stops concurrent visitors repeating the same work. The settings hint no longer claims a 60-title limit; the real cap is 300.
- **The portal could still push a phone's page wider than the screen.** The full-width fix only reached the portal's immediate parent, so a dashboard shell that nests shortcode output several containers deep (SureCart's customer dashboard) left every wrapper above it stacking its own gutter on top of `width:100%` — the document overflowed and the browser zoomed the whole page out. The reset now walks up to three levels of containing ancestors (never `html` or `body`) and clears `min-width` so a wrapper that happens to be a flex or grid item can actually shrink.

## [0.12.0] - 2026-07-22

### Added

- New **Free Apps** tab, sitting between Editor Picks and Tips & Tricks: a curated directory of free and free-tier streaming apps covering sport, movies, series, documentaries and live TV, filterable by device type (Smart TV, consoles, sticks and boxes, tablets, phones), content type and region.
- Each app carries a plain-language description, the devices it installs on, where it is available, whether it is fully free or a free tier, and per-device install steps — shown in the existing detail drawer, with a link out to the official site.
- The directory ships as `data/apps.json` inside the plugin (30 entries, each checked against the vendor's own site or store listing) and is fetched lazily the first time the tab is opened, with the plugin version appended so a new build can't be served from a stale cache. Region availability is recorded as coarse groups for filtering plus a precise free-text note, because free services are heavily region-locked and a country-level filter would need a hundred-entry dropdown.
- App logos are deliberately not used — the cards use the portal's existing initial-on-gradient treatment, tinted by the app's primary content type, so nothing is loaded from a third-party host.

## [0.11.0] - 2026-07-22

### Added

- **A real sports TV guide, baked in at build time** — a new `npm run sync-listings` step reads the published EPG for SuperSport and Sky Sports via [iptv-org/epg](https://github.com/iptv-org/epg) and writes `data/sports-listings.json` into the plugin zip. That answers the channel-first question the fixture feeds can't — what is actually on SuperSport Cricket at 18:00 — and it is the only permanently free source of it; every commercial sports API puts broadcaster listings behind a paid plan. The current guide carries 1,263 listings across 29 channels over three days.

  The grabber is a Node project of its own that clones ~150MB and takes about half an hour, so like the IMDb watchlist sync it runs at build/deploy time rather than from WordPress, cached in a gitignored `.cache/`. What ships is only the resulting JSON.

  Turning a broadcaster's EPG into something a listings row can show takes some cleaning: highlight reels and repeats are dropped (a sports channel's day is mostly the same fixture's highlights on a loop — 1,415 such programmes were filtered from the current guide), fixture names come from the fuller programme description rather than the broadcaster's shorthand ("Int CRI '26: WI v NZL 5th ODI" becomes "West Indies vs New Zealand 5th ODI"), the sport is read from the guide's own category tags, and DStv's per-market channel lists and Sky's separate UK and Ireland feeds are collapsed to one entry per channel so nothing appears twice.

### Changed

- **Sport listings now merge three free sources rather than two** — ESPN for fixtures and US networks, TheSportsDB for broadcasters elsewhere, and the baked guide for what is actually on. The baked guide is capped at 6 of the row's 12 slots: three days of SuperSport and Sky Sports is hundreds of programmes, every one of them sooner than most fixtures in the live feeds, so without that cap the row would sort itself into a single platform's schedule instead of a spread of what is on around the world.

### Notes

- The plugin drops any programme that has already finished and ignores the baked guide entirely once it is more than 10 days old, so a stale build quietly falls back to the two live feeds rather than showing last week's schedule as if it were current.
- `npm run sync-listings` needs `git` on PATH. It is a build-time step only — nothing about it runs on the WordPress server, and no API key, account or paid tier is involved in any of the three sources.

## [0.10.0] - 2026-07-22

### Added

- **Affiliates link in the top bar** — a sixth item at the end of the tab list, opening the AfriStream affiliate programme (`afristream.surecart.com/affiliates/`) in a new tab. Unlike the other five it isn't a portal section, so it renders as a real anchor rather than a button: it never takes the active underline, it carries `rel="noopener noreferrer"` (without which the opened page gets a `window.opener` handle back to the portal), and it's marked with a ↗ plus an accessible name saying it opens in a new tab.

## [0.9.1] - 2026-07-22

### Fixed

- **The portal scales correctly on a phone again.** The rule that lifts a host theme's width cap (so the portal fills the content area rather than sitting in a narrow column) set `width: 100%` without sizing the border box. On a `content-box` wrapper — the browser default, and what plenty of theme containers still are — the theme's own horizontal padding is then added on top of that 100%, so the wrapper ends up wider than its parent: on a 390px phone with a 24px theme gutter the document came out 438px wide. The browser's response to a document wider than the viewport is to zoom the whole page out to fit, which is why the portal rendered shrunk with the page scrolling sideways. The rule now sizes the border box, so "fill the content area" can no longer overflow it.
- **The filters drawer no longer lets the page scroll behind it.** The scroll-lock only keyed on the detail panel, so with the filters drawer open the catalogue still scrolled underneath — most obvious over the scrim beside the panel, where a wheel or a drag went straight to the page. The lock now covers both overlays, which are equally modal. (Scrolling *inside* the panel was already contained by `overscroll-behavior`; it was the area around it that leaked.)
- **Poster grids no longer collapse to one title per row on a phone.** The Editor Picks, search-result and collection grids are `auto-fill` with a pixel floor (146–150px), which assumed the portal owned the full viewport width. It doesn't: the host theme's gutter and the portal's own gutter both come off the top, leaving roughly 295px of content on a 390px phone — just under the ~316px two columns need — so `auto-fill` quietly dropped to a single column of roughly 300×450 posters. Mobile now pins the count at two columns instead of inferring it from a width the portal can't predict, and the columns use `minmax(0, 1fr)` so they can always shrink to the space available rather than overflowing the narrowest phones.

## [0.9.0] - 2026-07-22

### Added

- **Today's Pick** — the Editor Picks hero is no longer a fixed "Editors' No.1". It now draws one title from the list at random, seeded from the calendar date so everybody sees the same pick all day and a new one turns over at midnight. It respects the active filters, and the ranked list starts directly below it.
- **Rating filter on Editor Picks** — a second pill group (Any / ★ 7+ / ★ 8+ / ★ 9+) alongside the type filter. Steps only appear when they would actually leave something on screen.
- **IMDb ratings on the synced watchlist** — `npm run sync-watchlist` now records each title's IMDb rating next to its ID (`tt0099348 8.0`), and the plugin prefers that over TMDB's own score for the card badge, the ordering and the rating filter. Note this is IMDb's public community rating; a shared watchlist doesn't expose the owner's personal ratings to an anonymous viewer.
- **Worldwide sports TV listings** — sport fixtures are now enriched with real broadcaster data from outside the US. ESPN's public scoreboard, which the portal already used, only ever names US networks, so viewers anywhere else saw the competition label where a channel should be. A second keyless, permanently free feed (TheSportsDB's public API, key `123`, no registration) now supplies channels such as Sky Sports Cricket, SuperSport, FOX Cricket and Sky Sport NZ, alongside the country each channel broadcasts in. Both feeds are merged and de-duplicated on a normalised fixture key, so "Arsenal at Everton" from one feed and "Everton vs Arsenal" from the other collapse into a single listing, and the row that actually names a broadcaster wins. The sport row grows from 8 listings to 12.
- **Broadcast country on every sport listing** — sport cards show the channel with the country it airs in underneath, and the detail panel gains a "Broadcast in" row.

### Changed

- **Editor Picks shows the whole watchlist** — the read-side cap went from 60 to 300, so a 130-title watchlist now renders in full instead of stopping at 60. Because each pick costs a TMDB lookup, resolution is now memoised per title for a week and the loop runs under a time budget: a cold cache serves what it has and finishes the list on a later request rather than risking PHP's execution limit.
- **Editor Picks are ordered by rating**, best first, with watchlist position only breaking ties. Rank badges renumber to match.
- **Editor Picks tags reduced to Movies, Series and Documentaries** — previously every genre in the list got its own pill (two full rows of them). The three types are mutually exclusive, so a documentary is filed under Documentaries and never also under Movies.
- **What to Watch rows grow when you pick a category** — "All" keeps the short summary rows, but selecting Movies, Series, Documentaries, Kids or New This Week re-backs that row from the full catalog (best-rated first, up to 40) instead of the 10-item trending slice.
- **Collections now match their own descriptions.** "True Crime Deep Dive" was pulling in every Thriller and every Documentary, so nature docs like *My Octopus Teacher* and *Penguin Town* were filed as true crime; it is now crime and mystery only. "Big Match Build-Up" promised documentaries to watch before kick-off but nothing in the catalog marks a documentary as sport, so it was really just every documentary — it is now honestly named **Documentary Corner**. "Award Season Catch-Up" moved from ★7.5 to ★8.0, which was matching about a third of the catalog.

### Fixed

- **The detail and filter panels can be scrolled again.** Both are `position: fixed`, but a host page only has to put a `transform`, `filter` or `will-change` on any ancestor for that ancestor to become the containing block — at which point `top`/`bottom` stop meaning "the viewport", the panel stretches to its full content height and there is nothing left to scroll. Most visible on a collection with a long poster grid. The panels are now sized in viewport units and scroll in a dedicated body region, so they behave the same however the host page is built.
- **The watchlist sync no longer stops short.** IMDb renders 25 rows at a time and the scroll loop gave up an appended page early, so a 130-title watchlist synced as 125. It now steps to the lazy-load sentinel rather than jumping past it, clicks a "more" button if IMDb shows one instead, is far more patient about append lag, and warns (and exits non-zero) if the final count is under the list total.

### Removed

- **Live TV Channels** — the row is gone from What to Watch, and the channels no longer appear as entries in search results or the type facet.

### Notes

- TheSportsDB's free tier returns a single row per `eventstv` query with no pagination, so the plugin fans out one query per sport per day (today and tomorrow) and stitches the rows together. The loop shares the existing time budget used by Editor Picks, so a cold cache can never blow PHP's max execution time; results are cached for 2 hours as before. No API key, no account and no paid tier is involved.

## [0.8.0] - 2026-07-12

### Changed

- **Mobile-optimised top bar** — the header navigation is now a dedicated horizontally scrollable tab list. The tabs sit in their own scroll container (hidden scrollbar, touch momentum, per-tab snap) so every section stays reachable on a phone without the page itself scrolling sideways. The secondary "Annual · Active" plan badge, which previously crowded the bar on narrow screens, drops away below 600px and the tabs pick up a little more spacing; the desktop layout (tabs left, badge right) is unchanged.
- **Smoother horizontal rows on touch** — the poster, sport, channel and collection rows get touch momentum scrolling so they flick naturally on mobile.

## [0.7.0] - 2026-07-12

### Added

- **More sports** — Cricket, Golf, Rugby and Soccer now always appear. Cricket is new (ICC World Cup / T20 World Cup / Champions Trophy added to the ESPN league map in both the plugin and the preview server); Golf and Rugby, which previously only showed when ESPN had live fixtures, plus a fresh Soccer and Cricket entry, are now part of the curated fallback so all four are visible even off-season.
- **Real Collections** — the Collections row is now backed by live data instead of decorative "N titles" labels. Each collection is a query over the fetched TMDB catalog (e.g. Family Movie Night, True Crime Deep Dive, Award Season Catch-Up), showing a real, dynamic count; opening a collection now renders the actual matching posters. Collections with too few matches auto-hide.
- **Editor Picks from an IMDb watchlist** — a new `npm run sync-watchlist` step (a real browser via Playwright) reads a shared IMDb watchlist and writes the ordered title IDs into the bundled `data/editor-picks-ids.txt`, which the plugin uses as the default Editor Picks list (the admin box still overrides). This removes the need to paste IMDb IDs by hand; IMDb's watchlist is behind a WAF and can't be fetched by the WordPress server directly, so the sync runs at build/deploy time. The pick cap is raised from 24 to 60.

### Changed

- **"Football" renamed to "Soccer"** as a sport category (ESPN's soccer leagues), so it matches everyday naming; American Football is unaffected.
- **Editor Picks header** now matches the What to Watch header — title, description and a single row of tag pills — with no search box and no Filters button; selecting a tag filters the list and "All" resets it.
- **Page-scoped layout fix** — the portal now removes the host dashboard's right-column padding (`.dashboard-right`) so it sits flush, applied only on pages that render the shortcode (attached to the portal stylesheet, which only loads there).

## [0.6.0] - 2026-07-11

### Added

- **Real per-user credentials in the Profile tab** — the portal now shows each logged-in subscriber's actual app username and password, resolved from their assigned ACF `license` posts, via a new authenticated REST route `afristream/v1/credentials` (fetched with the REST nonce and same-origin cookies). The old hardcoded placeholder profiles are gone; the tab shows a clear "no profile assigned" state when a user has no active license, and the built-in demo profiles now appear only in the credential-less local preview.
- **License management (merged from the code-snippets plugin)** — so that plugin can be retired. Ported into `includes/licenses.php` and `includes/shortcodes.php`, with every ACF call guarded by `function_exists()` so the plugin degrades gracefully if ACF is inactive: the Users-table **Active Licenses** column; the License post-type **Expiry Date / Mobile Active / Connected User** columns (sortable); prevention of assigning a license already held by another user (relationship-field filtering + save-time validation); and auto-unassignment when a user is deleted. The `[user_acf_fields]` and `[troubleshooting_guide]` shortcodes are ported too (function names re-prefixed to avoid any clash), so existing pages keep working.

### Changed

- **Brand refresh** — the portal is restyled in the AfriStream brand colours (deep purple `#65009F` and magenta `#CD2DF5`), replacing the previous blue palette across headers, buttons, accents, links and gradients. Semantic colours (live-red, rating-gold, active-green, TMDB logo) are unchanged.
- **Troubleshooting tab** now shows the detailed, step-by-step guide (Restart → Clear Cache → Reconnect Playlist → Playlist Not Working → Alternative App with Downloader codes → Important Notes) as a single accordion, replacing the previous lighter device-tabbed version.

### Fixed

- **Profile header text** now stays white on the dark hero panel; a host theme's heading-colour rule was overriding it to near-black. Portal headings are forced to the brand colour (and white on dark) with a scoped `!important` rule so the theme can't win.

## [0.5.1] - 2026-07-11

### Fixed

- **Full-width rendering** — the portal now fills the full width of whatever content area it's placed in, instead of being boxed into a narrow, centered column by the surrounding theme or dashboard shell (e.g. SureCart's customer dashboard). A `:has()`-scoped rule lifts the width cap on the element that directly wraps the shortcode output, so only that wrapper is affected — the rest of the page, including any sidebar/account menu, is left untouched.

## [0.5.0] - 2026-07-09

### Added

- **Editor Picks filters** — the Editor Picks page now carries inline filter tags for Type (Movies / Series) and Genre, plus a sort control (Editor's order, Top Rated, A–Z, Newest). Only the types and genres actually present in the list appear as chips. The premium No.1 hero shows in the default view and collapses while any filter or sort is active, so every matching pick is shown in a wrapping grid (replacing the previous off-screen horizontal rail). Front-end only — the picks already carried the type, genre and rating data.
- **Card detail panel** — every card in What to Watch and Editor Picks is now clickable (mouse, or keyboard via Enter/Space), sliding in a right-hand detail panel with the item's poster, genre, rating, year, country and type. For films and series the panel lazy-loads a plot synopsis from TMDB via a new `afristream/v1/detail` endpoint (cached 24h; empty when no key or TMDB is unreachable, and failed lookups are not cached so a hiccup never blanks the panel), with a brief loading state and per-item client caching. Sport fixtures, Live TV channels and Collections open panels scaled to their own data. Closes via the ✕ button, the scrim, or Escape. The panel is a proper modal — it traps Tab focus, locks background scroll, and returns focus to the originating card on close. Every mapped item now carries its TMDB id to support the lookup.

## [0.4.0] - 2026-07-08

### Added

- **Editor Picks** — a new page curated from a hand-picked list of IMDb title IDs. The editor pastes the `tt…` IDs from their IMDb watchlist into a settings field (IMDb's watchlist page is behind AWS WAF and can't be read automatically), and the plugin resolves each through TMDB for consistent artwork, presenting them with a premium editorial hero and a ranked, drag-scrollable rail. New REST endpoint `afristream/v1/editor-picks` (cached 12h with a last-good fallback so a hiccup never blanks the page), and a deterministic fixture for tests.
- **Mouse drag-to-scroll** on every horizontal row in What to Watch and Editor Picks — click and drag to pan, with a small threshold so clicking a poster still works.
- **Deep, filterable catalog** — the watch endpoint now returns a much larger catalog built from TMDB discover across ~14 origin countries, so the filters have real breadth. Cached 12h; falls back to the built-in lists when unavailable.

### Changed

- **What to Watch filters overhauled** — the drawer is now a cascading Type → Genre → Country → Decade set, with Sorts (Recommended, A–Z, Newest, Top Rated) always available. Country is real origin-country data from the deep catalog; Year is replaced by Decade; the separate Category concept is folded into Genre. Facets remain dependent, so each choice narrows the next.
- **Sport is filterable** — each sport event now carries a sporting code and host country, so choosing Type → Sport reveals a "Sport Type" facet (Football, Motorsport, Basketball, Tennis, Rugby, Golf and the other main codes) and sport joins the Country facet.

## [0.3.0] - 2026-07-08

### Added

- Live & Upcoming Sport now spans a much wider set of global competitions from ESPN's keyless scoreboard API. Added LaLiga, Serie A, Bundesliga, Ligue 1, Europa League and MLS (football); MLB and NHL (North American majors); ATP and WTA tennis; the PGA Tour; and the AFL — alongside the existing World Cup, Premier League, Champions League, Formula 1, UFC, URC Rugby, NFL and NBA. This keeps the panel populated year-round as seasons rotate. Where ESPN publishes a broadcaster it is shown; where it doesn't (e.g. tennis, AFL) the entry falls back to the competition name.

### Notes

- Investigated dedicated free sports-TV-listings APIs (TheSportsDB, Sportmonks, SportsDataIO, XMLTV/EPG feeds) for richer per-region broadcaster data. None qualifies: TheSportsDB's free tier caps its TV endpoint to a single event per response and paywalls the useful V2 filter endpoints, and no other source is both permanently free and carries broadcaster data. ESPN's scoreboard feed remains the best free option, so this release broadens its coverage instead.

## [0.2.0] - 2026-07-07

### Added

- What to Watch now pulls real catalog data from TMDB (free, non-commercial tier): trending movies, trending series, and new releases/episodes, with real poster artwork and rating badges, refreshed twice a day. ([#1](https://github.com/blueworx-io/bluegroup_project_afristream/issues/1))
- New public REST endpoint `afristream/v1/watch` — the plugin fetches TMDB server-side (key stays out of the browser, responses cached 12h in a transient) and the portal falls back to its built-in curated lists whenever no key is configured or TMDB is unreachable. The key comes from the `AFRISTREAM_TMDB_API_KEY` constant in `wp-config.php` (or the `afristream_tmdb_api_key` option).
- The local preview server mirrors the endpoint at `/api/watch` (set a `TMDB_API_KEY` env var to see live data on localhost) plus a deterministic fixture mode used by two new Playwright tests covering the API-data path.
- TMDB attribution line — official TMDB logo plus the required statement — shown in the What to Watch section whenever live data is displayed.
- Settings page (**Settings → AfriStream Portal**) for entering the TMDB API key from wp-admin without `wp-config.php` access, with a connection status indicator, immediate cache refresh on save, a Settings link on the Plugins screen, and the shortcode reference. A `wp-config.php` constant still takes precedence when defined.
- Live & Upcoming Sport now pulls real major global events from ESPN's public scoreboard API — completely keyless, so it works with zero configuration. Covers FIFA World Cup, Premier League, Champions League, Formula 1, UFC, URC Rugby, NFL, and NBA (easy to extend in the league list): live events first, then the week's soonest kick-offs with broadcaster, formatted in the viewer's local time. Cached 2 hours; any failure falls back to the curated sport list.

### Changed

- All content is global rather than South Africa-scoped; the curated fallback sport and Live TV lists were globalized to match.
- Live TV and Collections stay curated lists — they describe the service's own lineup, and no free API covers broadcast channel line-ups.

## [0.1.0] - 2026-07-07

### Added

- Initial project scaffold on the `bluegroup_core_foundation` guardrails: WordPress CI caller workflow, shared pull request and issue templates, shared Claude Code settings, `CLAUDE.md` from the foundation template, and `approved-deps.json`.
- AfriStream Customer Portal plugin (`afristream-portal.php`, `[afristream_portal]` shortcode) implementing the Claude Design handoff (AfriStream Portal v2): App Profile credentials with copy-to-clipboard, What to Watch (search, filter drawer, poster rows, live sport, live TV channels, collections), Tips & Tricks, and the Troubleshooting guide with per-device tabs.
- Local preview harness (`npm run preview` → http://localhost:4173) so the portal can be viewed without a WordPress install, plus Playwright smoke tests that run against it until a staging URL exists.
- Plugin build script staging the deployable folder to `dist/`, and the `afristream-portal.zip` deployment artifact.
