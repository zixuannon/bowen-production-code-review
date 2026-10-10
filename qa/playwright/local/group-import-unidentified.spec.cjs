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

async function groupFinanceState(email = 'group_hq@group-qa.test') {
    const api = await request.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
        const loginPage = await api.get('/login', { maxRedirects: 0 });
        expect(loginPage.status()).toBe(200);
        const response = await api.post('/login', {
            form: {
                _token: csrfToken(await loginPage.text()),
                email,
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

test('Group Head Finance previews and confirms an Unidentified V3 XLSX row locally', async ({ browser }) => {
    const context = await browser.newContext({ baseURL, storageState: await groupFinanceState() });
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
        await page.waitForLoadState('networkidle');
        const previewRow = page.locator('tbody tr').filter({ hasText: reference });
        await expect(previewRow).toBeVisible();
        await expect(previewRow).toContainText('Unidentified Deposit');
        await expect(previewRow).toContainText('2026-10-09');
        await expect(previewRow).toContainText('Will create one physical cash-in in the Unidentified Deposit pool; it is not School income.');
        await expect(page.locator('form[data-lifecycle-confirm]')).toBeVisible();

        const confirmPattern = '**/central-finance/group-import/*/confirm';
        const rejectUnexpectedDirectSubmit = route => route.abort();
        await page.route(confirmPattern, rejectUnexpectedDirectSubmit);
        await page.getByRole('button', { name: 'Confirm Group Import' }).click();
        const modal = page.locator('#central-finance-lifecycle-confirmation');
        await expect(modal).toBeVisible();
        await page.unroute(confirmPattern, rejectUnexpectedDirectSubmit);
        await modal.locator('[data-lifecycle-confirm="submit"]').click();
        await expect(page).toHaveURL(/\/central-finance\/group-import\?batch=/);
        await expect(page.getByRole('heading', { name: 'Completed Group Import' })).toBeVisible();
        await expect(page.getByText('Confirmed', { exact: true })).toBeVisible();
        await expect(page.getByText('Canonical source linked.')).toBeVisible();

        await page.goto('/central-finance/account-statements', { waitUntil: 'domcontentloaded' });
        const statementRow = page.locator('tbody tr').filter({ hasText: reference });
        await expect(statementRow).toHaveCount(1);
        await expect(statementRow).toContainText('Unidentified Deposit');
        await expect(statementRow).toContainText('75.00');
        await page.goto('/central-finance/group-import', { waitUntil: 'domcontentloaded' });

        const invalidPath = path.join(scratch, 'invalid.xlsx');
        fs.copyFileSync(uploadPath, invalidPath);
        execFileSync('php', [
            path.join(__dirname, 'fixtures', 'fill-unidentified-group-import-workbook.php'),
            invalidPath,
            `${reference}-ERROR`,
            'invalid',
        ], { stdio: 'pipe' });
        await page.locator('#group-import-file').setInputFiles(invalidPath);
        await page.getByRole('button', { name: 'Preview and validate' }).click();
        await expect(page).toHaveURL(/\/central-finance\/group-import\?batch=/);
        await expect(page.locator('tbody tr').filter({ hasText: `${reference}-ERROR` })).toContainText('Error');
        const errorBatchToken = new URL(page.url()).searchParams.get('batch');
        expect(errorBatchToken).toBeTruthy();

        await page.goto('/central-finance/group-import/history', { waitUntil: 'networkidle' });
        await expect(page.getByRole('heading', { name: 'Group Import History' })).toBeVisible();
        await expect(page.getByRole('cell', { name: 'unidentified.xlsx' }).first()).toBeVisible();
        const errorBatchRow = page.locator(`tr:has(a[href*="batch=${errorBatchToken}"])`);
        await expect(errorBatchRow).toBeVisible();
        const errorDownload = page.waitForEvent('download');
        await errorBatchRow.getByRole('link', { name: 'Export error rows' }).click();
        const errorFile = await errorDownload;
        expect(errorFile.suggestedFilename()).toMatch(/^group-import-errors-.*\.csv$/);
        const errorCsvPath = path.join(scratch, 'errors.csv');
        await errorFile.saveAs(errorCsvPath);
        const errorCsv = fs.readFileSync(errorCsvPath, 'utf8');
        expect(errorCsv).toContain("'=HYPERLINK");
        expect(errorCsv).not.toContain(`${reference},`);
        expect(errorCsv.trim().split(/\r?\n/)).toHaveLength(2);

        const otherOperatorState = await groupFinanceState('group_school_a@group-qa.test');
        const otherOperator = await request.newContext({ baseURL, storageState: otherOperatorState });
        try {
            const errorToken = errorFile.suggestedFilename().replace(/^group-import-errors-/, '').replace(/\.csv$/, '');
            const deniedExport = await otherOperator.get(`/central-finance/group-import/${errorToken}/errors.csv`);
            expect([403, 404]).toContain(deniedExport.status());
        } finally {
            await otherOperator.dispose();
        }
    } finally {
        await context.close();
        fs.rmSync(scratch, { recursive: true, force: true });
    }
});
