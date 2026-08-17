const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
const logPath = path.resolve(__dirname, '../../../storage/logs/laravel.log');

function logOffset() {
  return fs.existsSync(logPath) ? fs.statSync(logPath).size : 0;
}

async function resetUrlFromLocalMail(offset, email) {
  for (let attempt = 0; attempt < 30; attempt += 1) {
    const chunk = fs.existsSync(logPath) ? fs.readFileSync(logPath, 'utf8').slice(offset) : '';
    const urls = [...chunk.matchAll(/https?:\/\/[^\s"'<>]+\/password\/reset\/[^\s"'<>]+/g)]
      .map((match) => match[0].replaceAll('&amp;', '&'));
    const candidate = urls.reverse().find((url) => {
      const parsed = new URL(url);
      return parsed.searchParams.get('email') === email && parsed.searchParams.get('school_code') === 'BOWEN_QA';
    });
    if (candidate) return candidate;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error('The local log mail sink did not contain the expected tenant reset link.');
}

test('P3 Scenario 1: Head Finance creates an Accountant who completes tenant-aware password setup and logs in', async ({ browser }) => {
  const email = `p3.accountant.${Date.now()}@bowen-qa.test`;
  const password = 'P3LocalAccountant9!';
  const statePath = `/tmp/finance-onboarding-head-${Date.now()}.json`;
  const context = await browser.newContext({ baseURL, storageState: await authenticateLocalBowenQa('qa_head_finance@bowen-qa.test', statePath) });

  try {
    const page = await context.newPage();
    expect((await page.goto('/finance-staff', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
    const mailOffset = logOffset();
    await page.getByRole('button', { name: 'Add Accountant' }).click();
    const modal = page.locator('#addAccountantModal');
    await expect(modal).toBeVisible();
    await modal.locator('[name="first_name"]').fill('P3');
    await modal.locator('[name="last_name"]').fill('Accountant A');
    await modal.locator('[name="email"]').fill(email);
    const create = page.waitForResponse((response) => response.url().endsWith('/finance-staff') && response.request().method() === 'POST');
    await modal.getByRole('button', { name: 'Create Accountant' }).click();
    expect((await create).status()).toBe(200);

    const resetUrl = await resetUrlFromLocalMail(mailOffset, email);
    const parsed = new URL(resetUrl);
    expect(parsed.searchParams.get('school_code')).toBe('BOWEN_QA');
    expect(parsed.searchParams.get('email')).toBe(email);

    expect((await page.goto(resetUrl, { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);
    await expect(page.locator('#school_code')).toHaveValue('BOWEN_QA');
    await expect(page.locator('#email')).toHaveValue(email);
    await page.locator('#password').fill(password);
    await page.locator('#password-confirm').fill(password);
    await Promise.all([
      page.waitForURL(/\/login$/),
      page.locator('input[type="submit"][value="Reset Password"]').click(),
    ]);

    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('input[name="code"]').fill('BOWEN_QA');
    await Promise.all([
      page.waitForURL(/\/(dashboard|home)/),
      page.locator('#login_btn').click(),
    ]);
    await expect(page.locator('body')).toContainText('Bowen School');
  } finally {
    await context.close();
    fs.rmSync(statePath, { force: true });
  }
});
