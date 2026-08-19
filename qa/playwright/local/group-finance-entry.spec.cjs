const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8011';
const groupId = process.env.GROUP_QA_GROUP_ID || '1';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local login page did not contain a CSRF token.');
  return match[1];
}

async function login(email, expectedRedirect = '/group-finance') {
  const api = await request.newContext({ baseURL });
  try {
    const form = await api.get('/login', { maxRedirects: 0 });
    expect(form.status()).toBe(200);
    const response = await api.post('/login', {
      form: { _token: csrfToken(await form.text()), email, password: 'local-only' },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(response.status());
    expect(new URL(response.headers().location, baseURL).pathname).toBe(expectedRedirect);
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

test('central Head Finance sees All Schools, can switch a scoped School, and exports Group Ledger', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await login('group_hq@group-qa.test') });
  try {
    const page = await context.newPage();
    await page.goto('/group-finance', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(new RegExp(`/group-finance/${groupId}$`));
    await expect(page.getByRole('heading', { name: 'Group Finance' })).toBeVisible();
    const switcher = page.locator('select[name="school_id"]');
    await expect(switcher.locator('option')).toHaveCount(3);

    const values = await switcher.locator('option').evaluateAll(options => options.map(option => ({ value: option.value, text: option.textContent })));
    expect(values.some(option => /All Schools/.test(option.text || ''))).toBeTruthy();
    const schoolA = values.find(option => /Zixuan QA School/.test(option.text || ''));
    const schoolB = values.find(option => /Timecity QA School/.test(option.text || ''));
    expect(schoolA?.value).toBeTruthy();
    expect(schoolB?.value).toBeTruthy();

    await switcher.selectOption(schoolA.value);
    await page.waitForURL(new RegExp(`school_id=${schoolA.value}`));
    await expect(page.getByText('Authorized Fund Accounts')).toBeVisible();
    const accountsCard = page.locator('.card').filter({ hasText: 'Authorized Fund Accounts' });
    await expect(accountsCard.getByText(/Zixuan QA School Cash/).first()).toBeVisible();
    await expect(accountsCard.getByText(/Timecity QA School Cash/)).toHaveCount(0);

    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV' }).click();
    expect((await download).suggestedFilename()).toBe('group-finance-ledger.csv');
  } finally {
    await context.close();
  }
});

test('School Accountant cannot switch to the peer or unrelated School', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await login('group_school_a@group-qa.test') });
  try {
    const page = await context.newPage();
    await page.goto('/group-finance', { waitUntil: 'domcontentloaded' });
    const switcher = page.locator('select[name="school_id"]');
    await expect(switcher.locator('option')).toHaveCount(2);
    const values = await switcher.locator('option').evaluateAll(options => options.map(option => option.textContent || ''));
    expect(values.some(value => /Zixuan QA School/.test(value))).toBeTruthy();
    expect(values.some(value => /Timecity QA School|Unrelated QA School/.test(value))).toBeFalsy();
  } finally {
    await context.close();
  }
});
