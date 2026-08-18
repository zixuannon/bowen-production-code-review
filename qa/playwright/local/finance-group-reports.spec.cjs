const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const groupId = process.env.GROUP_QA_GROUP_ID;
const email = 'group_hq@group-qa.test';
const password = 'local-only';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('Expected CSRF token from the local login form.');
  return match[1];
}

async function groupQaState() {
  if (!groupId || !/^\d+$/.test(groupId)) {
    throw new Error('GROUP_QA_GROUP_ID must be the local synthetic Group ID.');
  }
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const login = await api.get('/login', { maxRedirects: 0 });
    expect(login.status()).toBe(200);
    const response = await api.post('/login', {
      form: { _token: csrfToken(await login.text()), email, password },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(response.status());
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

test('GROUP_QA HQ report renders only member Schools and exports canonical Ledger V1 rows', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await groupQaState() });
  try {
    const page = await context.newPage();
    const dialogs = [];
    page.on('dialog', dialog => { dialogs.push(dialog.type()); dialog.dismiss(); });

    const response = await page.goto(`/finance-groups/${groupId}/reports`, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    await expect(page.getByText('Bowen QA Group')).toBeVisible();
    await expect(page.getByRole('cell', { name: 'GROUP QA SCHOOL A', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('cell', { name: 'GROUP QA SCHOOL B', exact: true }).first()).toBeVisible();
    await expect(page.getByText('GROUP QA UNRELATED', { exact: false })).toHaveCount(0);
    await expect(page.getByText('GROUP_QA_SCHOOL_A_HANDOVER_PENDING', { exact: false })).toHaveCount(0);
    await expect(page.getByText('GROUP_QA_SCHOOL_B_HANDOVER_PENDING', { exact: false })).toHaveCount(0);
    await expect(page.getByText('GROUP_QA_SCHOOL_A_HANDOVER_TRANSFER', { exact: false })).toBeVisible();

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV' }).click();
    const file = await download;
    expect(file.suggestedFilename()).toBe('finance-group-ledger.csv');
    expect(dialogs).toEqual([]);
  } finally {
    await context.close();
  }
});
