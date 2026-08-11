const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const password = 'local-bowen-qa-only';

async function login(email) {
  const statePath = `/tmp/${email.replace(/[^a-z]/g, '_')}.json`;
  await authenticateLocalBowenQa(email, statePath);
  const api = await request.newContext({ baseURL, storageState: statePath });
  return api;
}

test('Cashier account-list API exposes only the assigned synthetic Fund Account', async () => {
  const api = await login('qa_cashier_a@bowen-qa.test');
  try {
    const response = await api.get('/bank-accounts/list');
    expect(response.status()).toBe(200);
    const rows = (await response.json()).rows;
    expect(rows.map(row => row.account_number)).toEqual(['QA_P2_CASH_A']);
  } finally { await api.dispose(); }
});

test('Head Finance account-list API exposes all synthetic P2 Fund Accounts', async () => {
  const api = await login('qa_head_finance@bowen-qa.test');
  try {
    const response = await api.get('/bank-accounts/list');
    expect(response.status()).toBe(200);
    const accounts = (await response.json()).rows.map(row => row.account_number);
    expect(accounts).toEqual(expect.arrayContaining(['QA_P2_CASH_A', 'QA_P2_CASH_B', 'QA_P2_BANK']));
  } finally { await api.dispose(); }
});

test('Cashier direct access to an unassigned Fund Account is rejected', async () => {
  const head = await login('qa_head_finance@bowen-qa.test');
  const cashier = await login('qa_cashier_a@bowen-qa.test');
  try {
    const rows = (await (await head.get('/bank-accounts/list')).json()).rows;
    const forbidden = rows.find(row => row.account_number === 'QA_P2_CASH_B');
    expect(forbidden).toBeTruthy();
    const response = await cashier.get(`/bank-accounts/${forbidden.id}`);
    expect([403, 404]).toContain(response.status());
  } finally { await head.dispose(); await cashier.dispose(); }
});

test('Cashier cannot open the Fund Account edit endpoint or modify an opening balance', async () => {
  const cashier = await login('qa_cashier_a@bowen-qa.test');
  try {
    const rows = (await (await cashier.get('/bank-accounts/list')).json()).rows;
    expect(rows).toHaveLength(1);
    const response = await cashier.get(`/bank-accounts/${rows[0].id}/edit`);
    expect(response.status()).toBe(403);
  } finally { await cashier.dispose(); }
});
