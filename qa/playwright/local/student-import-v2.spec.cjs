const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
let workbookScratch = null;
const operatorEmail = process.env.STUDENT_IMPORT_V2_E2E_EMAIL || 'qa_admin@bowen-qa.test';
const operatorPassword = process.env.STUDENT_IMPORT_V2_E2E_PASSWORD || 'local-bowen-qa-only';
const operatorSchoolCode = process.env.STUDENT_IMPORT_V2_E2E_SCHOOL_CODE || 'BOWEN_QA';

test.afterEach(() => {
  if (workbookScratch) fs.rmSync(workbookScratch, { recursive: true, force: true });
  workbookScratch = null;
});

test('Student Import V2 confirms ten identities without creating a Fee Setup or Finance document', async ({ page }) => {
  workbookScratch = fs.mkdtempSync(path.join(os.tmpdir(), 'student-import-v2-'));
  const scratch = workbookScratch;
  const failures = [];
  page.on('console', (message) => {
    if (message.type() === 'error') failures.push(`console: ${message.text()}`);
  });
  page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
  page.on('response', (response) => {
    if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${response.url()}`);
  });

  await page.context().clearCookies();
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(operatorEmail);
  await page.locator('input[name="password"]').fill(operatorPassword);
  await page.locator('input[name="code"]').fill(operatorSchoolCode);
  await page.locator('form').filter({ has: page.locator('input[name="email"]') }).evaluate((form) => form.submit());
  await page.waitForURL(/dashboard/);

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/students/import-v2', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Student Import V2', exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Student Import V2', exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.setViewportSize({ width: 1440, height: 900 });
  const templateDownload = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download V2 template', exact: true }).click();
  const template = await templateDownload;
  expect(template.suggestedFilename()).toBe('Student_Import_V2.xlsx');
  const workbook = path.join(scratch, 'students.xlsx');
  await template.saveAs(workbook);
  execFileSync('php', [path.join(__dirname, 'fixtures', 'fill-student-import-v2-workbook.php'), workbook], { stdio: 'pipe' });
  await page.locator('#student-import-v2-file').setInputFiles(workbook);
  await page.getByRole('button', { name: /Preview and validate/i }).click();
  await expect(page.locator('.ui-import-summary')).toContainText('NEW');
  await expect(page.locator('.ui-import-summary')).toContainText('10');
  await expect(page.getByText('Possible existing Guardian match', { exact: false }).first()).toBeVisible();

  const confirm = page.getByRole('button', { name: /Confirm Import/i });
  await expect(confirm).toBeEnabled();
  // A network-level failure must not leave the UI permanently in its loading
  // state. The warning deliberately tells the operator to reconcile/reload;
  // the later real request below proves the same cached preview remains safe.
  await page.route('**/students/import-v2/confirm', (route) => route.abort('failed'));
  await confirm.click();
  await expect(page.getByText(/response was not received.*Reload and preview/i)).toBeVisible();
  await expect(confirm).toBeEnabled();
  await page.unroute('**/students/import-v2/confirm');

  const confirmRequest = page.waitForRequest((request) => request.url().endsWith('/students/import-v2/confirm') && request.method() === 'POST');
  const confirmResponse = page.waitForResponse((response) => response.url().endsWith('/students/import-v2/confirm') && response.request().method() === 'POST');
  await confirm.click();
  const request = await confirmRequest;
  const response = await confirmResponse;
  const responseBody = await response.text();
  expect(response.status(), responseBody).toBe(200);
  const token = JSON.parse(request.postData() || '{}').preview_token;
  expect(token).toBeTruthy();
  await expect(page.getByText(/Import completed/i)).toBeVisible();

  // A response may be retried by a browser or operator. The consumed preview
  // token must be rejected rather than creating another identity graph.
  const retry = await page.evaluate(async (preview_token) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch('/students/import-v2/confirm', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' },
      body: JSON.stringify({ preview_token }),
    });
    return { status: response.status, body: await response.json().catch(() => ({})) };
  }, token);
  expect(retry.status).toBe(422);
  expect(JSON.stringify(retry.body)).toMatch(/preview has expired|does not belong/i);

  await page.goto('/students', { waitUntil: 'domcontentloaded' });
  await expect(page.getByText('QA Student 001', { exact: true })).toBeVisible();
  await expect(page.getByText('QA Student 010', { exact: true })).toBeVisible();
  // The expected consumed-token 422 is intentionally exercised above. It is
  // not a client failure; any other console/page/404/500 error remains a
  // browser acceptance failure.
  expect(failures.filter((failure) => (
    !failure.includes('422 (Unprocessable Content)')
    && !failure.includes('net::ERR_FAILED')
  ))).toEqual([]);
});
