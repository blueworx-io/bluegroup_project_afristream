import { defineConfig } from '@playwright/test';

// Placeholder until AfriStream has a real staging/preview URL. Keep this in
// sync with `preview_url` in .github/workflows/ci.yml — while CI still passes
// the placeholder, tests fall back to the local preview harness so the
// guardrails exercise the real plugin front-end. Once a staging URL exists,
// update both files and tests will run against it automatically.
const STAGING_PLACEHOLDER = 'https://staging.afristream.example.com';

const port = Number(process.env.PORT) || 4173;
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
  },
  webServer: external
    ? undefined
    : {
        command: 'node scripts/preview-server.mjs',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 60000,
      },
});
