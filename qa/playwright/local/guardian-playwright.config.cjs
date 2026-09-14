const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    timeout: 60_000,
    use: {
        baseURL: process.env.LOCAL_QA_BASE_URL || 'http://localhost:18999',
        headless: true,
        launchOptions: process.env.LOCAL_QA_CHROME_PATH
            ? { executablePath: process.env.LOCAL_QA_CHROME_PATH }
            : undefined,
        trace: 'off',
        screenshot: 'off',
        video: 'off',
    },
    reporter: 'list',
});
