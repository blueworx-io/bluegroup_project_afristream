# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
