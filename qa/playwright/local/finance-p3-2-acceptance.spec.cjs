const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i)
    || html.match(/<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i);
  if (!match) throw new Error('Expected local CSRF token.');
  return match[1];
}

async function stateFor(email) {
  const state = `/tmp/finance-p32-${email.replace(/[^a-z]/g, '_')}.json`;
  await authenticateLocalBowenQa(email, state);
  return state;
}

async function apiFor(email) {
  return request.newContext({ baseURL, storageState: await stateFor(email) });
}

async function accountsFor(api) {
  const response = await api.get('/bank-accounts/list', { params: { limit: 100 } });
  expect(response.status()).toBe(200);
  return (await response.json()).rows;
}

test.describe.configure({ mode: 'serial' });

test('Transactions register scopes accounts and Receive Money creates one canonical OtherIncome movement', async ({ browser }) => {
  const cashierApi = await apiFor('qa_cashier_a@bowen-qa.test');
  const headApi = await apiFor('qa_head_finance@bowen-qa.test');
  const adminApi = await apiFor('qa_admin@bowen-qa.test');
  const reference = `BOWEN_QA_P32_RECEIPT_${Date.now()}`;
  const amount = 37.5;

  try {
    const cashierAccounts = await accountsFor(cashierApi);
    const headAccounts = await accountsFor(headApi);
    const cashA = cashierAccounts.find((account) => account.account_number === 'QA_P2_CASH_A');
    const cashB = headAccounts.find((account) => account.account_number === 'QA_P2_CASH_B');
    expect(cashA).toBeTruthy();
    expect(cashB).toBeTruthy();
    expect(cashierAccounts.map((account) => account.id)).toContain(cashA.id);
    expect(cashierAccounts.map((account) => account.id)).not.toContain(cashB.id);
    const beforeBalance = Number(cashA.current_balance);

    expect([403, 404]).toContain((await cashierApi.get(`/finance/transactions?bank_account_id=${cashB.id}`, { maxRedirects: 0 })).status());

    const context = await browser.newContext({ baseURL, storageState: await stateFor('qa_cashier_a@bowen-qa.test') });
    try {
      const page = await context.newPage();
      const dialogs = [];
      page.on('dialog', (dialog) => { dialogs.push(dialog.type()); dialog.dismiss(); });
      expect((await page.goto('/finance/transactions', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
      await expect(page.getByRole('link', { name: 'Transactions', exact: true })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Transactions' })).toBeVisible();
      const filterAccountOptions = await page.locator('form[method="GET"] select[name="bank_account_id"] option').allTextContents();
      expect(filterAccountOptions).toContain('QA P2 Cash A');
      expect(filterAccountOptions).not.toContain('QA P2 Cash B');
      await page.getByRole('button', { name: 'Receive Money' }).click();
      const modal = page.locator('#receiveMoneyModal');
      await expect(modal).toBeVisible();
      await expect(modal.locator('[name="payment_method"]')).toHaveAttribute('required', '');
      await expect(modal.locator('[name="bank_account_id"]')).toHaveAttribute('required', '');
      const receiveAccountOptions = await modal.locator('[name="bank_account_id"] option').allTextContents();
      expect(receiveAccountOptions).toContain('QA P2 Cash A');
      expect(receiveAccountOptions).not.toContain('QA P2 Cash B');
      await modal.locator('[name="date"]').fill('2026-08-13');
      await modal.locator('[name="payer"]').fill('BOWEN_QA P3.2 Browser Payer');
      await modal.locator('[name="description"]').fill('BOWEN_QA P3.2 browser receipt');
      await modal.locator('[name="amount"]').fill(String(amount));
      await modal.locator('[name="payment_method"]').selectOption('Cash');
      await modal.locator('[name="bank_account_id"]').selectOption(String(cashA.id));
      await modal.locator('[name="reference_no"]').fill(reference);
      const received = page.waitForResponse((response) => response.url().endsWith('/finance/transactions/receive') && response.request().method() === 'POST');
      await modal.getByRole('button', { name: 'Receive Money' }).click();
      expect((await received).status()).toBe(200);
      await page.waitForURL(/\/finance\/transactions/);
      expect(dialogs).toEqual([]);
    } finally { await context.close(); }

    const afterAccounts = await accountsFor(cashierApi);
    const cashAAfter = afterAccounts.find((account) => account.id === cashA.id);
    expect(Number(cashAAfter.current_balance)).toBeCloseTo(beforeBalance + amount, 2);
    const filtered = await cashierApi.get('/finance/transactions', { params: { reference, type: 'other_income', bank_account_id: cashA.id } });
    expect(filtered.status()).toBe(200);
    const filteredHtml = await filtered.text();
    expect(filteredHtml).toContain(reference);
    expect((filteredHtml.match(new RegExp(`<td>${reference}</td>`, 'g')) || []).length).toBe(1);
    expect((await cashierApi.get(`/bank-accounts/${cashA.id}`)).status()).toBe(200);
    expect(await (await cashierApi.get(`/bank-accounts/${cashA.id}`)).text()).toContain(reference);
    expect((await cashierApi.get('/finance-dashboard')).status()).toBe(200);
    expect((await cashierApi.get('/finance-report')).status()).toBe(200);

    const adminTransactions = await adminApi.get(`/finance/transactions?reference=${reference}&type=other_income`);
    expect(adminTransactions.status()).toBe(200);
    expect((await adminTransactions.text()).match(new RegExp(`<td>${reference}</td>`, 'g')) || []).toHaveLength(1);
  } finally {
    await cashierApi.dispose();
    await headApi.dispose();
    await adminApi.dispose();
  }
});

test('Internal transfers are neutral in the all-account register and directional for a selected Fund Account', async () => {
  const headApi = await apiFor('qa_head_finance@bowen-qa.test');
  const reference = `BOWEN_QA_P0_TRANSFER_${Date.now()}`;
  const amount = '25.00';

  try {
    const accountsBefore = await accountsFor(headApi);
    const source = accountsBefore.find((account) => account.account_number === 'QA_P2_BANK');
    const destination = accountsBefore.find((account) => account.account_number === 'QA_P2_CASH_A');
    expect(source).toBeTruthy();
    expect(destination).toBeTruthy();

    const transferPage = await headApi.get('/bank-transfers');
    expect(transferPage.status()).toBe(200);
    const create = await headApi.post('/bank-transfers', {
      form: {
        _token: csrfToken(await transferPage.text()),
        from_account_id: String(source.id),
        to_account_id: String(destination.id),
        amount,
        transfer_date: '2026-08-14',
        reference_no: reference,
        notes: 'BOWEN_QA P0 internal transfer perspective',
      },
      maxRedirects: 0,
    });
    expect(create.status()).toBe(200);
    const created = await create.json();
    expect(created.error).toBeFalsy();

    const allAccounts = await headApi.get('/finance/transactions', { params: { type: 'bank_transfer', reference } });
    expect(allAccounts.status()).toBe(200);
    const allHtml = await allAccounts.text();
    expect((allHtml.match(new RegExp(`<td>${reference}</td>`, 'g')) || [])).toHaveLength(1);
    const allRow = allHtml.match(new RegExp(`<tr>[^]*?<td>${reference}</td>[^]*?</tr>`))?.[0] || '';
    expect(allRow).toContain('Internal Transfer');
    expect(allRow).not.toContain('25.00');

    const sourceView = await headApi.get('/finance/transactions', { params: { type: 'bank_transfer', reference, bank_account_id: source.id } });
    expect(sourceView.status()).toBe(200);
    const sourceRow = (await sourceView.text()).match(new RegExp(`<tr>[^]*?<td>${reference}</td>[^]*?</tr>`))?.[0] || '';
    expect(sourceRow).toContain('25.00');

    const destinationView = await headApi.get('/finance/transactions', { params: { type: 'bank_transfer', reference, bank_account_id: destination.id } });
    expect(destinationView.status()).toBe(200);
    const destinationRow = (await destinationView.text()).match(new RegExp(`<tr>[^]*?<td>${reference}</td>[^]*?</tr>`))?.[0] || '';
    expect(destinationRow).toContain('25.00');

    const cancel = await headApi.delete(`/bank-transfers/${created.id}`, {
      form: { _token: csrfToken(await transferPage.text()) },
      maxRedirects: 0,
    });
    expect(cancel.status()).toBe(200);
    const cancelled = await headApi.get('/finance/transactions', { params: { type: 'bank_transfer', reference } });
    expect(await cancelled.text()).not.toContain(`<td>${reference}</td>`);
  } finally {
    await headApi.dispose();
  }
});
