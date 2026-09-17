const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8015';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('The login page did not contain a CSRF token.');
    return match[1];
}

async function login() {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const page = await api.get('/login', { maxRedirects: 0 });
        const response = await api.post('/login', {
            form: {
                _token: csrfToken(await page.text()),
                email: 'group_hq@group-qa.test',
                password: 'local-only',
            },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        return api.storageState();
    } finally {
        await api.dispose();
    }
}

test('Head Finance creates and allocates a shared Central Fund Account without School context', async ({ browser }) => {
    const context = await browser.newContext({ baseURL, storageState: await login() });
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('console', message => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });

    try {
        await page.goto('/central-finance/fund-accounts', { waitUntil: 'networkidle' });

        const createHeading = page.getByRole('heading', { name: 'Create Central Account', exact: true });
        await expect(createHeading).toBeVisible();
        const createForm = createHeading.locator('..').locator('form');
        await expect(createForm.getByLabel('Owner', { exact: true })).toHaveValue('Bowen Group / Central Finance');
        await expect(page.getByText('Select an authorized School before recording a Central Finance transaction.')).toHaveCount(0);

        const suffix = Date.now().toString().slice(-8);
        await createForm.locator('input[name="account_code"]').fill(`V2-${suffix}`);
        await createForm.locator('input[name="account_name"]').fill(`Shared Account ${suffix}`);
        await createForm.locator('select[name="account_type"]').selectOption('bank');
        await createForm.locator('input[name="currency"]').fill('MMK');
        await createForm.locator('input[name="opening_balance"]').fill('125000');
        await createForm.locator('input[name="opening_balance_date"]').fill('2026-09-17');
        await createForm.locator('input[name="opening_reason"]').fill('Central Fund Account V2 isolated browser acceptance');
        await createForm.getByRole('button', { name: 'Create Central Account', exact: true }).click();

        await expect(page).toHaveURL(/\/central-finance\/fund-accounts\/\d+\/manage$/);
        await expect(page.getByText('Owner: Bowen Group / Central Finance', { exact: false })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Allocated Schools', exact: true })).toBeVisible();

        const allocationForm = page.locator('form[action$="/school-allocations"]');
        await expect(allocationForm).toBeVisible();
        const schoolRows = allocationForm.locator('.form-row');
        await expect(schoolRows).toHaveCount(2);
        await schoolRows.nth(0).locator('input[type="checkbox"]').check();
        await schoolRows.nth(1).locator('input[type="checkbox"]').check();
        await allocationForm.locator('input[name="reason"]').fill('Allocate shared physical account to both QA Schools');
        await allocationForm.evaluate(form => form.submit());
        await page.waitForLoadState('networkidle');

        await expect(allocationForm.locator('input[type="checkbox"]:checked')).toHaveCount(2);
        await page.getByRole('link', { name: 'Detail', exact: true }).click();
        await expect(page.getByText('Physical Account Balance', { exact: true })).toBeVisible();
        await expect(page.getByText('Allocated Schools', { exact: true })).toBeVisible();
        await expect(page.getByText('Bowen Group / Central Finance', { exact: true })).toBeVisible();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload({ waitUntil: 'networkidle' });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        expect(consoleErrors).toEqual([]);
    } finally {
        await context.close();
    }
});
