const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const groupId = process.env.GROUP_QA_GROUP_ID;
const password = 'local-only';
const reference = 'GROUP_QA_BROWSER_FUNDING_001';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('Expected CSRF token from the local login form.');
  return match[1];
}

async function loginState(email) {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const login = await api.get('/login', { maxRedirects: 0 });
    expect(login.status()).toBe(200);
    const result = await api.post('/login', { form: { _token: csrfToken(await login.text()), email, password }, maxRedirects: 0 });
    expect([302, 303]).toContain(result.status());
    return await api.storageState();
  } finally { await api.dispose(); }
}

test('GROUP_QA School Accountant request is pending until Group Head Finance confirms it', async ({ browser }) => {
  if (!groupId || !/^\d+$/.test(groupId)) throw new Error('GROUP_QA_GROUP_ID must be the local synthetic Group ID.');
  const dialogs = [];
  const schoolContext = await browser.newContext({ baseURL, storageState: await loginState('group_school_a@group-qa.test') });
  try {
    const schoolPage = await schoolContext.newPage();
    schoolPage.on('dialog', dialog => { dialogs.push(dialog.type()); dialog.dismiss(); });
    const load = await schoolPage.goto(`/finance-groups/${groupId}/funding`, { waitUntil: 'domcontentloaded' });
    expect(load?.status()).toBe(200);
    await schoolPage.locator('#group-transfer-school').selectOption({ label: 'GROUP QA SCHOOL A' });
    await expect(schoolPage.locator('#group-transfer-account')).toBeEnabled();
    await schoolPage.locator('#group-transfer-account').selectOption({ index: 1 });
    await schoolPage.locator('select[name="direction"]').selectOption('HQ_TO_SCHOOL');
    await schoolPage.locator('input[name="amount"]').fill('10');
    await schoolPage.locator('input[name="reference_no"]').fill(reference);
    await schoolPage.getByRole('button', { name: 'Submit for Head Finance confirmation' }).click();
    await expect(schoolPage.getByText('Group funding request submitted for Head Finance confirmation.')).toBeVisible();
    const pending = schoolPage.getByRole('row').filter({ hasText: reference });
    await expect(pending).toContainText('Pending');

    const headContext = await browser.newContext({ baseURL, storageState: await loginState('group_hq@group-qa.test') });
    try {
      const headPage = await headContext.newPage();
      headPage.on('dialog', dialog => { dialogs.push(dialog.type()); dialog.dismiss(); });
      const response = await headPage.goto(`/finance-groups/${groupId}/funding`, { waitUntil: 'domcontentloaded' });
      expect(response?.status()).toBe(200);
      const transfer = headPage.getByRole('row').filter({ hasText: reference });
      await expect(transfer).toContainText('Pending');
      await transfer.locator('select[name="hq_account_id"]').selectOption({ index: 1 });
      await transfer.getByRole('button', { name: 'Confirm' }).click();
      await expect(headPage.getByText('Group funding transfer confirmed.')).toBeVisible();
      await expect(headPage.getByRole('row').filter({ hasText: reference })).toContainText('Confirmed');
    } finally { await headContext.close(); }
  } finally { await schoolContext.close(); }
  expect(dialogs).toEqual([]);
});
