const { test, expect, request } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:18999';

function csrfToken(html) {
    const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (!match) throw new Error('The local login form did not provide a CSRF token.');
    return match[1];
}

async function groupHeadFinanceState() {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const loginPage = await api.get('/login', { maxRedirects: 0 });
        expect(loginPage.status()).toBe(200);
        const response = await api.post('/login', {
            form: {
                _token: csrfToken(await loginPage.text()),
                email: 'group_hq@group-qa.test',
                password: 'local-only',
            },
            maxRedirects: 0,
        });
        expect([302, 303]).toContain(response.status());
        return await api.storageState();
    } finally {
        await api.dispose();
    }
}

test('Group Head Finance previews and confirms an Unidentified V3 XLSX row locally', async ({ browser }, testInfo) => {
    const context = await browser.newContext({ baseURL, storageState: await groupHeadFinanceState() });
    const page = await context.newPage();
    const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'group-import-v3-'));
    const reference = `LOCAL-GROUP-IMPORT-${Date.now()}`;

    try {
        const response = await page.goto('/central-finance/group-import', { waitUntil: 'networkidle' });
        expect(response.status()).toBe(200);
        await expect(page.getByRole('heading', { name: 'Group Finance Import V3' })).toBeVisible();

        const group = page.locator('#group-import-finance-group');
        await expect(group.locator('option')).toHaveCount(1);
        await group.selectOption({ index: 0 });
        const downloadEvent = page.waitForEvent('download');
        await page.locator('#group-import-template-link').click();
        const download = await downloadEvent;
        expect(download.suggestedFilename()).toBe('group-finance-import-template-v3.xlsx');
        const templatePath = path.join(scratch, 'template.xlsx');
        const uploadPath = path.join(scratch, 'unidentified.xlsx');
        await download.saveAs(templatePath);
        execFileSync('php', [
            path.join(__dirname, 'fixtures', 'fill-unidentified-group-import-workbook.php'),
            templatePath,
            reference,
        ], { stdio: 'pipe' });
        fs.copyFileSync(templatePath, uploadPath);

        await page.locator('#group-import-file').setInputFiles(uploadPath);
        await page.getByRole('button', { name: 'Preview and validate' }).click();
        await expect(page).toHaveURL(/\/central-finance\/group-import\?batch=/);
        const previewRow = page.locator('tbody tr').filter({ hasText: reference });
        await expect(previewRow).toBeVisible();
        await expect(previewRow).toContainText('Unidentified Deposit');
        await expect(previewRow).toContainText('2026-10-09');
        await expect(previewRow).toContainText('Will create one physical cash-in in the Unidentified Deposit pool; it is not School income.');
        await expect(page.locator('form[data-lifecycle-confirm]')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm Group Import' }).click();
        const modal = page.locator('#central-finance-lifecycle-confirmation');
        await expect(modal).toBeVisible();
        await modal.locator('[data-lifecycle-confirm="submit"]').click();
        await expect(page).toHaveURL(/\/central-finance\/group-import\?batch=/);
        await expect(page.getByRole('heading', { name: 'Completed Group Import' })).toBeVisible();
        await expect(page.getByText('Confirmed', { exact: true })).toBeVisible();
        await expect(page.getByText('Canonical source linked.')).toBeVisible();
    } finally {
        await context.close();
        fs.rmSync(scratch, { recursive: true, force: true });
    }
});
