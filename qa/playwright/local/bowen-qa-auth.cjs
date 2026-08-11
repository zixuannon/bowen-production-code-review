const fs = require('node:fs');
const path = require('node:path');
const { request } = require('@playwright/test');

const EMAIL = 'qa_admin@bowen-qa.test';
const SCHOOL_CODE = 'BOWEN_QA';
const PASSWORD = 'local-bowen-qa-only';
const authState = path.join(__dirname, '..', '.auth', 'bowen-qa.json');

function localBaseUrl() {
  const value = process.env.LOCAL_QA_BASE_URL || 'http://127.0.0.1:8000';
  const parsed = new URL(value);
  if (!['127.0.0.1', 'localhost', '::1'].includes(parsed.hostname)) {
    throw new Error('Local QA authentication is restricted to localhost, 127.0.0.1, or ::1.');
  }
  return parsed.origin;
}

function csrfToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
  if (!match) throw new Error('The local Laravel login form did not provide a CSRF token.');
  return match[1];
}

async function authenticateLocalBowenQa() {
  const baseURL = localBaseUrl();
  const api = await request.newContext({ baseURL });
  try {
    const login = await api.get('/login', { maxRedirects: 0 });
    if (login.status() !== 200) throw new Error(`Local GET /login returned HTTP ${login.status()}.`);

    const response = await api.post('/login', {
      form: { _token: csrfToken(await login.text()), email: EMAIL, password: PASSWORD, code: SCHOOL_CODE },
      maxRedirects: 0,
    });
    if (![302, 303].includes(response.status())) {
      throw new Error(`Local BOWEN_QA login returned HTTP ${response.status()}.`);
    }
    const location = new URL(response.headers().location || '/login', baseURL);
    if (location.pathname === '/login' || location.pathname === '/2fa') {
      throw new Error(`Local BOWEN_QA login did not authenticate (${location.pathname}).`);
    }

    const dashboard = await api.get('/dashboard', { maxRedirects: 0 });
    if (dashboard.status() !== 200) throw new Error(`Authenticated local dashboard returned HTTP ${dashboard.status()}.`);
    const html = await dashboard.text();
    if (!html.includes('Bowen School') || !html.includes('Finance')) {
      throw new Error('Authenticated dashboard did not render the expected BOWEN_QA identity and finance navigation.');
    }

    fs.mkdirSync(path.dirname(authState), { recursive: true, mode: 0o700 });
    await api.storageState({ path: authState });
    fs.chmodSync(authState, 0o600);
    return authState;
  } finally {
    await api.dispose();
  }
}

module.exports = { authenticateLocalBowenQa, authState, localBaseUrl };
