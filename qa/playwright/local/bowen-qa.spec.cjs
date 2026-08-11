const { test, expect } = require('@playwright/test');

test('authenticated BOWEN_QA exposes the representative Finance modules', async ({ page, baseURL }) => {
  const base = new URL(baseURL);
  expect(['127.0.0.1', 'localhost', '::1']).toContain(base.hostname);

  const dashboard = await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  expect(dashboard).not.toBeNull();
  expect(dashboard.status()).toBe(200);
  await expect(page.getByText(/Bowen School/i).first()).toBeVisible();

  for (const path of ['/bank-accounts', '/expense', '/fees/paid', '/fees/optional']) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response, `${path} must render in BOWEN_QA`).not.toBeNull();
    expect(response.status(), `${path} must return 200`).toBe(200);
    await expect(page).not.toHaveURL(/\/login/);
  }

  await page.goto('/fees/paid', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#btn-import-excel')).toBeVisible();
  await expect(page.locator('#importExcelModal')).toBeAttached();

  await page.goto('/bank-accounts', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#edit_opening_balance')).toBeAttached();
});
