const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('Expected local CSRF token was not rendered.');
  return match[1];
}

async function stateFor(email) {
  const path = `/tmp/finance-p2-${email.replace(/[^a-z]/g, '_')}.json`;
  await authenticateLocalBowenQa(email, path);
  return path;
}

async function cashierPage(browser) {
  const context = await browser.newContext({ baseURL, storageState: await stateFor('qa_cashier_a@bowen-qa.test') });
  return { context, page: await context.newPage() };
}

async function scopedAccountNames(browser, email) {
  const context = await browser.newContext({ baseURL, storageState: await stateFor(email) });
  const page = await context.newPage();
  try {
    const response = await page.goto('/expense', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    return await page.locator('select[name="bank_account_id"] option').evaluateAll((options) =>
      options.map((option) => option.textContent.trim()).filter(Boolean),
    );
  } finally {
    await context.close();
  }
}

async function accountsForHead() {
  const api = await request.newContext({ baseURL, storageState: await stateFor('qa_head_finance@bowen-qa.test') });
  try {
    const response = await api.get('/bank-accounts/list');
    expect(response.status()).toBe(200);
    return (await response.json()).rows;
  } finally {
    await api.dispose();
  }
}

test('Cashier sees only the assigned Fund Account in Expense, payment, transfer, and report UI', async ({ browser }) => {
  const { context, page } = await cashierPage(browser);
  try {
    for (const path of ['/expense', '/bank-transfers', '/bank-account-report', '/fees/pay/compulsory/2/4', '/fees/pay/optional/2/4']) {
      const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response.status()).toBe(200);
      const options = await page.locator('select[name="bank_account_id"], #from_account_id, #to_account_id').evaluateAll((selects) =>
        selects.flatMap((select) => Array.from(select.options).map((option) => option.textContent.trim())).filter(Boolean),
      );
      expect(options.join(' '), `Fund Account selector missing on ${path}`).toContain('QA P2 Cash A');
      expect(options.join(' ')).not.toContain('QA P2 Cash B');
      expect(options.join(' ')).not.toContain('QA P2 Bank');
    }
  } finally {
    await context.close();
  }
});

test('Cashier direct report and transfer requests using an unassigned account are rejected without a write', async () => {
  const accounts = await accountsForHead();
  const cashA = accounts.find((row) => row.account_number === 'QA_P2_CASH_A');
  const cashB = accounts.find((row) => row.account_number === 'QA_P2_CASH_B');
  expect(cashA).toBeTruthy();
  expect(cashB).toBeTruthy();

  const api = await request.newContext({ baseURL, storageState: await stateFor('qa_cashier_a@bowen-qa.test') });
  try {
    const report = await api.get(`/bank-account-report?bank_account_id=${cashB.id}`, { maxRedirects: 0 });
    expect([403, 404]).toContain(report.status());

    const transferIndex = await api.get('/bank-transfers');
    expect(transferIndex.status()).toBe(200);
    const transfer = await api.post('/bank-transfers', {
      form: {
        _token: csrfToken(await transferIndex.text()),
        from_account_id: String(cashA.id),
        to_account_id: String(cashB.id),
        amount: '1.00',
        transfer_date: '2026-02-02',
        reference_no: 'BOWEN_QA_P2_FORBIDDEN_TRANSFER',
        notes: 'Must be rejected before write',
      },
      maxRedirects: 0,
    });
    expect([403, 404]).toContain(transfer.status());

    const list = await api.get('/bank-transfers/list');
    expect(list.status()).toBe(200);
    expect((await list.json()).rows.some((row) => row.reference_no === 'BOWEN_QA_P2_FORBIDDEN_TRANSFER')).toBeFalsy();
  } finally {
    await api.dispose();
  }
});

test('Cashier B sees only Cash B, and account-scoped finance reporting does not expose school-wide outstanding', async ({ browser }) => {
  const options = await scopedAccountNames(browser, 'qa_cashier_b@bowen-qa.test');
  expect(options.join(' ')).toContain('QA P2 Cash B');
  expect(options.join(' ')).not.toContain('QA P2 Cash A');
  expect(options.join(' ')).not.toContain('QA P2 Bank');

  const context = await browser.newContext({ baseURL, storageState: await stateFor('qa_cashier_b@bowen-qa.test') });
  const page = await context.newPage();
  try {
    const response = await page.goto('/finance-report', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Not available' })).toBeVisible();
    await expect(page.getByText('Outstanding is school-wide and is not available to account-scoped roles.')).toBeVisible();
  } finally {
    await context.close();
  }
});
