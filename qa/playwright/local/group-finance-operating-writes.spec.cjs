const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8011';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('Missing local CSRF token.');
  return match[1];
}

async function centralStorageState() {
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

test('Legacy Group Finance operations are retired before any financial write', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await centralStorageState() });
  try {
    const page = await context.newPage();
    await page.goto('/group-finance', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/group-finance\/\d+$/);
    const school = page.locator('#operating-school');
    const options = await school.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' })));
    const zixuan = options.find(option => /Zixuan QA School/.test(option.text));
    expect(zixuan?.value).toBeTruthy();
    await school.selectOption(zixuan.value);
    await page.locator('[data-operating-school-switcher] button').click();
    await page.waitForURL(/group-finance\/operating\/bank-accounts$/);
    const navigation = page.locator('[data-operating-finance-nav]');
    await expect(navigation.getByRole('link', { name: 'Bank Accounts', exact: true })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Transactions', exact: true })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Finance Reports', exact: true })).toBeVisible();
    await expect(navigation.getByRole('link', { name: 'Finance Operations', exact: true })).toHaveCount(0);

    const retired = await page.goto('/group-finance/operating/operations', { waitUntil: 'domcontentloaded' });
    expect(retired?.status()).toBe(410);
  } finally {
    await context.close();
  }
});
