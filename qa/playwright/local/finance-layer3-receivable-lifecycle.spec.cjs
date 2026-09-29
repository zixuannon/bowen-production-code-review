const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('Local login page did not contain a CSRF token.');
    return match[1];
}

async function headFinanceState() {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const login = await api.get('/login', { maxRedirects: 0 });
        expect(login.status()).toBe(200);
        const response = await api.post('/login', {
            form: { _token: csrfToken(await login.text()), email: 'group_hq@group-qa.test', password: 'local-only' },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        return await api.storageState();
    } finally {
        await api.dispose();
    }
}

async function selectFirstSchool(page) {
    await page.goto('/central-finance', { waitUntil: 'networkidle' });
    const switcher = page.getByLabel('Switch School', { exact: true });
    const values = await switcher.locator('option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
    expect(values.length).toBeGreaterThan(0);
    await switcher.selectOption(values[0]);
    await page.waitForLoadState('networkidle');
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test(`Layer 3 promotion and receivable lifecycle screens render at ${viewport.width}px`, async ({ browser }) => {
        const context = await browser.newContext({ baseURL, storageState: await headFinanceState(), viewport });
        const page = await context.newPage();
        const consoleErrors = [];
        const pageErrors = [];
        const badResponses = [];
        page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
        page.on('pageerror', (error) => pageErrors.push(error.message));
        page.on('response', (response) => { if ([404, 500].includes(response.status())) badResponses.push(`${response.status()} ${response.url()}`); });

        try {
            const promotionResponse = await page.goto('/central-finance/promotions', { waitUntil: 'networkidle' });
            expect(promotionResponse?.status()).toBe(200);
            await expect(page.getByRole('heading', { name: 'Promotion definitions', exact: true })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Create promotion', exact: true })).toBeVisible();
            await expect(page.locator('select[name="group_id"]')).toBeVisible();
            await expect(page.locator('input[name="school_ids[]"]')).not.toHaveCount(0);
            await expect(page.getByText('QA/Test and Official Schools cannot be mixed in one Promotion definition.')).toBeVisible();

            await selectFirstSchool(page);
            const receivableResponse = await page.goto('/central-finance/receivables', { waitUntil: 'networkidle' });
            expect(receivableResponse?.status()).toBe(200);
            const statuses = await page.locator('select[name="receivable_status"] option').evaluateAll((options) => options.map((option) => option.value));
            expect(statuses).toContain('voided');
            expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)).toBeFalsy();
            expect(consoleErrors).toEqual([]);
            expect(pageErrors).toEqual([]);
            expect(badResponses).toEqual([]);
        } finally {
            await context.close();
        }
    });
}
