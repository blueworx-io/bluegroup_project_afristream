# bluegroup_project_afristream

**AfriStream Customer Portal** — a WordPress plugin giving AfriStream subscribers their app profile credentials, a "What to Watch" catalog, tips & tricks, and troubleshooting guides. Built from the Claude Design handoff (AfriStream Portal v2) on the shared [`bluegroup_core_foundation`](https://github.com/blueworx-io/bluegroup_core_foundation) guardrails.

## Using the plugin

Install `afristream-portal.zip` (the deployment artifact at the repo root), then place the shortcode on any page:

```
[afristream_portal default_tab="profile" show_sport="true"]
```

`default_tab` is one of `profile | watch | tips | help`.

## Live "What to Watch" data (TMDB)

The What to Watch section pulls trending movies/series and new releases from [TMDB](https://www.themoviedb.org/) (free API key, non-commercial use, attribution shown). Without a key the portal falls back to its built-in curated lists — nothing breaks.

- **WordPress:** add `define( 'AFRISTREAM_TMDB_API_KEY', 'your-key' );` to `wp-config.php` (or set the `afristream_tmdb_api_key` option). Never commit the key.
- **Local preview:** set the `TMDB_API_KEY` environment variable before `npm run preview`.
- Get a key: themoviedb.org → sign up → Settings → API → request a key (choose "Developer"/non-commercial).

Responses are cached for 12 hours (WP transient / in-memory locally). Sport, Live TV, and Collections are curated lists — edit them in [assets/portal.js](assets/portal.js).

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

Zip `dist/afristream-portal/` as `afristream-portal.zip` at the repo root — the zip is the deployment artifact; only the current version's zip may exist.

## Process

Every change: branch → pull request → CI guardrails (lint, build, version bump, changelog, approved deps, plugin checks, Playwright) → merge. `main` is protected; the `guardrails / guardrails` check must pass.
