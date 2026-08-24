const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8002';
const password = 'central-finance-qa-only';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('The local Central Finance login page did not contain a CSRF token.');
    return match[1];
}

async function superAdminPage(browser) {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const login = await api.get('/login', { maxRedirects: 0 });
        expect(login.status()).toBe(200);
        const response = await api.post('/login', {
            form: { _token: csrfToken(await login.text()), email: 'qa_central_super_admin@bowen-qa.test', password },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        const context = await browser.newContext({ baseURL, storageState: await api.storageState() });
        return { context, page: await context.newPage() };
    } finally {
        await api.dispose();
    }
}

test('Finance Groups uses a compact home and a separated manage workspace', async ({ browser }) => {
    const { context, page } = await superAdminPage(browser);
    try {
        await page.goto('/finance-groups', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('link', { name: /Create Finance Group/ })).toBeVisible();
        await expect(page.locator('form')).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Manage', exact: true }).first()).toBeVisible();

        await page.getByRole('link', { name: 'Manage', exact: true }).first().click();
        await expect(page.getByRole('heading', { name: 'Basic settings', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Member Schools', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Central Finance Staff', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Advanced / Legacy', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Authorize All Group Schools', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Authorize', exact: true })).toBeVisible();
        await expect(page.locator('th').filter({ hasText: /Role|角色/ })).toBeVisible();
        const legacy = page.getByText('Legacy / Transition tenant identity mapping', { exact: true });
        await expect(legacy).toBeVisible();
        await expect(legacy.locator('xpath=ancestor::details')).not.toHaveAttribute('open', '');

        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.locator('body')).toContainText('Central Finance Staff');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        await page.goto('/finance-groups', { waitUntil: 'domcontentloaded' });
        await page.getByRole('link', { name: /Create Finance Group/ }).click();
        await expect(page.getByRole('heading', { name: 'Create Finance Group', exact: true })).toBeVisible();
        await expect(page.locator('form[action$="/finance-groups"]')).toHaveCount(1);
    } finally {
        await context.close();
    }
});
