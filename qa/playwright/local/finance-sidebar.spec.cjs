const { test, expect } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

async function stateFor(email) {
  const statePath = `/tmp/finance-sidebar-${email.replace(/[^a-z]/g, '_')}.json`;
  await authenticateLocalBowenQa(email, statePath);
  return statePath;
}

async function openFinanceNavigation(browser, email) {
  const context = await browser.newContext({ baseURL, storageState: await stateFor(email) });
  const page = await context.newPage();
  expect((await page.goto('/dashboard', { waitUntil: 'domcontentloaded' }))?.status()).toBe(200);

  const trigger = page.locator('a[href="#finance-menu"]');
  await expect(trigger).toHaveCount(1);
  await trigger.click();
  await expect(page.locator('#finance-menu')).toHaveClass(/show/);

  return { context, page };
}

test('Finance sidebar is a single grouped navigation tree for School Admin and Head Finance', async ({ browser }) => {
  for (const email of ['qa_admin@bowen-qa.test', 'qa_head_finance@bowen-qa.test']) {
    const { context, page } = await openFinanceNavigation(browser, email);
    try {
      await expect(page.locator('a[href="#expense-menu"]')).toHaveCount(0);
      for (const path of ['/bank-accounts', '/bank-transfers', '/fund-handovers', '/finance-staff']) {
        await expect(page.locator(`#finance-menu a[href*="${path}"]`)).toHaveCount(1);
      }
      for (const label of ['Overview', 'Income and Expenses', 'Fund Management', 'Reports and Queries', 'Settings']) {
        await expect(page.locator('#finance-menu .menu-group-text', { hasText: label })).toHaveCount(1);
      }
    } finally {
      await context.close();
    }
  }
});

test('Cashier retains operational navigation but Finance Staff remains hidden', async ({ browser }) => {
  const { context, page } = await openFinanceNavigation(browser, 'qa_cashier_a@bowen-qa.test');
  try {
    await expect(page.locator('#finance-menu a[href*="/bank-transfers"]')).toHaveCount(1);
    await expect(page.locator('#finance-menu a[href*="/fund-handovers"]')).toHaveCount(1);
    await expect(page.locator('#finance-menu a[href*="/finance-staff"]')).toHaveCount(0);
  } finally {
    await context.close();
  }
});
