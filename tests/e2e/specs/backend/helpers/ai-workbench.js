const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.resolve(__dirname, '../../../../..');
const APP_ENV_PATH = path.join(ROOT_DIR, 'app', 'etc', 'env.php');

function getBaseUrl() {
  return process.env.PLAYWRIGHT_BASE_URL || 'https://127.0.0.1:9982';
}

function readBackendPrefix() {
  const content = fs.readFileSync(APP_ENV_PATH, 'utf8');
  const match = content.match(/'backend'\s*=>\s*\[\s*'prefix'\s*=>\s*'([^']+)'/s);
  if (!match) {
    throw new Error(`Could not read backend prefix from ${APP_ENV_PATH}`);
  }
  return match[1];
}

function buildBackendRoot(currentUrl) {
  const url = new URL(currentUrl);
  const adminIndex = url.pathname.lastIndexOf('/admin');
  if (adminIndex === -1) {
    throw new Error(`Could not derive backend root from URL: ${currentUrl}`);
  }
  return `${url.origin}${url.pathname.slice(0, adminIndex)}`;
}

function buildWorkbenchUrl(backendRoot, provider = 'pagebuilder', fakeMode = false) {
  const url = new URL(`${backendRoot}/websites/backend/site-builder-agent/index`);
  url.searchParams.set('provider', provider);
  if (fakeMode) {
    url.searchParams.set('fake_mode', '1');
  }
  return url.toString();
}

async function loginAsAdmin(page) {
  const baseUrl = getBaseUrl();
  const backendPrefix = process.env.PLAYWRIGHT_ADMIN_PREFIX || readBackendPrefix();
  const username = process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin';
  const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin';

  await page.goto(`${baseUrl}/${backendPrefix}/admin/login`, {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  await page.fill('input[name="username"], input[type="text"]', username);
  await page.fill('input[name="password"], input[type="password"]', password);
  await Promise.all([
    page.waitForURL(new RegExp(`/${backendPrefix}/.+/admin`), { timeout: 30000 }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  await page.waitForTimeout(1000);

  return buildBackendRoot(page.url());
}

module.exports = {
  buildWorkbenchUrl,
  getBaseUrl,
  loginAsAdmin,
  readBackendPrefix,
};
