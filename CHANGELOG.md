# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
