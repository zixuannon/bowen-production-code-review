const { defineConfig } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const parsed = new URL(baseURL);

if (!['127.0.0.1', 'localhost', '::1'].includes(parsed.hostname)) {
  throw new Error('LOCAL_QA_BASE_URL must point to localhost, 127.0.0.1, or ::1.');
}

module.exports = defineConfig({
  testDir: './qa/playwright/local',
  globalSetup: './qa/playwright/local/global-setup.cjs',
  timeout: 60_000,
  retries: 0,
  use: {
    baseURL,
    storageState: 'qa/playwright/.auth/bowen-qa.json',
    headless: true,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
  reporter: 'list',
});
