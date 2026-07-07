# Global CLAUDE.md

Global rules that apply to every project. Lives at `~/.claude/CLAUDE.md`. Full detail, all copy-paste prompts, and the complete Recipe Book live in the `bluegroup_core_foundation` repo and the Team Guidelines doc — this file is the condensed version Claude Code needs every session, and should never contradict them.

## How Projects Are Structured

- Every project is its own standalone repo — there is no monorepo
- Every project points at `bluegroup_core_foundation` for shared CI guardrails, permissions, and skills — never repeat those rules inside a project repo
- Projects don't have to share components or look alike — only the process is shared, not the design
- New projects are set up by pasting the matching Starter Prompt (standalone / WordPress plugin / headless) into Claude Code — there are no starter template repos to create from

## The Flow

Design System → Figma/Lovable/Claude Design → Claude Design (single source of truth) → handoff (export, or Claude Design's direct GitHub sync) → Claude Code builds → branch → pull request → automatic checks → review → merge → deploy

Every build or change starts from an approved GitHub Issue.

## Hard Guardrails (enforced by CI on every project, every type)

- Lint passes
- Build passes
- Version bumped on the pull request
- Changelog updated alongside the version bump
- No new dependency without prior approval (`approved-deps.json`)
- New functionality or a real bug fix has a Playwright test

## Golden Rules

- Always work on a branch, never main
- Every change goes through a pull request
- CI guardrails must pass — never bypassed, except a rare, written, Luke-approved emergency override
- Anyone with repo access can review and merge — no second sign-off required

## Versioning

- Patch bump for fixes, minor bump for new features
- Bump automatically alongside the change, and update the changelog to match — never wait to be asked

## Linting

- Run the linter once, as a final check — never loop lint, auto-fix, re-lint during a task
- Present any findings to the user at the end of the session and let them decide whether to action them
- Only fix lint issues after the user approves

## Deployment

Do this proactively at the end of any session with deployable changes — never wait to be asked.

- Standalone: `npm install`, `npm run build`, then remove `node_modules` to leave the folder clean for manual zipping
- WordPress plugin: bump the plugin version, then zip the plugin folder and place the zip at `<plugin-parent-dir>/<plugin-slug>.zip` — remove any older versioned zips in that same directory before creating the new one. The zip is the deployment artifact, never copy individual files
- Headless: nothing manual — CI and Netlify handle install, build, and deploy once merged

## Approved Tools & Styles

- Framework (headless projects): Next.js (App Router) + TypeScript — scaffolded via create-next-app
- Component base: Radix Themes
- Icons: lucide-react
- Styling: Tailwind CSS
- Design tokens: styles.refero.design
- Animation: tailwindcss-animate for simple cases, GSAP for complex cases, across every project type including WordPress
- Inspiration only, never copied directly: 21st.dev
- No page builders (Elementor etc.) — WordPress sites are built as a plugin, in code, never straight into WordPress core or a loose theme

## Approved Skills

Enabled automatically by the shared settings file from `bluegroup_core_foundation` — nobody installs these by hand: fewer-permission-prompts, test-driven-development, systematic-debugging, security-review, verification-before-completion, finishing-a-development-branch, init, writing-plans, executing-plans, requesting-code-review, graphify

> **Footnote — graphify:** graphify is the one exception to "nobody installs these by hand." It is a per-machine Python CLI (PyPI package `graphifyy`), not a config-enabled marketplace plugin, so each machine installs it once with `uv tool install graphifyy && graphify install`. The shared settings still list it as approved, and every other skill here loads automatically from that settings file.

## Model Guidance

- Default for building, Issues, Milestones: Claude Sonnet
- A genuinely hard bug or architecture decision: Claude Opus
- A very large or complex build (major migration, multi-day build): Claude Fable
- Quick, mechanical, high-volume work: Claude Haiku
- Claude Design: the same tiers, picked per project in-app

## Naming Conventions

- Repos: `blueworx_project_projectname` or `blueworx_client_clientname`
- Claude Design: `Project | ProjectName` or `Client | ClientName`
- Netlify: `blueworx-project-projectname` or `blueworx-client-clientname`
- Branches: short and descriptive — e.g. `add-contact-form`, `fix-header-bug`
- GitHub Issues: short, action-oriented title matching the branch; type set with a label, not in the title
- GitHub Milestones: short, descriptive phase name

## Recipe Book

Before building anything that solves a common, recurring problem (contact form, login, file upload, payment, search, error/loading states, WordPress shortcodes on a headless site), check the Recipe Book in the Team Guidelines doc first and follow the standard approach if one exists. Propose new recipes for Luke's approval rather than reinventing an approach per project.

## Secrets

Stored as environment variables in Netlify. Never committed to a repo or shared any other way.

## Accessibility

Meaningful alt text, real form labels, readable contrast, full keyboard access, and heading order used correctly — on every screen, every project type. Not a blocking CI check today, just how things get built.
