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
        email: 'qa_front_desk@bowen-qa.test',
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
  test(`Fee Item quantity configuration and Fee Setup render at ${viewport.width}px`, async ({ browser, baseURL }) => {
    const context = await browser.newContext({
      baseURL,
      storageState: await frontDeskState(baseURL),
      viewport,
    });
    const page = await context.newPage();
    const failures = [];
    page.on('console', (message) => {
      if (message.type() === 'error') failures.push(`console: ${message.text()}`);
    });
    page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
    page.on('response', (response) => {
      if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${response.url()}`);
    });

    try {
      const create = await page.goto('/fees', { waitUntil: 'domcontentloaded' });
      expect(create?.status()).toBe(200);
      await expect(page.getByText('Allow Quantity', { exact: false }).first()).toBeVisible();
      await expect(page.locator('input[type="checkbox"][name*="quantity_enabled"]').first()).toBeVisible();

      const edit = await page.goto('/fees/1/edit', { waitUntil: 'domcontentloaded' });
      expect(edit?.status()).toBe(200);
      await expect(page.getByText('Allow Quantity', { exact: false }).first()).toBeVisible();

      const setup = await page.goto('/students/1/fee-assignment', { waitUntil: 'networkidle' });
      expect(setup?.status()).toBe(200);
      await expect(page.getByRole('heading', { name: 'Student Fee Setup', exact: true })).toBeVisible();
      await expect(page.locator('input[readonly][value="1"]').first()).toBeVisible();
      const enabledQuantity = page.locator('input.fee-quantity[min="1"][max="100"]').first();
      await expect(enabledQuantity).toBeVisible();
      await expect(enabledQuantity).toHaveValue('1');
      expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)).toBeFalsy();
      expect(failures).toEqual([]);
    } finally {
      await context.close();
    }
  });
}
