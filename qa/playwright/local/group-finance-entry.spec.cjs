const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8011';

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local login page did not contain a CSRF token.');
  return match[1];
}

async function login(email, expectedRedirect = '/group-finance') {
  // Do not inherit the suite's BOWEN_QA storage state: Group Finance uses a
  // separate synthetic central identity in the same local browser process.
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
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
    await expect(page).toHaveURL(/\/group-finance\/\d+$/);
    const groupId = page.url().match(/\/group-finance\/(\d+)$/)?.[1];
    expect(groupId).toBeTruthy();
    await expect(page.getByRole('heading', { name: 'Group Finance' })).toBeVisible();
    const switcher = page.locator('#group-finance-school');
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

    // Scheme B Operating Context: the same central session selects an
    // authorized School but never becomes the mapped tenant user.
    const operatingSwitcher = page.locator('[data-operating-school-switcher]');
    await expect(operatingSwitcher).toBeVisible();
    const operatingSchool = operatingSwitcher.locator('#operating-school');
    await expect(operatingSchool.locator('option')).toHaveCount(2);
    await operatingSchool.selectOption(schoolA.value);
    await operatingSwitcher.getByRole('button', { name: 'Open Finance Workspace' }).click();
    await page.waitForURL(/group-finance\/operating\/bank-accounts$/);
    await expect(page.locator('[data-operating-school-banner]')).toContainText('Zixuan QA School');
    await expect(page.locator('[data-operating-bank-accounts]')).toContainText('Zixuan QA School Cash');
    await expect(page.locator('[data-operating-bank-accounts]')).not.toContainText('Timecity QA School Cash');
    await page.getByRole('link', { name: 'Transactions' }).click();
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_QA_SCHOOL_A_OTHER');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_QA_SCHOOL_B_OTHER');
    await page.getByRole('link', { name: 'Finance Reports' }).click();
    await expect(page.locator('[data-operating-finance-report]')).toContainText('240.00');

    await page.getByRole('link', { name: 'Switch School' }).click();
    await operatingSchool.selectOption(schoolB.value);
    await operatingSwitcher.getByRole('button', { name: 'Open Finance Workspace' }).click();
    await page.waitForURL(/group-finance\/operating\/bank-accounts$/);
    await expect(page.locator('[data-operating-school-banner]')).toContainText('Timecity QA School');
    await expect(page.locator('[data-operating-bank-accounts]')).toContainText('Timecity QA School Cash');
    await expect(page.locator('[data-operating-bank-accounts]')).not.toContainText('Zixuan QA School Cash');
    await page.getByRole('button', { name: 'Return to All Schools' }).click();
    await page.waitForURL(new RegExp(`/group-finance/${groupId}$`));
    await expect(page.getByText('Unrelated QA School', { exact: false })).toHaveCount(0);
  } finally {
    await context.close();
  }
});

test('School Accountant remains in the ordinary School dashboard and has no Group switcher', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await login('group_school_a@group-qa.test', '/dashboard') });
  try {
    const page = await context.newPage();
    await page.goto('/group-finance', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#group-finance-school')).toHaveCount(0);
    await expect(page.locator('[data-operating-school-switcher]')).toHaveCount(0);
    await expect(page.getByText('Bowen QA Group', { exact: false })).toHaveCount(0);
  } finally {
    await context.close();
  }
});
