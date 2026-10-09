const { test, expect, request } = require('@playwright/test');

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local login form did not provide a CSRF token.');
  return match[1];
}

async function groupHeadFinanceState(baseURL) {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const loginPage = await api.get('/login', { maxRedirects: 0 });
    expect(loginPage.status()).toBe(200);
    const response = await api.post('/login', {
      form: {
        _token: csrfToken(await loginPage.text()),
        email: 'group_hq@group-qa.test',
        password: 'local-only',
      },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(response.status());
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

test('Group Head Finance manages an unused Promotion without changing Finance history', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ baseURL, storageState: await groupHeadFinanceState(baseURL) });
  const page = await context.newPage();
  const failures = [];
  page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
  page.on('response', (response) => { if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${response.url()}`); });

  const code = `LOCAL-PROMO-${Date.now()}`;
  const response = await page.goto('/central-finance/promotions', { waitUntil: 'networkidle' });
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Promotion definitions', exact: true })).toBeVisible();

  const create = page.locator('form').filter({ has: page.locator('[name="group_id"]') });
  await create.locator('[name="group_id"]').selectOption({ index: 0 });
  await create.locator('[name="name"]').fill('Local Promotion Lifecycle');
  await create.locator('[name="code"]').fill(code);
  await create.locator('[name="discount_type"]').selectOption('percentage');
  await create.locator('[name="discount_value"]').fill('10');
  await create.locator('[name="valid_from"]').fill('2026-01-01');
  await create.locator('[name="valid_until"]').fill('2026-12-31');
  await create.locator('[name="school_ids[]"]').first().check();
  await create.locator('[name="description"]').fill('Synthetic local browser acceptance only.');
  await create.getByRole('button', { name: 'Create promotion', exact: true }).click();
  await expect(page.getByText('Promotion definition created.', { exact: true })).toBeVisible();

  const card = page.locator('[data-promotion-id]').filter({ hasText: code });
  await expect(card).toHaveCount(1);
  await expect(card).toContainText('active');
  await expect(card).toContainText('qa_test');
  await card.getByText('Edit unused Promotion', { exact: true }).click();
  const edit = card.locator('form').nth(1);
  await edit.locator('[name="name"]').fill('Local Promotion Lifecycle Updated');
  await edit.locator('[name="discount_type"]').selectOption('fixed');
  await edit.locator('[name="discount_value"]').fill('75');
  await edit.locator('[name="valid_from"]').fill('2026-02-01');
  await edit.locator('[name="valid_until"]').fill('2026-11-30');
  await edit.locator('[name="reason"]').fill('Local browser edit verification.');
  await edit.getByRole('button', { name: 'Save Promotion changes', exact: true }).click();
  await expect(page.getByText('Promotion definition updated.', { exact: true })).toBeVisible();
  await expect(page.locator('[data-promotion-id]').filter({ hasText: code })).toContainText('Local Promotion Lifecycle Updated');

  const updated = page.locator('[data-promotion-id]').filter({ hasText: code });
  const disable = updated.locator('form').first();
  await disable.locator('[name="reason"]').fill('Local browser disable verification.');
  await disable.getByRole('button', { name: 'Disable', exact: true }).click();
  await expect(page.getByText('Promotion disabled for future use.', { exact: true })).toBeVisible();

  const inactive = page.locator('[data-promotion-id]').filter({ hasText: code });
  const enable = inactive.locator('form').first();
  await enable.locator('[name="reason"]').fill('Local browser enable verification.');
  await enable.getByRole('button', { name: 'Enable', exact: true }).click();
  await expect(page.getByText('Promotion enabled for future use.', { exact: true })).toBeVisible();

  const finalCard = page.locator('[data-promotion-id]').filter({ hasText: code });
  await finalCard.getByText('Edit unused Promotion', { exact: true }).click();
  page.once('dialog', (dialog) => dialog.accept());
  const deletion = finalCard.locator('form').nth(2);
  await deletion.locator('[name="reason"]').fill('Local browser unused Promotion delete verification.');
  await deletion.getByRole('button', { name: 'Delete unused Promotion', exact: true }).click();
  await expect(page.getByText('Unused Promotion deleted with an audit record.', { exact: true })).toBeVisible();
  await expect(page.locator('[data-promotion-id]').filter({ hasText: code })).toHaveCount(0);
  expect(failures).toEqual([]);
  await context.close();
});
