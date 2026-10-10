const { test, expect, request } = require('@playwright/test');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8002';
const password = 'central-finance-qa-only';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('The local Central Finance login page did not contain a CSRF token.');
    return match[1];
}

async function centralLogin(email, loginPassword = password) {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const login = await api.get('/login', { maxRedirects: 0 });
        expect(login.status()).toBe(200);
        const response = await api.post('/login', {
            form: { _token: csrfToken(await login.text()), email, password: loginPassword },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        expect(new URL(response.headers().location, baseURL).pathname).not.toBe('/login');
        return await api.storageState();
    } finally {
        await api.dispose();
    }
}

async function centralPage(browser, email, loginPassword = password) {
    const context = await browser.newContext({ baseURL, storageState: await centralLogin(email, loginPassword) });
    const page = await context.newPage();
    await page.goto('/central-finance', { waitUntil: 'domcontentloaded' });
    return { context, page };
}

async function applicationPage(browser, email, loginPassword = password) {
    const context = await browser.newContext({ baseURL, storageState: await centralLogin(email, loginPassword) });
    const page = await context.newPage();
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
    return { context, page };
}

async function configurationPage(browser, email, loginPassword = password) {
    const context = await browser.newContext({ baseURL, storageState: await centralLogin(email, loginPassword) });
    const page = await context.newPage();
    await page.goto('/finance-groups', { waitUntil: 'domcontentloaded' });
    return { context, page };
}

test('School-scoped Group accountants do not receive a QA School switcher by default', async ({ browser }) => {
    for (const email of ['group_school_a@group-qa.test', 'group_school_b@group-qa.test']) {
        const { context, page } = await centralPage(browser, email, 'local-only');
        try {
            const switcher = page.getByLabel('Switch School', { exact: true });
            await expect(switcher).toBeVisible();
            await expect(switcher.locator('option')).toHaveCount(1);
            await expect(switcher).not.toContainText('Zixuan QA School');
            await expect(switcher).not.toContainText('Timecity QA School');
        } finally {
            await context.close();
        }
    }
});

test('School-scoped Group accountants cannot open Finance Group configuration', async ({ browser }) => {
    const ordinary = await applicationPage(browser, 'group_school_a@group-qa.test', 'local-only');
    try {
        await expect(ordinary.page.getByText('Group Finance', { exact: true })).toHaveCount(0);
        const response = await ordinary.page.goto('/finance-groups', { waitUntil: 'domcontentloaded' });
        expect(response?.status()).toBe(403);
    } finally {
        await ordinary.context.close();
    }
});

test('Central Finance sidebar expands and collapses current groups on mobile', async ({ browser }) => {
    const { context, page } = await centralPage(browser, 'group_hq@group-qa.test', 'local-only');
    try {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/central-finance/ledger', { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Open navigation menu' }).click();

        const groups = page.locator('details.central-finance-sidebar-group');
        await expect(groups).toHaveCount(5);

        const reports = groups.nth(3);
        await expect(reports).toHaveAttribute('open', '');
        await expect(reports.getByRole('link', { name: 'Standard Ledger', exact: true })).toBeVisible();
        await reports.locator('summary').press('Enter');
        await expect(reports.getByRole('link', { name: 'Standard Ledger', exact: true })).toBeHidden();
        await reports.locator('summary').press('Enter');
        await expect(reports.getByRole('link', { name: 'Standard Ledger', exact: true })).toBeVisible();

        const collections = groups.nth(0);
        await expect(collections).not.toHaveAttribute('open', '');
        await collections.locator('summary').press('Enter');
        await expect(collections.getByRole('link', { name: 'Student Collection', exact: true })).toBeVisible();
        await collections.locator('summary').press('Enter');
        await expect(collections.getByRole('link', { name: 'Student Collection', exact: true })).toBeHidden();

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally {
        await context.close();
    }
});

test('Central Finance P0 read screens expose ledger, history, account reporting, and configuration without a write', async ({ browser }) => {
    const { context, page } = await centralPage(browser, 'group_hq@group-qa.test', 'local-only');
    try {
        const switcher = page.getByLabel('Switch School', { exact: true });
        const options = await switcher.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' })));
        const zixuan = options.find(option => option.text.startsWith('Zixuan QA School'));
        expect(zixuan?.value).toBeTruthy();
        await switcher.selectOption(zixuan.value);
        await expect(page.getByText('Current School: Zixuan QA School')).toBeVisible();

        for (const [path, heading] of [
            ['/central-finance/student-ledger', 'Student Ledger'],
            ['/central-finance/payments', 'Payment / Receipt'],
            ['/central-finance/ledger', 'Standard Ledger'],
            ['/central-finance/categories', 'Chart of Accounts'],
            ['/central-finance/staff', 'Finance Staff / Scope'],
        ]) {
            await page.goto(path, { waitUntil: 'domcontentloaded' });
            await expect(page.getByText(heading, { exact: true }).first()).toBeVisible();
        }

        await expect(page.getByText('@endif', { exact: true })).toHaveCount(0);

        await expect(page.getByText(/School Scope is configured by Central Super Admin in Finance Groups\./)).toBeVisible();
        await page.goto('/central-finance/ledger', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('select[name="fund_account_id"]')).toBeVisible();
        await page.getByText('More Filters', { exact: true }).click();
        await expect(page.locator('select[name="category_id"]')).toBeVisible();
        await expect(page.locator('select[name="operator_id"]')).toBeVisible();
    } finally {
        await context.close();
    }
});

test('All Schools is read-only and legacy QA School remains non-writable in Central Finance', async ({ browser }) => {
    const head = await centralPage(browser, 'group_hq@group-qa.test', 'local-only');
    try {
        await head.page.goto('/central-finance/receivables', { waitUntil: 'domcontentloaded' });
        await expect(head.page.getByText('All Schools is read-only. Select an authorized School before searching students or collecting payment.')).toBeVisible();
        await expect(head.page.locator('#central-payment-form')).toHaveCount(0);

        const headSwitcher = head.page.getByLabel('Switch School', { exact: true });
        const headOptions = await headSwitcher.locator('option').evaluateAll(rows => rows.map(row => ({ value: row.value, text: row.textContent || '' })));
        const headZixuan = headOptions.find(option => option.text.startsWith('Zixuan QA School'));
        expect(headZixuan?.value).toBeTruthy();
        await headSwitcher.selectOption(headZixuan.value);
        await expect(head.page.getByText('Current School: Zixuan QA School')).toBeVisible();
        await head.page.goto('/central-finance/receivables', { waitUntil: 'domcontentloaded' });
        await expect(head.page.locator('#central-payment-form')).toHaveCount(0);
        await expect(head.page.getByText('This School remains in legacy Finance until its approved Central cutover. Central Finance is read-only.')).toBeVisible();
    } finally {
        await head.context.close();
    }

    const accountant = await centralPage(browser, 'group_school_a@group-qa.test', 'local-only');
    try {
        await expect(accountant.page.getByLabel('Switch School', { exact: true }).locator('option')).toHaveCount(1);
        await accountant.page.goto('/central-finance/receivables', { waitUntil: 'domcontentloaded' });
        await expect(accountant.page.locator('#central-payment-form')).toHaveCount(0);
        await expect(accountant.page.locator('#central-payment-filters')).toHaveCount(0);
        await expect(accountant.page.locator('body')).not.toContainText('GROUP_QA_SCHOOL_B_STUDENT');
    } finally {
        await accountant.context.close();
    }
});
