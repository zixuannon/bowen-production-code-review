const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i)
    || html.match(/<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i);
  if (!match) throw new Error('Expected a local CSRF token.');
  return match[1];
}

async function stateFor(email) {
  const state = `/tmp/finance-p3-${email.replace(/[^a-z]/g, '_')}.json`;
  await authenticateLocalBowenQa(email, state);
  return state;
}

async function apiFor(email) {
  return request.newContext({ baseURL, storageState: await stateFor(email) });
}

async function accountRows(api) {
  const response = await api.get('/bank-accounts/list');
  expect(response.status()).toBe(200);
  return (await response.json()).rows;
}

async function handoverToken(api) {
  const page = await api.get('/fund-handovers');
  expect(page.status()).toBe(200);
  return csrfToken(await page.text());
}

async function recipientId(api, label) {
  const html = await (await api.get('/fund-handovers')).text();
  const match = html.match(new RegExp(`<option value="(\\d+)">[^<]*${label}[^<]*</option>`, 'i'));
  if (!match) throw new Error(`Expected ${label} in local Fund Handover recipients.`);
  return match[1];
}

test('BOWEN_QA two-party Fund Handover remains pending until receiver confirmation, then records one transfer', async ({ browser }) => {
  const headApi = await apiFor('qa_head_finance@bowen-qa.test');
  const cashierApi = await apiFor('qa_cashier_a@bowen-qa.test');
  const cashierBApi = await apiFor('qa_cashier_b@bowen-qa.test');
  const adminApi = await apiFor('qa_admin@bowen-qa.test');
  const reference = `BOWEN_QA_P3_HEAD_TO_CASH_A_${Date.now()}`;

  try {
    const headAccounts = await accountRows(headApi);
    const cashierAccounts = await accountRows(cashierApi);
    const source = headAccounts.find((row) => row.account_number === 'QA_P2_BANK');
    const destination = cashierAccounts.find((row) => row.account_number === 'QA_P2_CASH_A');
    const forbiddenDestination = (await accountRows(cashierBApi)).find((row) => row.account_number === 'QA_P2_CASH_B');
    const cashierBId = await recipientId(headApi, 'QA Cashier B');
    expect(source).toBeTruthy();
    expect(destination).toBeTruthy();
    expect(forbiddenDestination).toBeTruthy();

    const sourceBefore = Number(source.current_balance);
    const destinationBefore = Number(destination.current_balance);
    const headPageContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_head_finance@bowen-qa.test') });
    const headPage = await headPageContext.newPage();
    try {
      const pageResponse = await headPage.goto('/fund-handovers', { waitUntil: 'domcontentloaded' });
      expect(pageResponse?.status()).toBe(200);
      await expect(headPage.locator('a[href$="/fund-handovers"]')).toBeVisible();
      await expect(headPage.getByText('New Pending Handover')).toBeVisible();
      await headPage.locator('#receiver_id').selectOption({ label: 'QA Cashier A' });
      await headPage.locator('#from_account_id').selectOption(String(source.id));
      await headPage.locator('#to_account_id').selectOption(String(destination.id));
      await headPage.locator('input[name="amount"]').fill('25.00');
      await headPage.locator('input[name="handover_date"]').fill('2026-03-01');
      await headPage.locator('input[name="reference_no"]').fill(reference);
      await headPage.locator('input[name="notes"]').fill('BOWEN_QA P3 browser acceptance');
      const pendingResponse = headPage.waitForResponse((response) => response.url().endsWith('/fund-handovers') && response.request().method() === 'POST');
      await headPage.locator('#fund-handover-form button[type="submit"]').click();
      expect((await pendingResponse).status()).toBe(200);
    } finally {
      await headPageContext.close();
    }

    const pending = await headApi.get('/fund-handovers/list');
    const pendingRow = (await pending.json()).rows.find((row) => row.reference_no === reference);
    expect(pendingRow?.status).toBe('pending');
    expect(pendingRow?.bank_transfer_id).toBeUndefined();
    expect((await accountRows(headApi)).find((row) => row.id === source.id).current_balance).toBe(sourceBefore);
    expect((await accountRows(cashierApi)).find((row) => row.id === destination.id).current_balance).toBe(destinationBefore);
    const transfersBefore = await headApi.get('/bank-transfers/list');
    expect((await transfersBefore.json()).rows.some((row) => row.reference_no === reference)).toBeFalsy();

    const adminPage = await adminApi.get('/fund-handovers');
    expect(adminPage.status()).toBe(200);
    const adminHtml = await adminPage.text();
    expect(adminHtml).toContain('Read-only oversight');
    expect(adminHtml).not.toContain('New Pending Handover');
    const adminRows = await adminApi.get('/fund-handovers/list');
    expect(adminRows.status()).toBe(200);
    expect((await adminRows.json()).rows.some((row) => row.reference_no === reference)).toBeTruthy();

    const adminContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_admin@bowen-qa.test') });
    const adminBrowserPage = await adminContext.newPage();
    try {
      const response = await adminBrowserPage.goto('/fund-handovers', { waitUntil: 'domcontentloaded' });
      expect(response?.status()).toBe(200);
      await expect(adminBrowserPage.locator('a[href$="/fund-handovers"]')).toBeVisible();
      await expect(adminBrowserPage.getByText('Read-only oversight')).toBeVisible();
      await expect(adminBrowserPage.getByText('New Pending Handover')).toHaveCount(0);
    } finally {
      await adminContext.close();
    }

    const adminCannotCreate = await adminApi.post('/fund-handovers', {
      form: { _token: csrfToken(adminHtml), receiver_id: cashierBId, from_account_id: String(source.id), to_account_id: String(destination.id), amount: '1.00', handover_date: '2026-03-01' }, maxRedirects: 0,
    });
    expect(adminCannotCreate.status()).toBe(403);
    for (const action of ['confirm', 'reject', 'cancel']) {
      const response = await adminApi.post(`/fund-handovers/${pendingRow.id}/${action}`, {
        form: { _token: csrfToken(adminHtml), reason: 'Forged School Admin action must be rejected' }, maxRedirects: 0,
      });
      expect(response.status()).toBe(403);
    }

    const headCannotConfirm = await headApi.post(`/fund-handovers/${pendingRow.id}/confirm`, {
      form: { _token: await handoverToken(headApi) }, maxRedirects: 0,
    });
    expect(headCannotConfirm.status()).toBe(403);

    const cashierContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_cashier_a@bowen-qa.test') });
    const cashierPage = await cashierContext.newPage();
    try {
      const response = await cashierPage.goto('/fund-handovers', { waitUntil: 'domcontentloaded' });
      expect(response?.status()).toBe(200);
      await expect(cashierPage.locator('a[href$="/fund-handovers"]')).toBeVisible();
      const row = cashierPage.locator('#handover-table tbody tr', { hasText: reference });
      await expect(row).toBeVisible();
      const confirmation = cashierPage.waitForResponse((candidate) => candidate.url().endsWith(`/fund-handovers/${pendingRow.id}/confirm`) && candidate.request().method() === 'POST');
      await row.getByRole('button', { name: 'Confirm' }).click();
      expect((await confirmation).status()).toBe(200);
      await expect(row).toContainText('confirmed');
    } finally {
      await cashierContext.close();
    }

    expect(Number((await accountRows(headApi)).find((row) => row.id === source.id).current_balance)).toBeCloseTo(sourceBefore - 25, 2);
    expect(Number((await accountRows(cashierApi)).find((row) => row.id === destination.id).current_balance)).toBeCloseTo(destinationBefore + 25, 2);
    const transfersAfter = await headApi.get('/bank-transfers/list');
    const transfer = (await transfersAfter.json()).rows.find((row) => row.reference_no === reference);
    expect(transfer?.status).toBe('completed');
    const adminHistory = await adminApi.get('/fund-handovers/list');
    const adminHistoryRow = (await adminHistory.json()).rows.find((row) => row.reference_no === reference);
    expect(adminHistoryRow?.audit).toContain('Confirmed by');
    expect((await headApi.get(`/bank-accounts/${source.id}`)).status()).toBe(200);
    expect(await (await headApi.get(`/bank-accounts/${source.id}`)).text()).toContain(reference);

    const secondConfirm = await cashierApi.post(`/fund-handovers/${pendingRow.id}/confirm`, {
      form: { _token: await handoverToken(cashierApi) }, maxRedirects: 0,
    });
    expect(secondConfirm.status()).toBe(422);
    const immutableTransfer = await headApi.delete(`/bank-transfers/${transfer.id}`, {
      form: { _token: csrfToken(await (await headApi.get('/bank-transfers')).text()) }, maxRedirects: 0,
    });
    expect(immutableTransfer.status()).toBe(422);

    const cashierToCashier = await cashierApi.post('/fund-handovers', {
      form: {
        _token: await handoverToken(cashierApi), receiver_id: cashierBId,
        from_account_id: String(destination.id), to_account_id: String(forbiddenDestination.id), amount: '1.00',
        handover_date: '2026-03-01', reference_no: 'BOWEN_QA_P3_FORBIDDEN_CASHIER_TO_CASHIER', notes: 'must reject',
      }, maxRedirects: 0,
    });
    expect(cashierToCashier.status()).toBe(403);
  } finally {
    await headApi.dispose();
    await cashierApi.dispose();
    await cashierBApi.dispose();
    await adminApi.dispose();
  }
});
