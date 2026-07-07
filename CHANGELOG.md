# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-07-07

### Added

- Initial project scaffold on the `bluegroup_core_foundation` guardrails: WordPress CI caller workflow, shared pull request and issue templates, shared Claude Code settings, `CLAUDE.md` from the foundation template, and `approved-deps.json`.
- AfriStream Customer Portal plugin (`afristream-portal.php`, `[afristream_portal]` shortcode) implementing the Claude Design handoff (AfriStream Portal v2): App Profile credentials with copy-to-clipboard, What to Watch (search, filter drawer, poster rows, live sport, live TV channels, collections), Tips & Tricks, and the Troubleshooting guide with per-device tabs.
- Local preview harness (`npm run preview` → http://localhost:4173) so the portal can be viewed without a WordPress install, plus Playwright smoke tests that run against it until a staging URL exists.
- Plugin build script staging the deployable folder to `dist/`, and the `afristream-portal.zip` deployment artifact.
