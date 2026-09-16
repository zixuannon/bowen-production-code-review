const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local login page did not contain a CSRF token.');
  return match[1];
}

async function headFinanceState() {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const login = await api.get('/login', { maxRedirects: 0 });
    expect(login.status()).toBe(200);
    const response = await api.post('/login', {
      form: { _token: csrfToken(await login.text()), email: 'group_hq@group-qa.test', password: 'local-only' },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(response.status());
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
  test(`Head Finance transfer and handover overview is safe at ${viewport.width}px`, async ({ browser }) => {
    const context = await browser.newContext({ baseURL, storageState: await headFinanceState(), viewport });
    const page = await context.newPage();
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('pageerror', error => pageErrors.push(error.message));

    try {
      for (const path of ['/central-finance/transfers', '/central-finance/handovers']) {
        const response = await page.goto(path, { waitUntil: 'networkidle' });
        expect(response?.status()).toBe(200);
        await expect(page.locator('select[name="source_account_id"]')).toHaveCount(0);
        await expect(page.locator('select[name="destination_account_id"]')).toHaveCount(0);
        await expect(page.locator('.cf-empty-state').first()).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
      }
      expect(consoleErrors).toEqual([]);
      expect(pageErrors).toEqual([]);
    } finally {
      await context.close();
    }
  });
}
