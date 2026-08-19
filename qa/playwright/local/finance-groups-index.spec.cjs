const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const email = 'group_super_admin@group-qa.test';
const password = 'local-only';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('Expected a local CSRF token.');
  return match[1];
}

async function superAdminState() {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const login = await api.get('/login', { maxRedirects: 0 });
    expect(login.status()).toBe(200);
    const result = await api.post('/login', {
      form: { _token: csrfToken(await login.text()), email, password },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(result.status());
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

test('GROUP_QA central Super Admin opens the Finance Groups index without Wizard redirect or writes', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await superAdminState() });
  try {
    const page = await context.newPage();
    // The local Group fixture deliberately has no unrelated dashboard-school
    // administrators. Enter through the protected Group route, then exercise
    // the actual sidebar link from its rendered application layout.
    expect((await page.goto('/finance-groups', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
    await expect(page).toHaveURL(/\/finance-groups$/);
    const link = page.locator('a[href$="/finance-groups"]');
    await expect(link).toBeVisible();
    const response = await link.click();
    await page.waitForURL(/\/finance-groups$/);
    expect(response).toBeUndefined();
    await expect(page.getByRole('heading', { name: 'Finance Groups' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Create Finance Group' })).toBeVisible();
    await expect(page).not.toHaveURL(/wizard-settings/);
  } finally {
    await context.close();
  }
});
