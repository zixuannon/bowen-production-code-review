const { test, expect } = require('@playwright/test');

test.skip(!['qa', 'official'].includes(process.env.QA_STAFF_MODE), 'Requires the matching disposable QA Staff fixture with array mail.');

test('New Principal and School Accountant remain visible and duplicate submission creates no second Staff', async ({ page }) => {
  const failures = [];
  page.on('pageerror', error => failures.push(error.message));
  page.on('console', message => { if (message.type() === 'error') failures.push(message.text()); });
  page.on('response', response => {
    if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${new URL(response.url()).pathname}`);
  });
  const fillStaff = async (role, email) => {
    const form = page.locator('#create-form');
    await form.getByLabel(role, { exact: true }).check();
    await form.locator('#first_name').fill('Classification');
    await form.locator('#last_name').fill(role === 'Principal' ? 'Principal' : 'Accountant');
    await form.locator('#mobile').fill('0900000002');
    await form.locator('#email').fill(email);
    const dob = form.locator('input[name="dob"]');
    await dob.click();
    await dob.pressSequentially('01-01-2000');
    await dob.press('Tab');
    await expect(dob).not.toHaveValue('');
    await form.locator('#salary').fill('1');
    await form.locator('input[name="status"][value="1"]').check();
  };
  const submit = async () => {
    const pending = page.waitForResponse(response => new URL(response.url()).pathname === '/staff' && response.request().method() === 'POST');
    await page.locator('#create-btn').click();
    const response = await pending;
    expect(response.status()).toBe(200);
    return response.json();
  };
  for (const [role, email] of [
    ['Principal', 'classification-principal@bowen-qa.test'],
    ['School Accountant', 'classification-accountant@bowen-qa.test'],
  ]) {
    expect((await page.goto('/staff'))?.status()).toBe(200);
    await fillStaff(role, email);
    const result = await submit();
    expect(result.error).toBe(false);
    expect(result.warning ?? false).toBe(false);
    expect(result.message).not.toMatch(/not sent|error|failed/i);
    await expect(page.locator('#table_list')).toContainText(email);
    // Reload forces a real Staff listing query through the School workflow filter.
    await page.reload();
    await expect(page.locator('#table_list')).toContainText(email);
  }
  await fillStaff('Principal', 'classification-principal@bowen-qa.test');
  const duplicate = await submit();
  expect(duplicate.error).toBe(true);
  expect(duplicate.message).toMatch(/email.*taken/i);
  expect(failures).toEqual([]);
});
