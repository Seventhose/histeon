import { defineConfig, devices } from '@playwright/test';
import { AUTH_FILE } from './lib/paths.ts';
import { e2eStack } from './lib/stack.ts';

// Run through dev/e2e.sh, which starts the stack and generates the test admin's password.
export default defineConfig({
  testDir: './specs',
  globalSetup: './global-setup.ts',
  // One WordPress, one versioning history: releases and restores must not interleave.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: e2eStack().url,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts$/ },
    {
      name: 'chromium',
      testMatch: /\.spec\.ts$/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, storageState: AUTH_FILE },
    },
  ],
});
