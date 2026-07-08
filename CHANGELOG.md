# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-07-07

### Added

- What to Watch now pulls real catalog data from TMDB (free, non-commercial tier): trending movies, trending series, and new releases/episodes, with real poster artwork and rating badges, refreshed twice a day. ([#1](https://github.com/blueworx-io/bluegroup_project_afristream/issues/1))
- New public REST endpoint `afristream/v1/watch` — the plugin fetches TMDB server-side (key stays out of the browser, responses cached 12h in a transient) and the portal falls back to its built-in curated lists whenever no key is configured or TMDB is unreachable. The key comes from the `AFRISTREAM_TMDB_API_KEY` constant in `wp-config.php` (or the `afristream_tmdb_api_key` option).
- The local preview server mirrors the endpoint at `/api/watch` (set a `TMDB_API_KEY` env var to see live data on localhost) plus a deterministic fixture mode used by two new Playwright tests covering the API-data path.
- TMDB attribution line shown in the What to Watch section whenever live data is displayed.
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
