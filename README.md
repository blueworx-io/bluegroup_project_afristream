# bluegroup_project_afristream

**AfriStream Customer Portal** — a WordPress plugin giving AfriStream subscribers their app profile credentials, a "What to Watch" catalog, tips & tricks, and troubleshooting guides. Built from the Claude Design handoff (AfriStream Portal v2) on the shared [`bluegroup_core_foundation`](https://github.com/blueworx-io/bluegroup_core_foundation) guardrails.

## Using the plugin

Install `afristream-portal.zip` (the deployment artifact at the repo root), then place the shortcode on any page:

```
[afristream_portal default_tab="profile" show_sport="true"]
```

`default_tab` is one of `profile | watch | tips | help`.

## Live "What to Watch" data

**Sport (no key needed):** major global events — Soccer (FIFA World Cup, Premier League, Champions League), Cricket (ICC World Cup / T20 / Champions Trophy), Rugby, Golf, F1, UFC, NFL, NBA, tennis — come from ESPN's public scoreboard API: live events first, then the week's soonest kick-offs with broadcaster, in the viewer's local time. Extend the league list in [afristream-portal.php](afristream-portal.php) and [scripts/preview-server.mjs](scripts/preview-server.mjs) (keep the two in sync). Cached 2 hours. Note this is an unofficial API — if it ever breaks, the portal just shows its curated sport list (which always includes Cricket, Golf, Rugby and Soccer).

**Movies & series (TMDB key):** trending movies/series and new releases come from [TMDB](https://www.themoviedb.org/) (free API key, non-commercial use, attribution shown). Without a key the portal falls back to its built-in curated lists — nothing breaks.

- **WordPress:** paste the key under **Settings → AfriStream Portal** in wp-admin (no `wp-config.php` access needed). If `AFRISTREAM_TMDB_API_KEY` is defined in `wp-config.php` it takes precedence. Never commit the key.
- **Local preview:** set the `TMDB_API_KEY` environment variable before `npm run preview`.
- Get a key: themoviedb.org → sign up → Settings → API → request a key (choose "Developer"/non-commercial).
- Cached 12 hours (WP transient / in-memory locally).

Live TV is a curated list — edit it in [assets/portal.js](assets/portal.js). **Collections** are live queries over the fetched TMDB catalog (defined by a `match()` predicate per collection in [assets/portal.js](assets/portal.js)), so they populate themselves and show real counts — no manual title lists.

**Editor Picks (IMDb watchlist):** the picks come from an IMDb watchlist. IMDb's watchlist is behind a WAF and can't be read by the WordPress server, so the IDs are pulled at build/deploy time by a real browser:

```bash
IMDB_WATCHLIST_URL="https://www.imdb.com/user/…/watchlist/" npm run sync-watchlist
```

This writes the ordered `tt` IDs into [data/editor-picks-ids.txt](data/editor-picks-ids.txt) (bundled in the plugin zip), which the plugin resolves through TMDB for artwork. The wp-admin **Editor Picks (IMDb IDs)** box overrides the file when set. Run the sync whenever the watchlist changes, before `npm run build`.

**Apps:** a bundled directory of free and free-tier streaming apps (`data/apps.json`), filterable by device, content type and region, with per-device install steps. No API key or configuration needed — the file ships with the plugin.

## Local preview (no WordPress needed)

```bash
npm install
npm run preview   # → http://localhost:4173
```

The preview page loads the exact assets the plugin enqueues, and `/api/watch` mirrors the plugin's REST endpoint.

## Tests

```bash
npm test
```

Playwright smoke tests run against the local preview harness until a real staging URL exists — see the note in [playwright.config.js](playwright.config.js). Once staging exists, set `preview_url` in [.github/workflows/ci.yml](.github/workflows/ci.yml) and tests run against it instead.

## Build / deploy

```bash
npm run build     # stages dist/afristream-portal/
```

Then zip the staged folder as `afristream-portal.zip` at the repo root — the zip is the deployment artifact; only the current version's zip may exist. On Windows run `scripts/zip-plugin.ps1` to build it: PowerShell's `Compress-Archive` (and .NET Framework's `ZipFile.CreateFromDirectory`) write backslash path separators, which WordPress's extractor mishandles — the plugin then installs as a stray file instead of a folder. The script writes proper forward-slash entries.

## Process

Every change: branch → pull request → CI guardrails (lint, build, version bump, changelog, approved deps, plugin checks, Playwright) → merge. `main` is protected; the `guardrails / guardrails` check must pass.
