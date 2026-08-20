const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8011';
test.setTimeout(120_000);

function csrfToken(html) {
  const match = html.match(/<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i)
    || html.match(/<input[^>]+name=["']_token["'][^>]*value=["']([^"']+)["']/i);
  if (!match) throw new Error('Missing local CSRF token.');
  return match[1];
}

async function centralStorageState() {
  const api = await request.newContext({ baseURL });
  try {
    const login = await api.get('/login');
    const result = await api.post('/login', {
      form: { _token: csrfToken(await login.text()), email: 'group_hq@group-qa.test', password: 'local-only' },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(result.status());
    const group = await api.get('/group-finance', { maxRedirects: 0 });
    expect(group.status()).toBe(302);
    return await api.storageState();
  } finally { await api.dispose(); }
}

async function enterSchool(page, name) {
  const response = await page.goto('/group-finance', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page).toHaveURL(/\/group-finance\/\d+$/);
  const select = page.locator('#operating-school');
  const option = (await select.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' }))))
    .find(row => row.text.includes(name));
  expect(option?.value).toBeTruthy();
  await select.selectOption(option.value);
  await page.locator('[data-operating-school-switcher] button').click();
  await page.waitForURL(/group-finance\/operating\/bank-accounts$/);
}

async function selectByText(select, expression) {
  const option = (await select.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' }))))
    .find(row => expression.test(row.text));
  expect(option?.value).toBeTruthy();
  await select.selectOption(option.value);
}

async function fund(page, direction, amount, reference, accountPattern) {
  await Promise.all([
    page.waitForURL(/finance-groups\/\d+\/funding$/),
    page.getByRole('link', { name: 'HQ / School Funding' }).click(),
  ]);
  const form = page.locator('form').filter({ has: page.getByRole('button', { name: 'Submit for Head Finance confirmation' }) });
  await selectByText(form.locator('#group-transfer-account'), accountPattern);
  await form.locator('select[name=direction]').selectOption(direction);
  await form.locator('input[name=amount]').fill(String(amount));
  await form.locator('input[name=reference_no]').fill(reference);
  await form.getByRole('button', { name: 'Submit for Head Finance confirmation' }).click();
  const row = page.getByRole('row').filter({ hasText: reference });
  await expect(row).toContainText('Pending');
  await row.locator('select[name=hq_account_id]').selectOption({ index: 1 });
  await row.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('row').filter({ hasText: reference })).toContainText('Confirmed');
}

test('Central Head Finance funds only the current operating School and reconciles it in Group Reports', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, storageState: await centralStorageState() });
  try {
    const page = await context.newPage();
    await enterSchool(page, 'Zixuan QA School');
    await expect(page.locator('[data-operating-school-banner]')).toContainText('Zixuan QA School');
    await fund(page, 'HQ_TO_SCHOOL', 50, 'GROUP_C5_A_FUNDING', /Zixuan QA School Cash/);
    await Promise.all([
      page.waitForURL(/group-finance\/operating\/operations$/),
      page.getByRole('link', { name: 'Back to Operating School' }).click(),
    ]);
    await Promise.all([
      page.waitForURL(/group-finance\/\d+$/),
      page.getByRole('button', { name: 'Return to All Schools' }).click(),
    ]);

    await enterSchool(page, 'Timecity QA School');
    await expect(page.locator('[data-operating-school-banner]')).toContainText('Timecity QA School');
    await fund(page, 'SCHOOL_TO_HQ', 40, 'GROUP_C5_B_REMITTANCE', /Timecity QA School Cash/);
    await Promise.all([
      page.waitForURL(/group-finance\/operating\/operations$/),
      page.getByRole('link', { name: 'Back to Operating School' }).click(),
    ]);
    await Promise.all([
      page.waitForURL(/group-finance\/\d+$/),
      page.getByRole('button', { name: 'Return to All Schools' }).click(),
    ]);
    await expect(page.locator('table')).toContainText('GROUP_C5_A_FUNDING');
    await expect(page.locator('table')).toContainText('GROUP_C5_B_REMITTANCE');
  } finally { await context.close(); }
});
