const { test, expect } = require('@playwright/test');

test('Student admission submits exactly the email of an existing Guardian selected in Select2', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill('qa_admin@bowen-qa.test');
    await page.locator('input[name="password"]').fill('local-bowen-qa-only');
    await page.locator('input[name="code"]').fill('BOWEN_QA');
    await page.locator('form').filter({ has: page.locator('input[name="email"]') }).evaluate((form) => form.submit());
    await page.waitForURL(/dashboard/);

    const suffix = Date.now();
    const email = `admission-guardian-${suffix}@bowen-qa.test`;

    await page.goto('/guardian/create', { waitUntil: 'domcontentloaded' });
    await page.locator('#guardian_first_name').fill('Admission');
    await page.locator('#guardian_last_name').fill(`Guardian ${suffix}`);
    await page.locator('#guardian_email').fill(email);
    await page.locator('#guardian_mobile').fill(`091${String(suffix).slice(-7)}`);
    await page.locator('#guardian_male').check();
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByText('Data Created Successfully')).toBeVisible();

    await page.goto('/students/create', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#guardian_email_search')).toBeVisible();
    await expect(page.locator('input[name="guardian_email"]')).toHaveCount(1);

    await page.locator('#guardian_email_search + .select2 .select2-selection').click();
    await page.locator('.select2-container--open .select2-search__field').fill(email);
    const matchingGuardian = page.locator('.select2-results__option').filter({ hasText: email }).last();
    await expect(matchingGuardian).toBeVisible();
    await matchingGuardian.click();

    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);
    await expect(page.locator('#guardian_first_name')).toHaveValue('Admission');
    await expect(page.locator('#guardian_last_name')).toHaveValue(`Guardian ${suffix}`);
    await expect(page.locator('#guardian_first_name')).not.toBeEditable();
    await expect(page.locator('#guardian_last_name')).not.toBeEditable();

    const submittedGuardianEmail = await page.locator('#create-form').evaluate((form) => new FormData(form).get('guardian_email'));
    expect(submittedGuardianEmail).toBe(email);

    await page.locator('select[name="class_section_id"]').selectOption({ index: 1 });
    await page.locator('input[name="first_name"]').fill('Admission');
    await page.locator('input[name="last_name"]').fill(`Student ${suffix}`);
    await page.locator('input[name="dob"]').fill('01-01-2015');
    await page.locator('textarea[name="current_address"]').fill('BOWEN QA local address');
    await page.locator('textarea[name="permanent_address"]').fill('BOWEN QA local address');
    await page.locator('#create-btn').click();
    await expect(page.getByText('Data Created Successfully')).toBeVisible();
});
