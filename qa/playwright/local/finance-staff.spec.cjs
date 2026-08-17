const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');
const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
async function state(email) { const p=`/tmp/finance-staff-${email.replace(/[^a-z]/g,'_')}.json`; await authenticateLocalBowenQa(email,p); return p; }
test('School Admin and Head Finance can view Finance Staff while Accountant is rejected', async ({browser}) => {
  for (const email of ['qa_admin@bowen-qa.test','qa_head_finance@bowen-qa.test']) { const c=await browser.newContext({baseURL,storageState:await state(email)}); const p=await c.newPage(); const r=await p.goto('/finance-staff'); expect(r.status()).toBe(200); await expect(p.getByRole('heading',{name:'Finance Staff'})).toBeVisible(); await c.close(); }
  const api=await request.newContext({baseURL,storageState:await state('qa_cashier_a@bowen-qa.test')}); try { expect((await api.get('/finance-staff',{maxRedirects:0})).status()).toBe(403); } finally { await api.dispose(); }
});

test('School Admin manages Finance roles and Accountant Fund Accounts through application modals without native dialogs', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await state('qa_admin@bowen-qa.test') });
  try {
    const page = await context.newPage();
    const dialogs = [];
    page.on('dialog', dialog => { dialogs.push(dialog.type()); dialog.dismiss(); });
    expect((await page.goto('/finance-staff', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
    const teacherRow = page.locator('#finance-staff-table tbody tr', { hasText: 'QA Teacher' });
    const cashierRow = page.locator('#finance-staff-table tbody tr', { hasText: 'QA Cashier A' });
    await expect(teacherRow).toBeVisible();
    await expect(teacherRow.getByRole('button', { name: 'Manage' })).toHaveCount(1);
    await expect(teacherRow.getByText('Assign/Remove', { exact: false })).toHaveCount(0);

    await cashierRow.getByRole('button', { name: 'Manage' }).click();
    const modal = page.getByRole('dialog', { name: 'Manage Finance Staff' });
    await expect(page.locator('#finance-staff-accounts-form')).toBeVisible();
    await expect(page.locator('.finance-staff-account')).not.toHaveCount(0);
    const firstAccount = page.locator('.finance-staff-account').first();
    await firstAccount.check({ force: true });
    const saveAccounts = page.waitForResponse(response => response.url().includes('/finance-staff/') && response.url().endsWith('/accounts') && response.request().method() === 'PUT');
    await modal.getByRole('button', { name: 'Save Fund Accounts' }).click();
    expect((await saveAccounts).status()).toBe(200);
    await page.goto('/finance-staff', { waitUntil: 'networkidle' });
    await expect(cashierRow.locator('td').nth(2)).not.toHaveText('None assigned');

    await teacherRow.getByRole('button', { name: 'Manage' }).click();
    await expect(modal).toContainText('QA Teacher');
    await expect(modal).toContainText('No Finance Role');
    const assignAccountant = page.waitForResponse(response => response.url().includes('/finance-staff/') && response.url().endsWith('/role') && response.request().method() === 'POST');
    await modal.getByRole('button', { name: 'Assign Accountant' }).click();
    expect((await assignAccountant).status()).toBe(200);
    await page.goto('/finance-staff', { waitUntil: 'networkidle' });
    await expect(teacherRow).toContainText('Accountant');

    await teacherRow.getByRole('button', { name: 'Manage' }).click();
    const removeAccountant = page.waitForResponse(response => response.url().includes('/finance-staff/') && response.url().endsWith('/role') && response.request().method() === 'POST');
    await modal.getByRole('button', { name: 'Remove Accountant' }).click();
    expect((await removeAccountant).status()).toBe(200);
    await page.goto('/finance-staff', { waitUntil: 'networkidle' });
    await expect(teacherRow).toContainText('No Finance Role');

    await teacherRow.getByRole('button', { name: 'Manage' }).click();
    const assignHead = page.waitForResponse(response => response.url().includes('/finance-staff/') && response.url().endsWith('/role') && response.request().method() === 'POST');
    await modal.getByRole('button', { name: 'Assign Head Finance' }).click();
    expect((await assignHead).status()).toBe(200);
    await page.goto('/finance-staff', { waitUntil: 'networkidle' });
    await expect(teacherRow).toContainText('Head Finance');

    await teacherRow.getByRole('button', { name: 'Manage' }).click();
    const removeHead = page.waitForResponse(response => response.url().includes('/finance-staff/') && response.url().endsWith('/role') && response.request().method() === 'POST');
    await modal.getByRole('button', { name: 'Remove Head Finance' }).click();
    expect((await removeHead).status()).toBe(200);
    await page.goto('/finance-staff', { waitUntil: 'networkidle' });
    await expect(teacherRow).toContainText('No Finance Role');
    expect(dialogs).toEqual([]);
  } finally { await context.close(); }
});
