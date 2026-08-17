const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const headAccountName = 'P3-UAT-Head-Main-Cash';
const accountantAccountName = 'P3-UAT-Accountant-Cash';
const accountantEmail = 'p3.uat.accountant.a@bowen-qa.test';

test('P3 Scenario 2 setup: Head Finance creates the two active UAT accounts once and assigns the Accountant account', async ({ browser }) => {
  const statePath = `/tmp/p3-scenario-2-setup-${Date.now()}.json`;
  const storageState = await authenticateLocalBowenQa('qa_head_finance@bowen-qa.test', statePath);
  const api = await request.newContext({ baseURL, storageState });
  let bankAccountPosts = 0;

  try {
    const context = await browser.newContext({ baseURL, storageState });
    try {
      const page = await context.newPage();
      page.on('request', (candidate) => {
        if (candidate.method() === 'POST' && new URL(candidate.url()).pathname === '/bank-accounts') bankAccountPosts += 1;
      });
      expect((await page.goto('/bank-accounts', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);

      for (const [name, number, opening] of [
        [headAccountName, 'P3-UAT-HEAD-CASH-001', '50000000'],
        [accountantAccountName, 'P3-UAT-ACCOUNTANT-CASH-001', '0'],
      ]) {
        await page.locator('#account_name').fill(name);
        await page.locator('#account_number').fill(number);
        await page.locator('select[name="account_type"]').selectOption('cash');
        await page.locator('#opening_balance').fill(opening);
        await page.locator('input[name="is_active"]').check();
        const response = page.waitForResponse((candidate) => candidate.url().endsWith('/bank-accounts') && candidate.request().method() === 'POST');
        await page.locator('#bank-account-create-form input[type="submit"]').click();
        expect((await response).status()).toBe(200);
      }
      await expect.poll(() => bankAccountPosts).toBe(2);

      const accountsResponse = await api.get('/bank-accounts/list', { params: { limit: 100 } });
      expect(accountsResponse.status()).toBe(200);
      const accounts = (await accountsResponse.json()).rows;
      const headAccount = accounts.find((account) => account.account_name === headAccountName);
      const accountantAccount = accounts.find((account) => account.account_name === accountantAccountName);
      expect(headAccount?.is_active).toBeTruthy();
      expect(accountantAccount?.is_active).toBeTruthy();
      expect(Number(headAccount?.current_balance)).toBe(50000000);
      expect(Number(accountantAccount?.current_balance)).toBe(0);

      await page.goto('/finance-staff', { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Add Accountant' }).click();
      const modal = page.locator('#addAccountantModal');
      await modal.locator('[name="first_name"]').fill('P3 UAT');
      await modal.locator('[name="last_name"]').fill('Accountant A');
      await modal.locator('[name="email"]').fill(accountantEmail);
      await modal.locator(`input[name="account_ids[]"][value="${accountantAccount.id}"]`).check();
      const created = page.waitForResponse((candidate) => candidate.url().endsWith('/finance-staff') && candidate.request().method() === 'POST');
      await modal.getByRole('button', { name: 'Create Accountant' }).click();
      expect((await created).status()).toBe(200);
      await page.waitForURL(/\/finance-staff$/);
      const accountantRow = page.locator('#finance-staff-table tbody tr', { hasText: 'P3 UAT Accountant A' });
      await expect(accountantRow).toContainText('Accountant');
      await expect(accountantRow).toContainText(accountantAccountName);
    } finally {
      await context.close();
    }
    expect(bankAccountPosts).toBe(2);
  } finally {
    await api.dispose();
  }
});
