// End-to-end tests of the page builder (Playwright test runner). The site is prepared by setup.sh.
// Tests marked fixme show known bugs of the builder; E2E_KNOWN_BUGS=1 runs them (they fail until the bug is fixed).
//   BASE_URL=http://127.0.0.1:8080 npm run e2e
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.mjs',
  outputDir: 'test-results',
  // One site, one database (SQLite in CI): tests run one after the other.
  workers: 1,
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  timeout: 120000,
  expect: { timeout: 15000 },
  reporter: process.env.CI ? [['list'], ['html', { outputFolder: 'report', open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.BASE_URL || 'http://127.0.0.1:8080',
    viewport: { width: 1500, height: 950 },
    actionTimeout: 20000,
    navigationTimeout: 45000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
