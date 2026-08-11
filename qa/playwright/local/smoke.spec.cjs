const { test, expect } = require('@playwright/test');

test('local login page loads', async ({ page, baseURL }) => {
  const url = new URL(baseURL);
  expect(['127.0.0.1', 'localhost', '::1']).toContain(url.hostname);

  const response = await page.goto('/login', { waitUntil: 'domcontentloaded' });
  expect(response).not.toBeNull();
  expect(response.status()).toBe(200);
});
