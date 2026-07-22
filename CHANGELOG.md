# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.10.0] - 2026-07-22

### Added

- New **Free Apps** tab, sitting after Editor Picks: a curated directory of free and free-tier streaming apps covering sport, movies, series, documentaries and live TV, filterable by device type (Smart TV, consoles, sticks and boxes, tablets, phones), content type and region.
- Each app carries a plain-language description, the devices it installs on, where it is available, whether it is fully free or a free tier, and per-device install steps — shown in the existing detail drawer, with a link out to the official site.
- The directory ships as `data/apps.json` inside the plugin and is fetched lazily the first time the tab is opened. Region availability is recorded as coarse groups for filtering plus a precise free-text note, because free services are heavily region-locked and a country-level filter would need a hundred-entry dropdown.
- App logos are deliberately not used — the cards use the portal's existing initial-on-gradient treatment, so nothing is loaded from a third-party host.

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
