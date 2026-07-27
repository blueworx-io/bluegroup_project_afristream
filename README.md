# bluegroup_project_afristream

**BlueGroup | AfriStream Portal** — a WordPress plugin giving AfriStream subscribers their app profile credentials, a "What to Watch" catalog, tips & tricks, and troubleshooting guides. Built from the Claude Design handoff (AfriStream Portal v2) on the shared [`bluegroup_core_foundation`](https://github.com/blueworx-io/bluegroup_core_foundation) guardrails.

## Using the plugin

Install `bluegroup-project-afristream.zip` (the deployment artifact, built one level above the repo), then place the shortcode on any page:

```
[afristream_portal default_tab="profile" show_sport="true"]
```

`default_tab` is one of `profile | watch | apps | editor | tips | help`.

## The landing page

The plugin also ships a public marketing landing page. On any page's editor,
set the Template dropdown to **AfriStream Landing** — the template renders
the whole document itself (its own header and footer), so no theme wrapper
or Elementor is needed. `[afristream_landing]` renders the same sections as
a shortcode instead, inside an existing page's theme wrapper, for anyone who
wants that.

Under **Settings → AfriStream Portal**, set the **Portal page** to the page
carrying `[afristream_portal]` — the landing page's header and footer
"Dashboard" links point at it. Left on "Detect automatically" it finds the
first published page containing that shortcode.

Set **Get Started URL** on the same settings screen to whatever should take an
order — a checkout, an order form, a WhatsApp or mailto link. Every "Get
Started" button on the landing page leads to the closing call to action, and
that button follows this URL. Left empty it scrolls to the pricing section
instead, so the page never has a dead button.

## Live "What to Watch" data

**Sport (no key needed):** major global events — Soccer (FIFA World Cup, Premier League, Champions League), Cricket (ICC World Cup / T20 / Champions Trophy), Rugby, Golf, F1, UFC, NFL, NBA, tennis — come from ESPN's public scoreboard API: live events first, then the week's soonest kick-offs with broadcaster, in the viewer's local time. Extend the league list in [bluegroup-project-afristream.php](bluegroup-project-afristream.php) and [scripts/preview-server.mjs](scripts/preview-server.mjs) (keep the two in sync). Cached 2 hours. Note this is an unofficial API — if it ever breaks, the portal just shows its curated sport list (which always includes Cricket, Golf, Rugby and Soccer).

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

This writes the ordered `tt` IDs into [data/editor-picks-ids.txt](data/editor-picks-ids.txt), then resolves every title through TMDB and writes the finished list to [data/editor-picks.json](data/editor-picks.json). Both are bundled in the plugin zip, and it is the JSON the plugin actually serves — a file read, rather than one TMDB round-trip per title from WordPress. Needs `TMDB_API_KEY` in the environment.

IMDb renders at most 250 rows per page, so the sync walks `?page=N` until the list total is covered. It also **only ever adds**: the ID file accumulates, so a failed or partial scrape is a no-op instead of data loss. To drop a title, delete its line from the ID file by hand and re-run `bake-picks -- --refresh`.

```bash
npm run bake-picks               # resolves only titles not already in editor-picks.json
npm run bake-picks -- --refresh  # ignores the previous bake, re-resolves everything
```

Baked picks record the IMDb ID they came from, so a re-bake re-uses what it resolved last time — adding a few titles to a 250-title list is a couple of seconds, not 250 round-trips.

The wp-admin **Editor Picks (IMDb IDs)** box overrides both files when set; because hand-entered IDs have no baked copy, that path resolves through TMDB live and fills in over the first few page loads. Run the sync whenever the watchlist changes, before `npm run build`.

**Free Apps:** a bundled directory of free and free-tier streaming apps (`data/apps.json`), filterable by device, content type and region, with per-device install steps. No API key or configuration needed — the file ships with the plugin.

**Sport (no key needed):** sport listings merge three permanently free sources, so there is nothing to configure:

| Source | Supplies | When |
| --- | --- | --- |
| [ESPN](https://site.api.espn.com/) public scoreboard | Fixtures worldwide, US networks | Live, cached 2h |
| [TheSportsDB](https://www.thesportsdb.com/documentation) free tier (public key `123`) | Broadcasters outside the US | Live, cached 2h |
| [iptv-org/epg](https://github.com/iptv-org/epg) | What is actually on SuperSport and Sky Sports | Baked at build time |

The first two are plain HTTP calls the WordPress server makes itself. The third is a grabber that clones ~150MB and takes minutes, so it runs at build/deploy time like the watchlist sync:

```bash
npm run sync-listings          # ~3 days of listings
EPG_DAYS=5 npm run sync-listings
```

This writes [data/sports-listings.json](data/sports-listings.json) (bundled in the plugin zip); the grabber itself is cached in `.cache/` and never committed. DStv publishes a separate channel list per African market and Sky a separate feed for the UK and Ireland, but the same channel carries the same programming in each, so the sync keeps one entry per channel rather than grabbing — and then showing — the same listing several times over. The plugin drops any programme that has already finished and ignores the file entirely once it is more than 10 days old, so a stale build degrades to the two live feeds rather than showing yesterday's guide. Re-run it before `npm run build`.

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
npm run build     # stages dist/bluegroup-project-afristream/
```

Then zip the staged folder as `bluegroup-project-afristream.zip` **one level above the repo** — the zip is the deployment artifact; only the current version's zip may exist. Build it with bsdtar, which writes forward-slash entry names on every platform:

```bash
/c/Windows/System32/tar.exe -a -c -f ../bluegroup-project-afristream.zip -C dist bluegroup-project-afristream   # Windows
tar -a -c -f ../bluegroup-project-afristream.zip -C dist bluegroup-project-afristream                           # macOS / Linux
```

`scripts/zip-plugin.ps1` is the PowerShell equivalent. Never use `Compress-Archive` (or .NET Framework's `ZipFile.CreateFromDirectory`): both write backslash path separators, which WordPress's extractor mishandles — the plugin then installs as a stray file instead of a folder and activation fails with "Plugin file does not exist." Always list the finished zip (`unzip -l`) and confirm every entry reads `bluegroup-project-afristream/...` with forward slashes.

## Process

Every change: branch → pull request → CI guardrails (lint, build, version bump, changelog, approved deps, plugin checks, Playwright) → merge. `main` is protected; the `guardrails / guardrails` check must pass.
