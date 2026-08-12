const { test, expect, request } = require('@playwright/test');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');
const baseURL = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
async function state(email) { const p=`/tmp/finance-staff-${email.replace(/[^a-z]/g,'_')}.json`; await authenticateLocalBowenQa(email,p); return p; }
test('School Admin and Head Finance can view Finance Staff while Cashier is rejected', async ({browser}) => {
  for (const email of ['qa_admin@bowen-qa.test','qa_head_finance@bowen-qa.test']) { const c=await browser.newContext({baseURL,storageState:await state(email)}); const p=await c.newPage(); const r=await p.goto('/finance-staff'); expect(r.status()).toBe(200); await expect(p.getByRole('heading',{name:'Finance Staff'})).toBeVisible(); await c.close(); }
  const api=await request.newContext({baseURL,storageState:await state('qa_cashier_a@bowen-qa.test')}); try { expect((await api.get('/finance-staff',{maxRedirects:0})).status()).toBe(403); } finally { await api.dispose(); }
});
