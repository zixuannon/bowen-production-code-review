const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

test('school staff create sends exactly one POST per submit', async ({ browser }) => {
  const statePath = path.join('/tmp', 'staff-single-submit-admin.json');
  const context = await browser.newContext({
    baseURL,
    storageState: await authenticateLocalBowenQa('qa_admin@bowen-qa.test', statePath),
  });
  const page = await context.newPage();
  let postCount = 0;

  try {
    expect((await page.goto('/staff', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);

    await page.route('**/staff', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue();
        return;
      }

      postCount += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ error: false, message: 'Synthetic staff accepted.' }),
      });
    });

    const form = page.locator('#create-form');
    await form.locator('#role_id').selectOption({ index: 1 });
    await form.locator('#first_name').fill('Single');
    await form.locator('#last_name').fill('Submit');
    await form.locator('#mobile').fill('0912345678');
    await form.locator('#email').fill('single-submit@bowen-qa.test');
    await form.locator('input[name="dob"]').fill('01-01-2000');
    await form.locator('#salary').fill('1');
    await form.locator('input[name="status"][value="1"]').check();
    await form.locator('[required]').evaluateAll((elements) => {
      for (const element of elements) element.removeAttribute('required');
    });
    await page.locator('#create-btn').click();

    await expect.poll(() => postCount).toBe(1);
    await page.waitForTimeout(700);
    expect(postCount).toBe(1);
  } finally {
    await context.close();
  }
});
