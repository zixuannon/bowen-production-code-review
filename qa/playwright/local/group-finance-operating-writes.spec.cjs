const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8011';

test.setTimeout(120_000);

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('Missing local CSRF token.');
  return match[1];
}

async function centralStorageState() {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const login = await api.get('/login', { maxRedirects: 0 });
    const response = await api.post('/login', {
      form: { _token: csrfToken(await login.text()), email: 'group_hq@group-qa.test', password: 'local-only' },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(response.status());
    return await api.storageState();
  } finally { await api.dispose(); }
}

async function enterSchool(page, schoolPattern) {
  await page.goto('/group-finance', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/group-finance\/\d+$/);
  const select = page.locator('#operating-school');
  const options = await select.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' })));
  const school = options.find(option => schoolPattern.test(option.text));
  expect(school?.value).toBeTruthy();
  await select.selectOption(school.value);
  await page.locator('[data-operating-school-switcher] button').click();
  await page.waitForURL(/group-finance\/operating\/bank-accounts$/);
  const operations = page.locator('[data-operating-finance-nav]').getByRole('link', { name: 'Finance Operations', exact: true });
  await Promise.all([page.waitForURL(/group-finance\/operating\/operations$/), operations.click()]);
}

test('Central Head Finance writes only the selected School through canonical Finance sources and tenant audit', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await centralStorageState() });
  try {
    const page = await context.newPage();
    await enterSchool(page, /Zixuan QA School/);
    await expect(page.locator('[data-operating-school-banner]')).toContainText('Zixuan QA School');

    const other = page.locator('[data-operating-receive-money-form]');
    await other.locator('input[name=payer]').fill('Operating QA payer');
    await other.locator('input[name=description]').fill('Operating QA other income');
    await other.locator('input[name=amount]').fill('101');
    await other.locator('input[name=reference_no]').fill('GROUP_OP_A_OTHER');
    await other.getByRole('button', { name: 'Receive Money' }).click();
    await expect(other).toBeVisible();

    const expense = page.locator('[data-operating-expense-form]');
    await expense.locator('input[name=title]').fill('Operating QA expense');
    await expense.locator('input[name=amount]').fill('31');
    await expense.locator('input[name=ref_no]').fill('GROUP_OP_A_EXPENSE');
    await expense.getByRole('button', { name: 'Create Expense' }).click();
    await expect(expense).toBeVisible();

    const fee = page.locator('[data-operating-student-fee-form]');
    await fee.locator('select[name=fees_id]').selectOption({ label: 'Group QA Operating Fee' });
    await fee.locator('input[name=enter_amount]').fill('150');
    await fee.locator('input[name=reference_no]').fill('GROUP_OP_A_FEE');
    await fee.getByRole('button', { name: 'Receive Student Fee' }).click();
    await expect(fee).toBeVisible();

    await page.getByRole('link', { name: 'Transactions' }).click();
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_OP_A_OTHER');
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_OP_A_EXPENSE');
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_OP_A_FEE');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_QA_SCHOOL_B_OTHER');

    await page.getByRole('link', { name: 'Switch School' }).click();
    await enterSchool(page, /Timecity QA School/);
    await expect(page.locator('[data-operating-transactions]')).toHaveCount(0);
    await page.getByRole('link', { name: 'Transactions' }).click();
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_OTHER');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_EXPENSE');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_FEE');
  } finally { await context.close(); }
});
