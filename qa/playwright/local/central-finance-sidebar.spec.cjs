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

test('Zixuan and Timecity Accountants can select only their own Central Finance School', async ({ browser }) => {
    for (const [email, ownSchool, otherSchool] of [
        ['group_school_a@group-qa.test', 'Zixuan QA School', 'Timecity QA School'],
        ['group_school_b@group-qa.test', 'Timecity QA School', 'Zixuan QA School'],
    ]) {
        const { context, page } = await centralPage(browser, email, 'local-only');
        try {
            const switcher = page.getByLabel('Switch School', { exact: true });
            await expect(switcher).toBeVisible();
            await expect(switcher.locator('option')).toHaveCount(2);
            await expect(switcher).toContainText(ownSchool);
            await expect(switcher).not.toContainText(otherSchool);
            await expect(page.locator('body')).not.toContainText(otherSchool);

            await switcher.selectOption({ label: ownSchool });
            await expect(page.getByText(`当前校区：${ownSchool}`)).toBeVisible();
            await expect(page.locator('body')).not.toContainText(otherSchool);
        } finally {
            await context.close();
        }
    }
});

test('ordinary Finance users do not see legacy Group Finance and Super Admin retains Finance Groups', async ({ browser }) => {
    const ordinary = await applicationPage(browser, 'qa_central_regular_finance@bowen-qa.test');
    try {
        await expect(ordinary.page.getByText('Group Finance', { exact: true })).toHaveCount(0);
    } finally {
        await ordinary.context.close();
    }

    const superAdmin = await configurationPage(browser, 'qa_central_super_admin@bowen-qa.test');
    try {
        await expect(superAdmin.page.getByRole('heading', { name: 'Finance Groups', exact: true })).toBeVisible();
        await superAdmin.page.goto('/central-finance', { waitUntil: 'domcontentloaded' });
        await expect(superAdmin.page.getByText('403 A Central Finance identity is required.', { exact: true })).toBeVisible();
    } finally {
        await superAdmin.context.close();
    }
});

test('Central Finance sidebar expands and collapses both three-level groups on mobile', async ({ browser }) => {
    const { context, page } = await centralPage(browser, 'group_hq@group-qa.test', 'local-only');
    try {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('.navbar-toggler-right[data-toggle="offcanvas"]').click();

        const groups = page.locator('details.central-finance-sidebar-group');
        await expect(groups).toHaveCount(2);

        // The active route's group is intentionally expanded on load.
        await expect(groups.nth(0)).toHaveAttribute('open', '');
        await expect(groups.nth(0).getByRole('link', { name: '财务总览', exact: true })).toBeVisible();
        await expect(groups.nth(0).getByRole('link', { name: 'Standard Ledger', exact: true })).toBeVisible();
        await groups.nth(0).locator('summary').click();
        await expect(groups.nth(0).getByRole('link', { name: '财务总览', exact: true })).toBeHidden();
        await groups.nth(0).locator('summary').click();
        await expect(groups.nth(0).getByRole('link', { name: '财务总览', exact: true })).toBeVisible();

        await expect(groups.nth(1)).not.toHaveAttribute('open', '');
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

test('Central Finance P0 read screens expose ledger, history, account reporting, and configuration without a write', async ({ browser }) => {
    const { context, page } = await centralPage(browser, 'group_hq@group-qa.test', 'local-only');
    try {
        const switcher = page.getByLabel('Switch School', { exact: true });
        await switcher.selectOption({ label: 'Zixuan QA School' });
        await expect(page.getByText('当前校区：Zixuan QA School')).toBeVisible();

        for (const [path, heading] of [
            ['/central-finance/student-ledger', '学生账本'],
            ['/central-finance/payments', '收费记录 / 收据'],
            ['/central-finance/ledger', 'Standard Ledger'],
            ['/central-finance/categories', 'Income / Expense Categories'],
            ['/central-finance/staff', 'Central Finance Staff'],
        ]) {
            await page.goto(path, { waitUntil: 'domcontentloaded' });
            await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
        }

        await expect(page.getByText('@endif', { exact: true })).toHaveCount(0);

        await expect(page.getByText('School Scope is configured by Central Super Admin in Finance Groups.')).toBeVisible();
        await page.goto('/central-finance/ledger', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('select[name="fund_account_id"]')).toBeVisible();
        await expect(page.locator('select[name="category_id"]')).toBeVisible();
        await expect(page.locator('select[name="operator_id"]')).toBeVisible();
    } finally {
        await context.close();
    }
});

test('Head Finance and the Zixuan Accountant see the school-first Central Student Fee flow', async ({ browser }) => {
    const head = await centralPage(browser, 'group_hq@group-qa.test', 'local-only');
    try {
        await head.page.goto('/central-finance/receivables', { waitUntil: 'domcontentloaded' });
        await expect(head.page.getByText('All Schools is read-only for payment history and totals. Please select a School to collect a payment.')).toBeVisible();
        await expect(head.page.locator('#central-payment-form')).toHaveCount(0);

        await head.page.getByLabel('Switch School', { exact: true }).selectOption({ label: 'Zixuan QA School' });
        await expect(head.page.getByText('当前校区：Zixuan QA School')).toBeVisible();
        await head.page.goto('/central-finance/receivables', { waitUntil: 'domcontentloaded' });
        await expect(head.page.locator('#central-payment-form')).toHaveCount(0);
        await expect(head.page.getByText('Payment collection is unavailable until Central cutover and operating scope are both active. Student financial information remains read-only.')).toBeVisible();
        await expect(head.page.locator('#central-payment-class')).toBeVisible();
        await expect(head.page.locator('#central-payment-search')).toBeVisible();
        await expect(head.page.locator('#central-payment-student option')).toHaveCount(2);
        await head.page.locator('#central-payment-student').selectOption({ index: 1 });
        await expect(head.page.locator('#central-payment-student-summary')).toBeVisible();
        await expect(head.page.locator('#central-payment-summary-name')).not.toHaveText('');
        await expect(head.page.locator('#central-payment-summary-due')).not.toHaveText('');
        await expect(head.page.locator('#central-payment-summary-outstanding')).not.toHaveText('');
        await expect(head.page.getByText('当前没有待缴项目')).toBeVisible();
        await expect(head.page.locator('#central-payment-receivable')).toBeEnabled();
        await expect(head.page.locator('#central-payment-receivable option')).toHaveCount(1);
        await expect(head.page.locator('#central-payment-amount')).toHaveCount(0);
        await expect(head.page.locator('#central-payment-submit')).toHaveCount(0);
    } finally {
        await head.context.close();
    }

    const accountant = await centralPage(browser, 'group_school_a@group-qa.test', 'local-only');
    try {
        await accountant.page.getByLabel('Switch School', { exact: true }).selectOption({ label: 'Zixuan QA School' });
        await accountant.page.goto('/central-finance/receivables', { waitUntil: 'domcontentloaded' });
        await expect(accountant.page.locator('#central-payment-form')).toHaveCount(0);
        await expect(accountant.page.getByText('Payment collection is unavailable until Central cutover and operating scope are both active. Student financial information remains read-only.')).toBeVisible();
        await expect(accountant.page.locator('#central-payment-filters')).toBeVisible();
        await expect(accountant.page.locator('#central-payment-student')).toContainText('GROUP_QA_SCHOOL_A_STUDENT');
        await expect(accountant.page.locator('#central-payment-student')).not.toContainText('GROUP_QA_SCHOOL_B_STUDENT');
    } finally {
        await accountant.context.close();
    }
});
