const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8002';
const password = 'central-finance-qa-only';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('The local Central Finance login page did not contain a CSRF token.');
    return match[1];
}

async function centralLogin(email) {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const login = await api.get('/login', { maxRedirects: 0 });
        expect(login.status()).toBe(200);
        const response = await api.post('/login', {
            form: { _token: csrfToken(await login.text()), email, password },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        expect(new URL(response.headers().location, baseURL).pathname).not.toBe('/login');
        return await api.storageState();
    } finally {
        await api.dispose();
    }
}

async function centralPage(browser, email) {
    const context = await browser.newContext({ baseURL, storageState: await centralLogin(email) });
    const page = await context.newPage();
    await page.goto('/central-finance', { waitUntil: 'domcontentloaded' });
    return { context, page };
}

test('Zixuan and Timecity Accountants can select only their own Central Finance School', async ({ browser }) => {
    for (const [email, ownSchool, otherSchool] of [
        ['qa_zixuan_accountant@bowen-qa.test', 'Zixuan QA School', 'Timecity QA School'],
        ['qa_timecity_accountant@bowen-qa.test', 'Timecity QA School', 'Zixuan QA School'],
    ]) {
        const { context, page } = await centralPage(browser, email);
        try {
            const switcher = page.getByLabel('Switch School', { exact: true });
            await expect(switcher).toBeVisible();
            await expect(switcher.locator('option')).toHaveCount(2);
            await expect(switcher).toContainText(ownSchool);
            await expect(switcher).not.toContainText(otherSchool);
            await expect(page.locator('body')).not.toContainText(otherSchool);

            await switcher.selectOption({ label: ownSchool });
            await expect(page.getByText(`当前操作校区：${ownSchool}`)).toBeVisible();
            await expect(page.locator('body')).not.toContainText(otherSchool);
        } finally {
            await context.close();
        }
    }
});

test('ordinary Finance users do not see legacy Group Finance and Super Admin retains Finance Groups', async ({ browser }) => {
    const ordinary = await centralPage(browser, 'qa_central_regular_finance@bowen-qa.test');
    try {
        await expect(ordinary.page.getByText('Group Finance', { exact: true })).toHaveCount(0);
    } finally {
        await ordinary.context.close();
    }

    const superAdmin = await centralPage(browser, 'qa_central_super_admin@bowen-qa.test');
    try {
        await expect(superAdmin.page.getByRole('link', { name: 'Finance Groups', exact: true })).toBeVisible();
    } finally {
        await superAdmin.context.close();
    }
});

test('Central Finance sidebar expands and collapses both three-level groups on mobile', async ({ browser }) => {
    const { context, page } = await centralPage(browser, 'qa_central_head_finance@bowen-qa.test');
    try {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByRole('link', { name: /Central Finance/ }).click();

        const groups = page.locator('details.central-finance-sidebar-group');
        await expect(groups).toHaveCount(2);

        await groups.nth(0).locator('summary').click();
        await expect(groups.nth(0).getByRole('link', { name: '财务总览', exact: true })).toBeVisible();
        await expect(groups.nth(0).getByRole('link', { name: 'Standard Ledger', exact: true })).toBeVisible();
        await groups.nth(0).locator('summary').click();
        await expect(groups.nth(0).getByRole('link', { name: '财务总览', exact: true })).toBeHidden();

        await groups.nth(1).locator('summary').click();
        await expect(groups.nth(1).getByRole('link', { name: '银行账户', exact: true })).toBeVisible();
        await expect(groups.nth(1).getByRole('link', { name: 'HQ / School Funding', exact: true })).toBeVisible();
        await groups.nth(1).locator('summary').click();
        await expect(groups.nth(1).getByRole('link', { name: '银行账户', exact: true })).toBeHidden();

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally {
        await context.close();
    }
});
