const { test, expect } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

test('P1-A undated Fee can be edited to a date, reloaded, then cleared back to NULL', async ({ browser }) => {
  const feeId = Number(process.env.P1A_FEE_ID);
  const editorEmail = process.env.P1A_EDITOR_EMAIL;
  expect(feeId).toBeGreaterThan(0);
  expect(editorEmail).toContain('@bowen-qa.test');
  const storageState = await authenticateLocalBowenQa(editorEmail);
  const context = await browser.newContext({
    baseURL: process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000',
    storageState,
    viewport: { width: 1440, height: 900 },
  });
  const page = await context.newPage();
  const failures = [];
  page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
  page.on('response', (response) => { if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${response.url()}`); });

  const editUrl = `/fees/${feeId}/edit`;
  const initial = await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  expect(initial?.status()).toBe(200);
  await expect(page.locator('#due_date')).toHaveValue('');

  const dueDate = page.locator('#due_date');
  await dueDate.click();
  const monthLabel = page.locator('.datepicker-dropdown .datepicker-days th.datepicker-switch');
  for (let step = 0; step < 24 && (await monthLabel.innerText()) !== 'December 2026'; step += 1) {
    await page.locator('.datepicker-dropdown .datepicker-days th.next').click();
  }
  await expect(monthLabel).toHaveText('December 2026');
  await page.locator('.datepicker-dropdown .datepicker-days td.day:not(.old):not(.new)')
    .filter({ hasText: /^31$/ })
    .click();
  await expect(dueDate).toHaveValue('31-12-2026');
  const datedUpdate = page.waitForResponse((response) => response.url().includes(`/fees/${feeId}`) && ['POST', 'PUT'].includes(response.request().method()));
  await page.locator('#feesForm input[type="submit"]').click({ noWaitAfter: true });
  expect((await datedUpdate).status()).toBe(302);

  const reloaded = await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  expect(reloaded?.status()).toBe(200);
  await expect(page.locator('#due_date')).toHaveValue('31-12-2026');
  await dueDate.fill('');
  await dueDate.evaluate((input) => window.jQuery(input).datepicker('hide'));
  const clearedUpdate = page.waitForResponse((response) => response.url().includes(`/fees/${feeId}`) && ['POST', 'PUT'].includes(response.request().method()));
  await page.locator('#feesForm input[type="submit"]').click({ noWaitAfter: true });
  expect((await clearedUpdate).status()).toBe(302);

  await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#due_date')).toHaveValue('');
  expect(failures).toEqual([]);
  await context.close();
});
