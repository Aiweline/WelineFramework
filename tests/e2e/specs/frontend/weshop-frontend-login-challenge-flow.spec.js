// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect, gotoFrontend } = require('../../framework');

const WORKSPACE_ROOT = path.resolve(__dirname, '../../../..');
const PHP_BIN =
  process.platform === 'win32' && fs.existsSync(path.join(WORKSPACE_ROOT, 'extend', 'server', 'php', 'php.exe'))
    ? path.join(WORKSPACE_ROOT, 'extend', 'server', 'php', 'php.exe')
    : 'php';

const accountDashboardUrlPattern = /customer\/account(?:\/index)?\/?(?:[?#].*)?$/i;

function buildCustomerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'Auth',
    lastName: 'Flow',
    email: `auth-flow-${suffix}@example.com`,
    password: 'AuthFlow#2026',
  };
}

function runCustomer2faBootstrap(action, email) {
  const scriptPath = path.resolve(__dirname, '../../framework/customer-2fa-bootstrap.php');
  const stdout = execFileSync(PHP_BIN, [scriptPath, `--action=${action}`, `--email=${email}`], {
    cwd: WORKSPACE_ROOT,
    encoding: 'utf8',
    env: process.env,
  });

  return JSON.parse(stdout);
}

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i, {
    timeout: 15000,
  });
}

async function dismissCustomerServicePrompt(page) {
  const modal = page.locator('#cs-bind-modal');
  const isVisible = await modal.isVisible({ timeout: 1000 }).catch(() => false);
  if (!isVisible) {
    return;
  }

  const closeButton = modal.locator('.cs-modal-close, .cs-btn-secondary').first();
  if (await closeButton.isVisible({ timeout: 1000 }).catch(() => false)) {
    await closeButton.click();
    await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  }
}

async function registerCustomer(page, customer) {
  await gotoFrontend(page, '/customer/account/register', {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
    settleMs: 800,
  });

  await dismissCustomerServicePrompt(page);
  await expect(page.locator('body')).toBeVisible({ timeout: 15000 });

  await page.locator('#firstname').waitFor({ state: 'visible', timeout: 60000 });
  await page.locator('#firstname').fill(customer.firstName);
  await page.locator('#lastname').fill(customer.lastName);
  await page.locator('#email').fill(customer.email);
  await page.locator('#password').fill(customer.password);
  await page.locator('#confirm_password').fill(customer.password);
  await page.locator('input[name="agree_terms"]').check();

  await Promise.all([
    page.waitForURL(accountDashboardUrlPattern, {
      timeout: 120000,
      waitUntil: 'commit',
    }),
    page.locator('form[action*="/customer/account/register"] button[type="submit"]').first().click(),
  ]);
}

async function logoutCustomer(page) {
  await gotoFrontend(page, '/customer/account/logout', {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
    settleMs: 500,
  }).catch(() => {});
}

test.describe('WeShop frontend login submit/challenge/success redirect flow', () => {
  test.describe.configure({ timeout: 300000 });

  test('login submit redirects to challenge then challenge verifies and redirects to target page', async ({ page }) => {
    const customer = buildCustomerIdentity();
    let twoFaEnabled = false;
    try {
      await registerCustomer(page, customer);
      await logoutCustomer(page);

      const twoFaSeed = runCustomer2faBootstrap('enable', customer.email);
      expect(twoFaSeed.status).toBe('ok');
      expect(twoFaSeed.backup_code).toBeTruthy();
      twoFaEnabled = true;

      await gotoFrontend(page, '/customer/account/login?redirect=cart', {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
        settleMs: 800,
      });

      await page.locator('#username, #email, input[name="username"], input[name="email"]').first().fill(customer.email);
      await page.locator('#password').fill(customer.password);

      await Promise.all([
        page.waitForURL(/challenge_token=/i, {
          timeout: 120000,
          waitUntil: 'commit',
        }),
        page.locator('form[action*="/customer/account/login"] button[type="submit"], form[action*="/customer/account/login"] input[type="submit"], button[type="submit"], input[type="submit"]').first().click(),
      ]);

      await expect(page.locator('input[name="challenge_token"]')).toBeVisible({ timeout: 15000 });
      await page.locator('input[name="code"], #code').first().fill(twoFaSeed.backup_code);

      const cartNav = page.waitForURL(/\/cart/i, {
        timeout: 120000,
        waitUntil: 'commit',
      });
      await page.locator('#verifyBtn, form[action*="/customer/account/challenge"] button[type="submit"]').first().click();
      await cartNav;

      await expect(page).toHaveURL(/\/cart/i, { timeout: 30000 });
      await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
      await expectNoRuntimeError(page);
    } finally {
      if (twoFaEnabled) {
        runCustomer2faBootstrap('disable', customer.email);
      }
    }
  });
});
