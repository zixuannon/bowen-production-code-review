const { test, expect, request } = require('@playwright/test');

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local login form did not provide a CSRF token.');
  return match[1];
}

async function frontDeskState(baseURL) {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const form = await api.get('/login', { maxRedirects: 0 });
    expect(form.status()).toBe(200);
    const login = await api.post('/login', {
      form: {
        _token: csrfToken(await form.text()),
        email: 'qa_cashier_a@bowen-qa.test',
        password: 'local-bowen-qa-only',
        code: 'BOWEN_QA',
      },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(login.status());
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
  test(`Front Desk uses the Central collection workspace at ${viewport.width}px without legacy payment navigation`, async ({ browser, baseURL }) => {
    const context = await browser.newContext({
      baseURL,
      viewport,
      storageState: await frontDeskState(baseURL),
    });
    const page = await context.newPage();
    const consoleErrors = [];
    const errorResponses = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('response', (response) => {
      if ([404, 500].includes(response.status())) errorResponses.push(`${response.status()} ${response.url()}`);
    });

    try {
      const dashboard = await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
      expect(dashboard?.status()).toBe(200);

      const sidebar = page.locator('#sidebar');
      await expect(sidebar).toContainText('收费设置 / Student Fees');
      await expect(sidebar).toContainText('Manage Fees');
      await expect(sidebar).toContainText('Fee Types');
      await expect(sidebar).toContainText('School Finance');
      await expect(sidebar).toContainText('Student Collection');
      await expect(sidebar).toContainText('My pending collections');
      await expect(sidebar).toContainText('Collection Receipts');
      await expect(sidebar.locator('a[href$="/fees"]')).toHaveCount(1);
      await expect(sidebar.locator('a[href$="/fees-type"]')).toHaveCount(1);
      await expect(sidebar.locator('a[href$="/central-finance/student-collection"]')).toHaveCount(1);
      await expect(sidebar.locator('a[href*="/central-finance/pending-collections/my"]')).toHaveCount(2);
      await expect(sidebar.locator('a[href*="/fees/pay/"]')).toHaveCount(0);
      await expect(sidebar.locator('a[href="/finance/transactions"]')).toHaveCount(0);

      const collection = await page.goto('/central-finance/student-collection', { waitUntil: 'domcontentloaded' });
      expect(collection?.status()).toBe(200);
      const pending = await page.goto('/central-finance/pending-collections/my', { waitUntil: 'domcontentloaded' });
      expect(pending?.status()).toBe(200);
      await expect(page.locator('#collection-receipts')).toBeVisible();
      expect(errorResponses).toEqual([]);
      expect(consoleErrors).toEqual([]);
    } finally {
      await context.close();
    }
  });
}
