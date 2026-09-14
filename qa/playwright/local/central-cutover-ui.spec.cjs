const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8014';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('The local login page did not contain a CSRF token.');
    return match[1];
}

async function login(email, password) {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const loginPage = await api.get('/login', { maxRedirects: 0 });
        expect(loginPage.status()).toBe(200);
        const response = await api.post('/login', {
            form: { _token: csrfToken(await loginPage.text()), email, password },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        return await api.storageState();
    } finally {
        await api.dispose();
    }
}

for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
    test(`Head Finance cutover control is read-only until confirmed at ${viewport.width}px`, async ({ browser }) => {
        const context = await browser.newContext({
            baseURL,
            viewport,
            storageState: await login('group_hq@group-qa.test', 'local-only'),
        });
        const page = await context.newPage();
        const consoleErrors = [];
        const pageErrors = [];
        page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
        page.on('pageerror', (error) => pageErrors.push(error.message));

        try {
            const response = await page.goto('/central-finance/cutover', { waitUntil: 'networkidle' });
            expect(response.status()).toBe(200);
            await expect(page.getByRole('heading', { name: 'School Centralization Cutover', exact: true })).toBeVisible();
            await expect(page.getByLabel('School', { exact: true })).toBeVisible();

            const school = page.getByLabel('School', { exact: true });
            const schoolIds = await school.locator('option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
            expect(schoolIds.length).toBeGreaterThan(0);
            await school.selectOption(schoolIds[0]);
            await page.getByRole('button', { name: 'View cutover controls', exact: true }).click();
            await page.waitForLoadState('networkidle');

            await expect(page.getByText('Asia/Yangon', { exact: true }).first()).toBeVisible();
            await expect(page.getByLabel('Effective date and time', { exact: true })).toBeVisible();
            await expect(page.getByLabel('Audit reason', { exact: true }).first()).toBeVisible();
            await expect(page.getByText('Type the canonical School Code to confirm', { exact: false }).first()).toBeVisible();
            await expect(page.getByRole('button', { name: 'Save audited cutoff', exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Submit controlled transition', exact: true })).toBeVisible();

            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
            expect(consoleErrors).toEqual([]);
            expect(pageErrors).toEqual([]);
        } finally {
            await context.close();
        }
    });
}

test('School-scoped Finance identity cannot open cutover control', async ({ browser }) => {
    const context = await browser.newContext({
        baseURL,
        storageState: await login('group_school_a@group-qa.test', 'local-only'),
    });
    try {
        const page = await context.newPage();
        const response = await page.goto('/central-finance/cutover', { waitUntil: 'domcontentloaded' });
        expect(response.status()).toBe(403);
    } finally {
        await context.close();
    }
});
