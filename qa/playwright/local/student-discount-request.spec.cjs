const { test, expect, request } = require('@playwright/test');
const { execFileSync } = require('node:child_process');

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local login page did not provide a CSRF token.');
  return match[1];
}

async function login(baseURL, email, password, code = null) {
  const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const page = await api.get('/login', { maxRedirects: 0 });
    expect(page.status()).toBe(200);
    const form = { _token: csrfToken(await page.text()), email, password };
    if (code) form.code = code;
    const result = await api.post('/login', { form, maxRedirects: 0 });
    expect([302, 303]).toContain(result.status());
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

test('Front Desk submits an exact student Discount request and Head Finance can approve without editing it', async ({ browser, baseURL }) => {
  const frontContext = await browser.newContext({
    baseURL,
    storageState: await login(baseURL, 'qa_front_desk@bowen-qa.test', 'local-bowen-qa-only', 'BOWEN_QA'),
    viewport: { width: 1440, height: 900 },
  });
  const front = await frontContext.newPage();
  const failures = [];
  let toggle;
  let feeId;
  for (const page of [front]) {
    page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
    page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
    page.on('response', (response) => { if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${response.url()}`); });
  }

  try {
    await test.step('Front Desk cannot manage general Promotion definitions', async () => {
      const denied = await front.goto('/central-finance/promotions', { waitUntil: 'domcontentloaded' });
      expect(denied?.status()).toBe(403);
    });

    await test.step('Front Desk creates the immutable Discount request', async () => {
    const setup = await front.goto('/students/1/fee-assignment', { waitUntil: 'domcontentloaded' });
    expect(setup?.status()).toBe(200);
    await expect(front.getByRole('heading', { name: 'Student Fee Setup', exact: true })).toBeVisible();

    toggle = front.locator('.student-discount-toggle').first();
    await expect(toggle).toBeVisible();
    await toggle.check({ force: true });
    feeId = await toggle.getAttribute('data-fee-id');
    await front.locator(`input[name="student_discounts[${feeId}][discount_value]"]`).fill('10');
    await front.locator(`input[name="student_discounts[${feeId}][reason]"]`).fill('BOWEN_QA browser request only');
    await front.getByRole('button', { name: 'Preview Fee Assignment / Submit Discount Request', exact: true }).click();
    await expect(front.locator('.student-discount-panel small').filter({ hasText: 'Head Finance status' })).toContainText('pending');
    await expect(front.getByText('This draft is locked while Head Finance decides', { exact: false })).toBeVisible();
    await expect(front.locator(`input[name="student_discounts[${feeId}][discount_value]"]`)).toBeDisabled();
    });

    await test.step('Head Finance approves the exact request', async () => {
    const headContext = await browser.newContext({
      baseURL,
      storageState: await login(baseURL, 'qa_discount_hq@bowen-qa.test', 'local-only'),
      viewport: { width: 1440, height: 900 },
    });
    const head = await headContext.newPage();
    head.on('console', (message) => { if (message.type() === 'error') failures.push(`head console: ${message.text()}`); });
    head.on('pageerror', (error) => failures.push(`head page: ${error.message}`));
    head.on('response', (response) => { if ([404, 500].includes(response.status())) failures.push(`head ${response.status()} ${response.url()}`); });
    try {
      const queue = await head.goto('/central-finance/student-discount-requests?include_qa_test=1', { waitUntil: 'domcontentloaded' });
      expect(queue?.status()).toBe(200);
      await expect(head.getByRole('heading', { name: 'Student Discount Requests', exact: true })).toBeVisible();
      await expect(head.getByText('BOWEN_QA browser request only', { exact: true })).toBeVisible();
      await head.getByRole('button', { name: 'Approve exact request', exact: true }).click();
      await expect(head.getByText('Student-specific Discount approved.', { exact: true })).toBeVisible();
    } finally {
      await headContext.close();
    }
    });

    await test.step('Front Desk confirms the approved fee setup and declares one multi-receivable collection', async () => {
    await front.reload({ waitUntil: 'domcontentloaded' });
    await expect(front.locator('.student-discount-panel small').filter({ hasText: 'Head Finance status' })).toContainText(/approved/i);
    await expect(front.getByText('This draft is locked while Head Finance decides', { exact: false })).toBeVisible();
    await expect(toggle).toBeDisabled();
    await expect(front.getByRole('cell', { name: '900.00 MMK', exact: true })).toBeVisible();
    await expect(front.locator(`input[name="student_discounts[${feeId}][discount_value]"]`)).toBeDisabled();
    const confirm = front.getByRole('button', { name: 'Confirm Fee Assignment', exact: true });
    await expect(confirm).toBeEnabled();
    await confirm.click();
    await expect(front.getByText('Fee assignment confirmed. Central Receivable sync has been requested.', { exact: true })).toBeVisible();
    await expect(front.getByText('awaiting Central Finance synchronization', { exact: false })).toHaveCount(0);
    // The disposable BOWEN_QA School is not the permanent Run-managed
    // MMBOWEN01 School. Classify only its two known synthetic Receivables so
    // the rest of the browser flow exercises the ordinary QA-only view.
    execFileSync('php', ['artisan', 'local:student-discount-request-qa', 'classify-receivables'], { cwd: process.cwd(), stdio: 'pipe' });
    const collectPayment = front.getByRole('link', { name: 'Collect Payment Now', exact: true });
    await expect(collectPayment).toBeVisible();
    await collectPayment.click();
    await expect(front.getByRole('heading', { name: 'Student Finance', exact: true })).toBeVisible();
    const receivableSelections = front.locator('input[name="receivable_ids[]"]');
    await expect(receivableSelections).toHaveCount(2);
    for (let index = 0; index < await receivableSelections.count(); index += 1) {
      await receivableSelections.nth(index).check();
    }
    await front.getByRole('button', { name: 'Collect selected', exact: true }).click();
    await expect(front.getByRole('heading', { name: 'Submit pending collection', exact: true })).toBeVisible();
    await front.locator('#payment-method').selectOption('Bank Transfer');
    const bankAccount = front.locator('select[name="intended_fund_account_id"]');
    await expect(bankAccount.getByRole('option', { name: /BOWEN QA Discount E2E Bank/ })).toHaveCount(1);
    await bankAccount.selectOption({ index: 1 });
    await front.locator('input[name="payment_reference"]').fill('BOWEN-QA-DISCOUNT-E2E');
    await front.getByRole('button', { name: 'Submit pending collection', exact: true }).click();
    await expect(front.getByText(/Collection Receipt created:/)).toBeVisible();
    });

    await test.step('Head Finance confirms the declared Bank Transfer exactly once', async () => {
    const confirmationContext = await browser.newContext({
      baseURL,
      storageState: await login(baseURL, 'qa_discount_hq@bowen-qa.test', 'local-only'),
      viewport: { width: 1440, height: 900 },
    });
    const confirmation = await confirmationContext.newPage();
    confirmation.on('console', (message) => { if (message.type() === 'error') failures.push(`confirm console: ${message.text()}`); });
    confirmation.on('pageerror', (error) => failures.push(`confirm page: ${error.message}`));
    confirmation.on('response', (response) => { if ([404, 500].includes(response.status())) failures.push(`confirm ${response.status()} ${response.url()}`); });
    try {
      await confirmation.goto('/central-finance', { waitUntil: 'domcontentloaded' });
      const schoolSwitcher = confirmation.locator('select[name="school_id"]');
      await expect(schoolSwitcher).toBeVisible();
      const localQaSchool = schoolSwitcher.getByRole('option', { name: /Bowen School — Local QA/ });
      await expect(localQaSchool).toHaveCount(1);
      const localQaSchoolId = await localQaSchool.getAttribute('value');
      await Promise.all([
        confirmation.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }),
        confirmation.evaluate((schoolId) => {
          const form = document.querySelector('form[action$="/central-finance/school"]');
          const select = form?.querySelector('select[name="school_id"]');
          if (!(form instanceof HTMLFormElement) || !(select instanceof HTMLSelectElement)) throw new Error('Central Finance School selector is unavailable.');
          select.value = schoolId;
          form.submit();
        }, localQaSchoolId),
      ]);
      const pending = await confirmation.goto('/central-finance/pending-collections?include_qa_test=1', { waitUntil: 'domcontentloaded' });
      expect(pending?.status()).toBe(200);
      const pendingRow = confirmation.locator('tr').filter({ hasText: 'BOWEN-QA-DISCOUNT-E2E' });
      await expect(pendingRow).toHaveCount(1);
      const review = pendingRow.locator('details');
      await expect(review).toHaveCount(1);
      await review.locator('summary').click();
      const confirmationForm = review.locator('form[action*="/confirm"]');
      await expect(confirmationForm).toHaveCount(1);
      await expect(confirmationForm.getByRole('button', { name: 'Confirm', exact: true })).toBeEnabled();
      await confirmationForm.locator('input[name="reason"]').fill('BOWEN_QA E2E confirms the exact approved student Discount.');
      const confirmationResponse = confirmation.waitForResponse((response) => response.request().method() === 'POST'
        && response.url().includes('/central-finance/pending-collections/') && response.url().endsWith('/confirm'));
      await confirmationForm.getByRole('button', { name: 'Confirm', exact: true }).click();
      expect((await confirmationResponse).status()).toBe(302);
      await expect(pendingRow).toHaveCount(0);
    } finally {
      await confirmationContext.close();
    }
    });
    // The deliberate 403 probe is reported by Chromium as a resource error;
    // it proves the authorization boundary and is not a browser defect.
    expect(failures.filter((failure) => failure !== 'console: Failed to load resource: the server responded with a status of 403 (Forbidden)')).toEqual([]);
  } finally {
    await frontContext.close();
  }
});
