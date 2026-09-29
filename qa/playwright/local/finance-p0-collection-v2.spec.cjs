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

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    test(`P0 Unidentified Deposits screen renders read-only at ${viewport.width}px`, async ({ browser }) => {
        const context = await browser.newContext({ baseURL, storageState: await headFinanceState(), viewport });
        const page = await context.newPage();
        const failures = [];
        page.on('console', (message) => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
        page.on('pageerror', (error) => failures.push(`page: ${error.message}`));
        page.on('response', (response) => { if ([404, 500].includes(response.status())) failures.push(`${response.status()} ${response.url()}`); });

        try {
            const response = await page.goto('/central-finance/unidentified-deposits', { waitUntil: 'networkidle' });
            expect(response?.status()).toBe(200);
            await expect(page.getByRole('heading', { name: 'Unidentified Deposits', exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Record Unidentified Deposit', exact: true })).toBeVisible();
            await expect(page.locator('input[name="idempotency_reference"]')).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)).toBeFalsy();
            expect(failures).toEqual([]);
        } finally {
            await context.close();
        }
    });
}
