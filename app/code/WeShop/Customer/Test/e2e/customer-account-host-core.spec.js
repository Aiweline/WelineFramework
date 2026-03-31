// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../../../../../tests/e2e/framework');

const accountDashboardUrlPattern = /customer\/account(?:\/index)?\/?(?:[?#].*)?$/i;

function buildCustomerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'Host',
    lastName: 'Flow',
    email: `customer-host-${suffix}@example.com`,
    password: 'CustomerHost#2026',
  };
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

test.describe('WeShop Customer account host core flow', () => {
  test('register then login keeps account host slot available', async ({ page }) => {
    const customer = buildCustomerIdentity();

    await gotoFrontend(page, '/customer/account/register', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });
    await dismissCustomerServicePrompt(page);
    await expect(page.locator('[data-layout="account_auth_content"]')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-wslot="account-auth-main"]')).toHaveCount(1, { timeout: 15000 });

    await page.locator('#firstname').fill(customer.firstName);
    await page.locator('#lastname').fill(customer.lastName);
    await page.locator('#email').fill(customer.email);
    await page.locator('#password').fill(customer.password);
    await page.locator('#confirm_password').fill(customer.password);
    await page.locator('input[name="agree_terms"]').check();

    const submitButton = page.locator('form[action="/customer/account/register"]').locator('button[type="submit"]').first();
    await Promise.all([
      page.waitForURL(accountDashboardUrlPattern, {
        timeout: 90000,
        waitUntil: 'commit',
      }),
      submitButton.click(),
    ]);

    await expect(page.locator('[data-wslot="account-main"]')).toHaveCount(1, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(customer.email, { timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/customer/account/logout', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 500,
    }).catch(() => {});

    await gotoFrontend(page, '/customer/account/login', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });
    await dismissCustomerServicePrompt(page);
    await expect(page.locator('[data-layout="account_auth_content"]')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-wslot="account-auth-main"]')).toHaveCount(1, { timeout: 15000 });

    await page.locator('#username, #email').fill(customer.email);
    await page.locator('#password').fill(customer.password);
    await Promise.all([
      page.waitForURL(accountDashboardUrlPattern, {
        timeout: 90000,
        waitUntil: 'commit',
      }),
      page.locator('button[type="submit"]').click(),
    ]);

    await expect(page.locator('[data-wslot="account-main"]')).toHaveCount(1, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(customer.email, { timeout: 15000 });
    await expectNoRuntimeError(page);
  });
});
