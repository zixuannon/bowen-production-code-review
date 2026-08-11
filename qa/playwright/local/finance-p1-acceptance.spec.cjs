const { test, expect } = require('@playwright/test');

test.describe.configure({ mode: 'serial' });

async function openBankAccountEdit(page) {
  const listResponse = page.waitForResponse((response) => response.url().includes('/bank-accounts/list') && response.status() === 200);
  await page.goto('/bank-accounts', { waitUntil: 'domcontentloaded' });
  await listResponse;
  const bankRow = page.locator('tr', { hasText: 'Bowen QA P1 Bank' });
  await expect(bankRow).toBeVisible();
  await bankRow.locator('.edit-data').click();
  await expect(page.locator('#editModal')).toBeVisible();
}

test('BOWEN_QA P1 payment deletion requires a reason and deletes only the synthetic payment', async ({ page }) => {
  const response = await page.goto('/fees/pay/compulsory/1/4', { waitUntil: 'domcontentloaded' });
  expect(response).not.toBeNull();
  expect(response.status()).toBe(200);

  const deletePayment = page.locator('.remove-installment-fees-paid');
  await expect(deletePayment).toHaveCount(1);
  await deletePayment.click();
  await expect(page.locator('#swal-fee-delete-reason')).toBeVisible();

  await page.locator('.swal2-confirm').click();
  await expect(page.locator('.swal2-validation-message')).toContainText(/provide a reason/i);

  await page.locator('#swal-fee-delete-reason').fill('BOWEN_QA P1 payment deletion acceptance');
  const deleteRequest = page.waitForResponse((candidate) =>
    candidate.request().method() === 'DELETE' && candidate.url().includes('/fees/paid/remove-installment-fees/'));
  await page.locator('.swal2-confirm').click();
  expect((await deleteRequest).status()).toBe(200);
  await expect(page.locator('.remove-installment-fees-paid')).toHaveCount(0);
});

test('BOWEN_QA P1 opening-balance edits require a reason but unrelated edits do not', async ({ page }) => {
  await openBankAccountEdit(page);
  await expect(page.locator('#opening-balance-adjustment-reason-group')).toHaveClass(/d-none/);

  await page.locator('#edit_opening_balance').fill('5100');
  await expect(page.locator('#opening-balance-adjustment-reason-group')).not.toHaveClass(/d-none/);
  await expect(page.locator('#adjustment_reason')).toHaveAttribute('required', '');

  await page.locator('#edit-form input[type="submit"]').click();
  await expect(page.locator('#adjustment_reason')).toHaveClass(/is-invalid/);
  await expect(page.locator('#adjustment_reason_error')).toContainText(/provide a reason/i);

  await page.locator('#adjustment_reason').fill('BOWEN_QA P1 opening balance acceptance');
  const updateRequest = page.waitForResponse((candidate) =>
    candidate.request().method() === 'POST' && /\/bank-accounts\/\d+$/.test(new URL(candidate.url()).pathname));
  await page.locator('#edit-form input[type="submit"]').click();
  expect((await updateRequest).status()).toBe(200);
  await expect(page.locator('#editModal')).toBeHidden();

  await openBankAccountEdit(page);
  await page.locator('#edit_account_name').fill('Bowen QA P1 Bank — unrelated edit');
  await expect(page.locator('#opening-balance-adjustment-reason-group')).toHaveClass(/d-none/);
  const unrelatedUpdate = page.waitForResponse((candidate) =>
    candidate.request().method() === 'POST' && /\/bank-accounts\/\d+$/.test(new URL(candidate.url()).pathname));
  await page.locator('#edit-form input[type="submit"]').click();
  expect((await unrelatedUpdate).status()).toBe(200);
  await expect(page.locator('#editModal')).toBeHidden();
});
