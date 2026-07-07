# bluegroup_project_afristream

**AfriStream Customer Portal** — a WordPress plugin giving AfriStream subscribers their app profile credentials, a "What to Watch" catalog, tips & tricks, and troubleshooting guides. Built from the Claude Design handoff (AfriStream Portal v2) on the shared [`bluegroup_core_foundation`](https://github.com/blueworx-io/bluegroup_core_foundation) guardrails.

## Using the plugin

Install `afristream-portal.zip` (the deployment artifact at the repo root), then place the shortcode on any page:

```
[afristream_portal default_tab="profile" show_sport="true"]
```

`default_tab` is one of `profile | watch | tips | help`.

## Local preview (no WordPress needed)

```bash
npm install
npm run preview   # → http://localhost:4173
```

The preview page loads the exact assets the plugin enqueues.

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
