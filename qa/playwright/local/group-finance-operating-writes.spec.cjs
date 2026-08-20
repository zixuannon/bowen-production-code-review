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

async function selectByText(select, pattern) {
  const options = await select.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' })));
  const option = options.find(item => pattern.test(item.text));
  expect(option?.value).toBeTruthy();
  await select.selectOption(option.value);
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

    const transfer = page.locator('[data-operating-bank-transfer-form]');
    await selectByText(transfer.locator('select[name=from_account_id]'), /Zixuan QA School Cash/);
    await selectByText(transfer.locator('select[name=to_account_id]'), /Zixuan QA School Bank/);
    await transfer.locator('input[name=amount]').fill('20');
    await transfer.locator('input[name=reference_no]').fill('GROUP_OP_A_TRANSFER');
    await transfer.getByRole('button', { name: 'Create Bank Transfer' }).click();
    await expect(transfer).toBeVisible();

    const handover = page.locator('[data-operating-fund-handover-form]');
    await selectByText(handover.locator('select[name=from_account_id]'), /Zixuan QA School Bank/);
    await handover.locator('select[name=receiver_id]').selectOption({ label: 'School Accountant' });
    await expect(handover.locator('select[name=to_account_id]')).toBeEnabled();
    await selectByText(handover.locator('select[name=to_account_id]'), /Zixuan QA School Cash/);
    await handover.locator('input[name=amount]').fill('25');
    await handover.locator('input[name=reference_no]').fill('GROUP_OP_A_HANDOVER');
    await handover.getByRole('button', { name: 'Request Handover' }).click();
    await expect(handover).toBeVisible();

    await page.getByRole('link', { name: 'Transactions' }).click();
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_OP_A_OTHER');
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_OP_A_EXPENSE');
    await expect(page.locator('[data-operating-transactions]')).toContainText('GROUP_OP_A_FEE');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_HANDOVER');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_QA_SCHOOL_B_OTHER');

    await page.getByRole('link', { name: 'Switch School' }).click();
    await enterSchool(page, /Timecity QA School/);
    await expect(page.locator('[data-operating-transactions]')).toHaveCount(0);
    await page.getByRole('link', { name: 'Transactions' }).click();
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_OTHER');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_EXPENSE');
    await expect(page.locator('[data-operating-transactions]')).not.toContainText('GROUP_OP_A_FEE');
    await Promise.all([
      page.waitForURL(/group-finance\/operating\/operations$/),
      page.getByRole('link', { name: 'Finance Operations', exact: true }).click(),
    ]);
    await expect(page.locator('[data-operating-school-banner]')).toContainText('Timecity QA School');
    const timecityTransfer = page.locator('[data-operating-bank-transfer-form]');
    await selectByText(timecityTransfer.locator('select[name=from_account_id]'), /Timecity QA School Cash/);
    await selectByText(timecityTransfer.locator('select[name=to_account_id]'), /Timecity QA School Bank/);
    await timecityTransfer.locator('input[name=amount]').fill('20');
    await timecityTransfer.locator('input[name=reference_no]').fill('GROUP_OP_B_TRANSFER');
    await timecityTransfer.getByRole('button', { name: 'Create Bank Transfer' }).click();
    await expect(timecityTransfer).toBeVisible();
  } finally { await context.close(); }
});
