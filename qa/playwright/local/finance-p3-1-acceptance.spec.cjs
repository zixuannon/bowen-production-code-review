const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

async function stateFor(email) {
  const state = `/tmp/finance-p31-${email.replace(/[^a-z]/g, '_')}.json`;
  await authenticateLocalBowenQa(email, state);
  return state;
}

async function apiFor(email) {
  return request.newContext({ baseURL, storageState: await stateFor(email) });
}

function csv(values) {
  return values.map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n');
}

async function setRolePermission(page, name, checked) {
  const input = page.getByRole('checkbox', { name: new RegExp(`^${name}(?:\\s|$)`) });
  await expect(input).toHaveCount(1);
  if ((await input.isChecked()) !== checked) {
    if (checked) await input.check({ force: true });
    else await input.uncheck({ force: true });
  }
}

test.describe.configure({ mode: 'serial' });

test('School Admin permission UI grants and revokes Finance expense access without bypassing Cashier account scope', async ({ browser }) => {
  const adminApi = await apiFor('qa_admin@bowen-qa.test');
  let adminContext;
  try {
    const roles = await adminApi.get('/roles-list');
    expect(roles.status()).toBe(200);
    const headRole = (await roles.json()).rows.find((role) => role.name === 'Head Finance');
    expect(headRole).toBeTruthy();

    adminContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_admin@bowen-qa.test') });
    const page = await adminContext.newPage();
    expect((await page.goto(`/roles/${headRole.id}/edit`, { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
    await expect(page.getByText('finance-expense-view')).toBeVisible();
    await expect(page.getByText('finance-expense-create')).toBeVisible();

    // The local fixture deliberately retains legacy grants for compatibility.
    // Remove both representations first, then prove the named Finance grants
    // alone restore the matching route/menu access.
    for (const permission of ['finance-expense-view', 'finance-expense-create', 'expense-list', 'expense-create']) await setRolePermission(page, permission, false);
    const revoke = page.waitForResponse((response) => response.url().endsWith(`/roles/${headRole.id}`) && response.request().method() === 'POST');
    await page.locator('form.edit-form-without-reset input[type="submit"]').click();
    expect((await revoke).status()).toBe(200);

    const revokedContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_head_finance@bowen-qa.test') });
    try {
      const revoked = await revokedContext.request.get('/expense', { maxRedirects: 0 });
      expect([302, 403]).toContain(revoked.status());
      const revokedPage = await revokedContext.newPage();
      await revokedPage.goto('/dashboard', { waitUntil: 'domcontentloaded' });
      await expect(revokedPage.locator('a[href$="/expense"]')).toHaveCount(0);
    } finally { await revokedContext.close(); }

    await page.goto(`/roles/${headRole.id}/edit`, { waitUntil: 'domcontentloaded' });
    for (const permission of ['finance-expense-view', 'finance-expense-create']) await setRolePermission(page, permission, true);
    const grant = page.waitForResponse((response) => response.url().endsWith(`/roles/${headRole.id}`) && response.request().method() === 'POST');
    await page.locator('form.edit-form-without-reset input[type="submit"]').click();
    expect((await grant).status()).toBe(200);

    const grantedContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_head_finance@bowen-qa.test') });
    try {
      expect((await grantedContext.request.get('/expense', { maxRedirects: 0 })).status()).toBe(200);
      const grantedPage = await grantedContext.newPage();
      await grantedPage.goto('/dashboard', { waitUntil: 'domcontentloaded' });
      await expect(grantedPage.locator('a[href$="/expense"]')).toHaveCount(1);
    } finally { await grantedContext.close(); }

    const cashier = await apiFor('qa_cashier_a@bowen-qa.test');
    try {
      const accounts = (await (await adminApi.get('/bank-accounts/list')).json()).rows;
      const forbidden = accounts.find((account) => account.account_number === 'QA_P2_CASH_B');
      expect(forbidden).toBeTruthy();
      expect([403, 404]).toContain((await cashier.get(`/bank-accounts/${forbidden.id}`, { maxRedirects: 0 })).status());
    } finally { await cashier.dispose(); }
  } finally {
    await adminContext?.close();
    await adminApi.dispose();
  }
});

test('Expense Import UI previews without writes, confirms create-only rows, and rejects invalid or unassigned account data', async ({ page, browser }) => {
  const reference = `BOWEN_QA_P31_EXP_${Date.now()}`;
  const heading = ['Date', 'Expense Category', 'Finance Category', 'Title', 'Reference No', 'Amount (MMK)', 'Payment Method', 'Fund Account Name', 'Remark', 'Academic Year'];
  const nativeDialogs = [];
  const adminApi = await apiFor('qa_admin@bowen-qa.test');
  const currentP1Bank = (await (await adminApi.get('/bank-accounts/list')).json()).rows
    .find((account) => account.account_number === 'BOWEN_QA_P1_BANK');
  expect(currentP1Bank).toBeTruthy();
  const valid = csv([heading, ['2026-08-13', 'Bowen QA Expense Category', '', 'BOWEN_QA P3.1 UI expense', reference, '75', 'Cash', currentP1Bank.account_name, 'browser import', 'Bowen QA 2026']]);
  const invalid = csv([heading, ['2026-08-13', 'Bowen QA Expense Category', '', 'invalid browser expense', `${reference}-INVALID`, '0', 'Cash', currentP1Bank.account_name, '', 'Bowen QA 2026']]);
  const unassigned = csv([heading, ['2026-08-13', 'Bowen QA Expense Category', '', 'unassigned browser expense', `${reference}-FORGED`, '1', 'Cash', 'QA P2 Cash B', '', 'Bowen QA 2026']]);
  page.on('dialog', (dialog) => { nativeDialogs.push(dialog.type()); dialog.dismiss(); });

  try {
    expect((await page.goto('/expense', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
    const templateLink = page.getByText('Download business-field template');
    expect(await templateLink.getAttribute('href')).toContain('/expense/import/template');
    const template = await page.request.get(await templateLink.getAttribute('href'));
    expect(template.status()).toBe(200);
    expect(template.headers()['content-disposition']).toContain('expense_import_template.xlsx');
    await page.getByRole('button', { name: 'Import Expenses' }).click();
    await expect(page.getByRole('dialog', { name: 'Expense Excel Import' })).toBeVisible();
    await page.locator('#expense-import-upload input[type="file"]').setInputFiles({ name: `${reference}.csv`, mimeType: 'text/csv', buffer: Buffer.from(valid) });
    const preview = page.waitForResponse((response) => response.url().endsWith('/expense/import/preview') && response.request().method() === 'POST');
    await page.locator('#expense-import-upload button[type="submit"]').click();
    expect((await preview).status()).toBe(200);
    await expect(page.locator('#expense-import-summary')).toContainText('1 rows: 1 valid');
    await expect(page.locator('#expense-import-confirm')).toBeVisible();
    await expect(page.locator('#expense-import-result')).toContainText(reference);
    const beforeConfirm = await adminApi.get('/expense/1', { params: { search: reference } });
    expect((await beforeConfirm.json()).total).toBe(0);

    const confirm = page.waitForResponse((response) => response.url().endsWith('/expense/import/confirm') && response.request().method() === 'POST');
    await page.locator('#expense-import-confirm').click();
    expect((await confirm).status()).toBe(200);
    await expect(page.locator('#expenseImportModal')).toBeHidden();
    const afterConfirm = await adminApi.get('/expense/1', { params: { search: reference } });
    expect((await afterConfirm.json()).total).toBe(1);
    const baseline = await adminApi.get('/expense/1', { params: { search: 'BOWEN_QA_P1_EXPENSE_001' } });
    expect((await baseline.json()).total).toBe(1);

    await page.getByRole('button', { name: 'Import Expenses' }).click();
    await page.locator('#expense-import-upload input[type="file"]').setInputFiles({ name: `${reference}-invalid.csv`, mimeType: 'text/csv', buffer: Buffer.from(invalid) });
    await page.locator('#expense-import-upload button[type="submit"]').click();
    await expect(page.locator('#expense-import-summary')).toContainText('0 valid, 1 invalid');
    await expect(page.locator('#expense-import-confirm')).toBeHidden();

    const cashierContext = await browser.newContext({ baseURL, storageState: await stateFor('qa_cashier_a@bowen-qa.test') });
    try {
      const cashierPage = await cashierContext.newPage();
      expect((await cashierPage.goto('/expense', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
      await cashierPage.getByRole('button', { name: 'Import Expenses' }).click();
      await cashierPage.locator('#expense-import-upload input[type="file"]').setInputFiles({ name: `${reference}-unassigned.csv`, mimeType: 'text/csv', buffer: Buffer.from(unassigned) });
      await cashierPage.locator('#expense-import-upload button[type="submit"]').click();
      await expect(cashierPage.locator('#expense-import-summary')).toContainText('0 valid, 1 invalid');
      await expect(cashierPage.locator('#expense-import-result')).toContainText(/Fund Account was not found/i);
    } finally { await cashierContext.close(); }

    expect(nativeDialogs).toEqual([]);
  } finally {
    await adminApi.dispose();
  }
});
