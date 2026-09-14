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
    if (await switcher.count()) {
        const values = await switcher.locator('option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
        if (values.length) {
            await switcher.selectOption(values[0]);
            await page.waitForLoadState('networkidle');
        }
    }
}

async function shellAudit(page, path) {
    const consoleErrors = [];
    const pageErrors = [];
    const badResponses = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('response', (response) => { if ([404, 500].includes(response.status())) badResponses.push(`${response.status()} ${response.url()}`); });

    const response = await page.goto(path, { waitUntil: 'networkidle' });
    expect(response).not.toBeNull();
    expect(response.status()).toBe(200);
    const result = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        rawKey: /\b(?:central_finance|group_import|student_import|handover)\.[a-z0-9_.]+\b/i.test(document.body.innerText),
        undefinedText: /\bundefined\b/i.test(document.body.innerText),
        activeLeaves: document.querySelectorAll('[data-ui-sidebar-nav] a.nav-link[aria-current="page"]').length,
        activeParents: document.querySelectorAll('[data-ui-sidebar-nav] summary.active, [data-ui-sidebar-nav] a[data-toggle="collapse"].active').length,
        hugePaginationSvg: Array.from(document.querySelectorAll('.pagination svg')).some((svg) => {
            const box = svg.getBoundingClientRect();
            return box.width > 48 || box.height > 48;
        }),
    }));
    expect(result.overflow).toBeFalsy();
    expect(result.rawKey).toBeFalsy();
    expect(result.undefinedText).toBeFalsy();
    expect(result.activeLeaves).toBe(1);
    expect(result.activeParents).toBe(0);
    expect(result.hugePaginationSvg).toBeFalsy();
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
    expect(badResponses).toEqual([]);
}

for (const viewport of [{ width: 1440, height: 900 }, { width: 1280, height: 800 }, { width: 390, height: 844 }]) {
    test(`Central Finance UX batch remains stable at ${viewport.width}px`, async ({ browser }) => {
        const context = await browser.newContext({ baseURL, storageState: await headFinanceState(), viewport });
        const page = await context.newPage();
        try {
            await selectFirstSchool(page);
            for (const path of [
                '/central-finance/fund-accounts',
                '/central-finance/ledger',
                '/central-finance/reports',
                '/central-finance/audits',
                '/central-finance/imports',
            ]) await shellAudit(page, path);

            await page.goto('/central-finance/fund-accounts', { waitUntil: 'networkidle' });
            await expect(page.locator('#central-finance-cutover-readiness')).toHaveCount(0);
            await expect(page.getByText('中央财务启用准备', { exact: true })).toHaveCount(0);
            const manage = page.locator('a[href*="/central-finance/fund-accounts/"][href$="/manage"]').first();
            await expect(manage).toHaveCount(1);
            const managed = await page.goto(await manage.getAttribute('href'), { waitUntil: 'networkidle' });
            expect(managed.status()).toBe(200);
            await expect(page.getByText('Opening Allocation / Adjustment', { exact: true })).toBeVisible();

            await page.goto('/central-finance/reports', { waitUntil: 'networkidle' });
            for (const label of ['Report filters', 'School comparison', 'Income / expense trend', 'Category analysis']) {
                await expect(page.getByText(label, { exact: true })).toBeVisible();
            }

            await page.goto('/central-finance/audits', { waitUntil: 'networkidle' });
            await expect(page.getByText('Before / After', { exact: true })).toBeVisible();

            await page.goto('/central-finance/imports', { waitUntil: 'networkidle' });
            await expect(page.getByRole('heading', { name: /Import Batch|导入批次/, exact: true }).first()).toBeVisible();

            await page.goto('/central-finance/student-collection', { waitUntil: 'networkidle' });
            const detail = page.locator('a[href*="/central-finance/student-collection/"]').first();
            await expect(detail).toHaveCount(1);
            const detailResponse = await page.goto(await detail.getAttribute('href'), { waitUntil: 'networkidle' });
            expect(detailResponse.status()).toBe(200);
            await expect(page.locator('body')).not.toContainText('403');
        } finally {
            await context.close();
        }
    });
}
