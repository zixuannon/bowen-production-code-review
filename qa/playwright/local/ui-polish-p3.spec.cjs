const { test, expect } = require('@playwright/test');

const adminPages = ['/dashboard', '/students', '/students/create-bulk'];
const financePages = ['/dashboard', '/bank-accounts', '/finance/transactions', '/student-ledger', '/fund-handovers'];
const viewports = [
  { width: 1440, height: 900 },
  { width: 1280, height: 800 },
  { width: 390, height: 844 },
];

async function auditShell(page, paths, viewport, baseURL) {
  const consoleErrors = [];
  const pageErrors = [];
  const failedResponses = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('response', (response) => {
    if (response.status() >= 400) failedResponses.push(`${response.status()} ${response.url()}`);
  });
  await page.setViewportSize(viewport);

  for (const path of paths) {
    const response = await page.goto(path, { waitUntil: 'networkidle' });
    expect(response, `${path} should return a document`).not.toBeNull();
    expect(response.status(), `${path} should remain accessible`).toBe(200);
    await expect(page).not.toHaveURL(/\/login/);

    const audit = await page.evaluate(() => {
      const visible = (element) => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
      };
      const bodyText = document.body.innerText;
      const unnamedActions = Array.from(document.querySelectorAll('button, a, [role="button"]'))
        .filter((element) => visible(element))
        .filter((element) => !(
          (element.innerText || '').trim()
          || element.getAttribute('aria-label')
          || element.getAttribute('title')
          || element.querySelector('img[alt]')?.getAttribute('alt')
        ));

      return {
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        brokenImages: Array.from(document.images).filter((image) => visible(image) && image.complete && image.naturalWidth === 0).length,
        rawKeys: (bodyText.match(/\b(?:central_finance|group_import|student_import|handover|finance_staff|schools)\.[a-z0-9_.]+\b/gi) || []),
        literalUndefined: /\bundefined\b/i.test(bodyText),
        unnamedActions: unnamedActions.length,
        pageLoading: document.body.classList.contains('ui-page-loading'),
        pageBusy: document.body.getAttribute('aria-busy'),
        footerBrand: Boolean(document.querySelector('.app-footer__brand')),
        namedSidebar: document.querySelector('#sidebar')?.getAttribute('aria-label') || '',
        activeCurrent: document.querySelectorAll('[data-ui-sidebar-nav] [aria-current="page"]').length,
      };
    });

    expect(audit.overflow, `${path} has page-level horizontal overflow`).toBeFalsy();
    expect(audit.brokenImages, `${path} has a broken visible image`).toBe(0);
    expect(audit.rawKeys, `${path} exposes a raw translation key`).toEqual([]);
    expect(audit.literalUndefined, `${path} exposes undefined`).toBeFalsy();
    expect(audit.unnamedActions, `${path} has unnamed visible actions`).toBe(0);
    expect(audit.pageLoading, `${path} remains in loading state`).toBeFalsy();
    expect(audit.pageBusy).toBe('false');
    expect(audit.footerBrand).toBeTruthy();
    expect(audit.namedSidebar).not.toBe('');
    expect(audit.activeCurrent, `${path} should expose one current navigation item`).toBeGreaterThanOrEqual(1);
  }

  await page.goto('/dashboard', { waitUntil: 'networkidle' });
  await page.locator('body').click({ position: { x: 1, y: 1 } });
  await page.keyboard.press('Tab');
  const focusAudit = await page.evaluate(() => {
    const active = document.activeElement;
    const style = active ? getComputedStyle(active) : null;
    return {
      tag: active?.tagName || '',
      name: active?.getAttribute('aria-label') || active?.textContent?.trim() || '',
      outlineStyle: style?.outlineStyle || 'none',
      outlineWidth: style?.outlineWidth || '0px',
    };
  });
  expect(focusAudit.tag).not.toBe('BODY');
  expect(focusAudit.name).not.toBe('');
  expect(focusAudit.outlineStyle).not.toBe('none');
  expect(focusAudit.outlineWidth).not.toBe('0px');

  expect(consoleErrors, failedResponses.join('\n')).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(new URL(baseURL).hostname).toMatch(/^(127\.0\.0\.1|localhost|::1)$/);
}

for (const viewport of viewports) {
  test(`P3 School shell remains accessible at ${viewport.width}px`, async ({ page, baseURL }) => {
    await auditShell(page, adminPages, viewport, baseURL);
  });
}

test.describe('Head Finance visual shell', () => {
  test.use({ storageState: 'qa/playwright/.auth/bowen-qa-head-finance.json' });

  for (const viewport of viewports) {
    test(`P3 Finance shell remains accessible at ${viewport.width}px`, async ({ page, baseURL }) => {
      await auditShell(page, financePages, viewport, baseURL);
    });
  }
});
