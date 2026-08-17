const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

async function apiFor(statePath) {
  return request.newContext({ baseURL, storageState: statePath });
}

test('Head Finance creates active and inactive Fund Accounts with exactly one normalized POST per click', async ({ browser }) => {
  const statePath = `/tmp/bank-account-create-${Date.now()}.json`;
  const storageState = await authenticateLocalBowenQa('qa_head_finance@bowen-qa.test', statePath);
  const api = await apiFor(storageState);
  const suffix = Date.now();
  const activeName = `P3 Browser Active ${suffix}`;
  const inactiveName = `P3 Browser Inactive ${suffix}`;
  let postCount = 0;

  try {
    const context = await browser.newContext({ baseURL, storageState });
    try {
      const page = await context.newPage();
      page.on('request', (candidate) => {
        if (candidate.method() === 'POST' && new URL(candidate.url()).pathname === '/bank-accounts') postCount += 1;
      });
      expect((await page.goto('/bank-accounts', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
      await expect(page.locator('#bank-account-create-form')).toHaveAttribute('action', /\/bank-accounts$/);

      for (const [name, number, active] of [[activeName, `P3-ACTIVE-${suffix}`, true], [inactiveName, `P3-INACTIVE-${suffix}`, false]]) {
        await page.locator('#account_name').fill(name);
        await page.locator('#account_number').fill(number);
        await page.locator('select[name="account_type"]').selectOption('cash');
        if (active) await page.locator('input[name="is_active"]').check();
        else await page.locator('input[name="is_active"]').uncheck();
        const response = page.waitForResponse((candidate) => candidate.url().endsWith('/bank-accounts') && candidate.request().method() === 'POST');
        await page.locator('#bank-account-create-form input[type="submit"]').click();
        expect((await response).status()).toBe(200);
      }

      await expect.poll(() => postCount).toBe(2);
    } finally {
      await context.close();
    }

    const list = await api.get('/bank-accounts/list', { params: { limit: 100 } });
    expect(list.status()).toBe(200);
    const rows = (await list.json()).rows;
    expect(rows.find((row) => row.account_name === activeName)?.is_active).toBeTruthy();
    expect(rows.find((row) => row.account_name === inactiveName)?.is_active).toBeFalsy();
  } finally {
    await api.dispose();
  }
});
