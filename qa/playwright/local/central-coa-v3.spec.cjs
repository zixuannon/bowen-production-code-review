const { test, expect, request } = require('@playwright/test');
const fs = require('node:fs/promises');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8002';
if (!['127.0.0.1', 'localhost'].includes(new URL(baseURL).hostname)) throw new Error('Local-only Chart of Accounts acceptance');

async function login(browser, email = 'group_hq@group-qa.test') {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const loginPage = await api.get('/login');
        const csrf = (await loginPage.text()).match(/name=["']_token["'][^>]+value=["']([^"']+)/)[1];
        const response = await api.post('/login', { form: { _token: csrf, email, password: 'local-only' }, maxRedirects: 0 });
        expect([302, 303]).toContain(response.status());
        const context = await browser.newContext({ baseURL, storageState: await api.storageState() });
        return { context, page: await context.newPage() };
    } finally { await api.dispose(); }
}

test('Central CoA V3 UI preserves shared definitions, codes, holder and responsive import download', async ({ browser }, testInfo) => {
    const { context, page } = await login(browser);
    const errors = [];
    const httpFailures = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => { if (response.status() >= 400) httpFailures.push(`${response.status()} ${response.url()}`); });
    try {
        await page.setViewportSize({ width: 1440, height: 1000 });
        expect((await page.goto('/central-finance/categories', { waitUntil: 'networkidle' })).status()).toBe(200);
        const form = page.locator('form[method="POST"][action$="/central-finance/categories"]');
        await expect(form).toBeVisible();
        expect(await form.locator('select[name="type"] option').evaluateAll(options => options.map(option => option.value))).toEqual(['asset', 'liability', 'equity', 'income', 'expense']);
        const code = `0101${Date.now().toString().slice(-7)}`;
        await form.locator('select[name="type"]').selectOption('income');
        await form.locator('input[name="category_code"]').fill(code);
        await form.locator('input[name="name"]').fill(`V3 Browser Tuition ${code}`);
        const schools = form.locator('input[name="school_ids[]"]');
        await expect(schools).toHaveCount(2);
        await schools.nth(0).check();
        await schools.nth(1).check();
        await form.locator('textarea[name="reason"]').fill('Isolated local V3 browser acceptance; one definition allocated to two QA schools');
        await form.locator('button[type="submit"], button:not([type])').click();
        await page.waitForLoadState('networkidle');
        const row = page.locator('tr').filter({ hasText: `V3 Browser Tuition ${code}` }).first();
        await expect(row).toContainText(code);
        await expect(row).toContainText('Zixuan QA School');
        await expect(row).toContainText('Timecity QA School');
        await row.getByRole('button', { name: new RegExp(code) }).click();
        const editForm = page.locator('form[action*="/categories/"][action$="/update"]').filter({ has: page.locator(`input[value="${code}"]`) });
        await expect(editForm.locator('input[name="category_code"]')).toHaveAttribute('readonly', '');
        await expect(editForm.locator('select[name="type"]')).toBeDisabled();
        await expect(editForm.locator('input[name="school_ids[]"]:checked')).toHaveCount(2);

        for (const width of [1440, 1280, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.reload({ waitUntil: 'networkidle' });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            expect(await form.evaluate(element => element.getBoundingClientRect().width)).toBeGreaterThan(Math.min(width - 90, 300));
            await page.screenshot({ path: testInfo.outputPath(`coa-${width}.png`), fullPage: true });
        }

        await page.goto('/central-finance/fund-accounts', { waitUntil: 'networkidle' });
        const fundForm = page.locator('form[method="POST"][action$="/central-finance/fund-accounts"]');
        await expect(fundForm).toBeVisible();
        await fundForm.locator('input[name="account_code"]').fill(`V3-${code}`);
        await fundForm.locator('input[name="account_name"]').fill(`V3 Browser Fund ${code}`);
        await fundForm.locator('input[name="owner_holder"]').fill('BOWEN QA COMPANY');
        await fundForm.locator('input[name="opening_balance"]').fill('0');
        await fundForm.locator('input[name="opening_balance_date"]').fill('2026-09-17');
        await fundForm.locator('input[name="opening_reason"]').fill('Local QA zero opening only');
        await fundForm.locator('button').click();
        await expect(page).toHaveURL(/fund-accounts\/\d+\/manage$/);
        const manageUrl = page.url();
        const holder = page.locator('input[name="owner_holder"]');
        await expect(holder).toHaveValue('BOWEN QA COMPANY');
        await holder.fill('BOWEN QA COMPANY LIMITED');
        const masterForm = holder.locator('xpath=ancestor::form');
        await masterForm.locator('input[name="reason"]').fill('Local QA holder edit, authorization unchanged');
        await masterForm.getByRole('button', { name: /Save master data/ }).click();
        await page.waitForLoadState('networkidle');
        await expect(holder).toHaveValue('BOWEN QA COMPANY LIMITED');
        const allocation = page.locator('form[action$="/school-allocations"]');
        const boxes = allocation.locator('input[type="checkbox"]');
        await expect(boxes).toHaveCount(2);
        await boxes.nth(0).check(); await boxes.nth(1).check();
        await allocation.locator('input[name="reason"]').fill('Local QA shared account access only');
        // Exercise the actual lifecycle confirmation rather than bypassing it.
        await allocation.getByRole('button').click();
        const confirm = page.locator('.swal2-confirm');
        if (await confirm.isVisible()) await confirm.click();
        await page.waitForLoadState('networkidle');
        await expect(allocation.locator('input[type="checkbox"]:checked')).toHaveCount(2);

        for (const width of [1440, 1280, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto(manageUrl, { waitUntil: 'networkidle' });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await page.screenshot({ path: testInfo.outputPath(`holder-${width}.png`), fullPage: true });
            expect((await page.goto('/central-finance/group-import', { waitUntil: 'networkidle' })).status()).toBe(200);
            await expect(page.locator('h1')).toContainText('Group Finance Import V3');
            await expect(page.locator('body')).not.toContainText('group-import.download_template_v3');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await page.screenshot({ path: testInfo.outputPath(`import-${width}.png`), fullPage: true });
        }
        const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#group-import-template-link').click()]);
        expect(download.suggestedFilename()).toMatch(/v3.*\.xlsx$/i);
        const bytes = await fs.readFile(await download.path());
        expect(bytes.subarray(0, 2).toString()).toBe('PK');
        expect(bytes.length).toBeGreaterThan(10000);
        expect(errors).toEqual([]);
        expect(httpFailures).toEqual([]);
    } finally { await context.close(); }
});
