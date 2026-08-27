import { defineConfig } from '@playwright/test';

// Placeholder until AfriStream has a real staging/preview URL. Keep this in
// sync with `preview_url` in .github/workflows/ci.yml — while CI still passes
// the placeholder, tests fall back to the local preview harness so the
// guardrails exercise the real plugin front-end. Once a staging URL exists,
// update both files and tests will run against it automatically.
const STAGING_PLACEHOLDER = 'https://staging.afristream.example.com';

// Tests get their own port (4180) so they never reuse a manually started
// `npm run preview` server (4173), which may be serving live API data —
// the suite must always run against the hermetic offline instance below.
const port = Number(process.env.PORT) || 4180;
const external = [process.env.PLAYWRIGHT_BASE_URL, process.env.BASE_URL]
  .find((u) => u && u !== STAGING_PLACEHOLDER);
const baseURL = external || `http://localhost:${port}`;

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? 'list' : 'html',
  use: {
    baseURL,
    trace: 'on-first-retry',
    // The currency switcher picks its opening currency from the browser's own
    // timezone, so an unpinned browser prices the page differently on a
    // developer's laptop than in CI. Pinned to the currency the site bills in;
    // tests/currency.spec.js overrides it where the choice is the point.
    //
    // Timezone only, deliberately: the portal's affiliate calculator formats
    // through Intl.NumberFormat, so pinning a locale here would change its
    // separators as a side effect.
    timezoneId: 'Africa/Johannesburg',
  },
  webServer: external
    ? undefined
    : {
        command: 'node scripts/preview-server.mjs',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 60000,
        // Hermetic tests: no live TMDB/ESPN calls — the portal exercises its
        // curated fallback, and the fixture page covers the API-data path.
        env: { ...process.env, WATCH_OFFLINE: '1', PORT: String(port) },
      },
});
