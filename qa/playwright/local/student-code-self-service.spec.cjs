const { test, expect } = require('@playwright/test');

test('Student Code is server-generated and read-only on desktop and mobile', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(`${message.location().url || 'inline'}: ${message.text()}`);
    });

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill('qa_admin@bowen-qa.test');
    await page.locator('input[name="password"]').fill('local-bowen-qa-only');
    await page.locator('input[name="code"]').fill('BOWEN_QA');
    await page.locator('form').filter({ has: page.locator('input[name="email"]') }).evaluate((form) => form.submit());
    await page.waitForURL(/dashboard/);
    // The synthetic QA login page has its own optional brand image. Scope
    // console acceptance to the Student pages owned by this change.
    consoleErrors.length = 0;

    for (const width of [1280, 390]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/students/create', { waitUntil: 'domcontentloaded' });
        const code = page.locator('#student_code');
        await expect(code).toBeVisible();
        await expect(code).not.toBeEditable();
        await expect(code).toHaveValue('Generated when the Student is saved');
        await expect(page.locator('input[name="student_code"]')).toHaveCount(0);
        await expect(page.getByText(/000001, 000002/)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    }

    expect(consoleErrors).toEqual([]);
});
