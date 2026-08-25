const { test, expect } = require('@playwright/test');

test('Student admission submits exactly the Guardian email selected in Select2', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill('qa_admin@bowen-qa.test');
    await page.locator('input[name="password"]').fill('local-bowen-qa-only');
    await page.locator('input[name="code"]').fill('BOWEN_QA');
    await page.locator('form').filter({ has: page.locator('input[name="email"]') }).evaluate((form) => form.submit());
    await page.waitForURL(/dashboard/);

    await page.goto('/students/create', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#guardian_email_search')).toBeVisible();
    await expect(page.locator('input[name="guardian_email"]')).toHaveCount(1);

    await page.locator('#guardian_email_search + .select2 .select2-selection').click();
    const email = 'admission-guardian@bowen-qa.test';
    await page.locator('.select2-container--open .select2-search__field').fill(email);
    await page.keyboard.press('Enter');

    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);
    await expect(page.locator('#guardian_first_name')).toBeEditable();
    await expect(page.locator('#guardian_last_name')).toBeEditable();
    await expect(page.locator('#guardian_mobile')).toBeEditable();

    const submittedGuardianEmail = await page.locator('#create-form').evaluate((form) => new FormData(form).get('guardian_email'));
    expect(submittedGuardianEmail).toBe(email);
});
