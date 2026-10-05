const { test, expect } = require('@playwright/test');

test.skip(process.env.STAFF_INVITATION_E2E !== '1', 'Requires a prepared disposable BOWEN_QA fixture and array mail transport.');

test('Principal creation reaches invitation delivery once despite a stale School replica', async ({ page }) => {
  const failures = [];
  let posts = 0;
  page.on('pageerror', error => failures.push(error.message));
  page.on('console', message => { if (message.type() === 'error') failures.push(message.text()); });
  page.on('response', response => {
    if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${new URL(response.url()).pathname}`);
  });
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/staff' && request.method() === 'POST') posts += 1;
  });
  expect((await page.goto('/staff'))?.status()).toBe(200);
  const form = page.locator('#create-form');
  const fillStaff = async () => {
    await form.getByLabel('Principal', { exact: true }).check();
    await form.locator('#first_name').fill('Invitation');
    await form.locator('#last_name').fill('Principal');
    await form.locator('#mobile').fill('0900000001');
    await form.locator('#email').fill('invitation-principal@bowen-qa.test');
    const dob = form.locator('input[name="dob"]');
    await dob.click();
    await dob.pressSequentially('01-01-2000');
    await dob.press('Tab');
    await expect(dob).not.toHaveValue('');
    await form.locator('#salary').fill('1');
    await form.locator('input[name="status"][value="1"]').check();
  };
  await fillStaff();
  const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/staff' && response.request().method() === 'POST');
  await page.locator('#create-btn').click();
  const response = await responsePromise;
  expect(response.status()).toBe(200);
  const result = await response.json();
  expect(result.error).toBe(false);
  expect(result.message).not.toMatch(/not sent|error|failed/i);
  expect(result.warning ?? false).toBe(false);
  expect(posts).toBe(1);
  await expect(page.locator('#table_list')).toContainText('invitation-principal@bowen-qa.test');
  // An operator retry must be a normal validation rejection, not another
  // Staff/profile or another invitation. The fixture verifier checks counts.
  await page.reload();
  await fillStaff();
  const retryPromise = page.waitForResponse(response => new URL(response.url()).pathname === '/staff' && response.request().method() === 'POST');
  await page.locator('#create-btn').click();
  const retry = await retryPromise;
  expect(retry.status()).toBe(200);
  const rejected = await retry.json();
  expect(rejected.error).toBe(true);
  expect(rejected.message).toMatch(/email.*taken/i);
  expect(posts).toBe(2);
  expect(failures).toEqual([]);
});
