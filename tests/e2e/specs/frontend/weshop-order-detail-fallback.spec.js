// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

const accountDashboardUrlPattern = /customer\/account(?:\/index)?\/?(?:[?#].*)?$/i;

function buildCustomerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'Order',
    lastName: 'Fallback',
    email: `order-fallback-${suffix}@example.com`,
    password: 'OrderFallback#2026',
  };
}

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

async function registerAndLogin(page, customer) {
  await gotoFrontend(page, '/customer/account/register', {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
    settleMs: 800,
  });

  await page.locator('#firstname').fill(customer.firstName);
  await page.locator('#lastname').fill(customer.lastName);
  await page.locator('#email').fill(customer.email);
  await page.locator('#password').fill(customer.password);
  await page.locator('#confirm_password').fill(customer.password);
  await page.locator('input[name="agree_terms"]').check();

  await Promise.all([
    page.waitForURL(accountDashboardUrlPattern, {
      timeout: 90000,
      waitUntil: 'commit',
    }),
    page.locator('button[type="submit"]').click(),
  ]);

  await expect(page.locator('body')).toContainText(customer.email, { timeout: 15000 });
  await expectNoRuntimeError(page);
}

test.describe('WeShop order detail fallback', () => {
  test('redirects authenticated users to order list when order id is missing', async ({ page }) => {
    const customer = buildCustomerIdentity();
    await registerAndLogin(page, customer);

    await gotoFrontend(page, '/weshop/order/view', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1000,
    });

    await expect(page).toHaveURL(/weshop\/order\/list|\/order\/list/i, { timeout: 20000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
  });

  test('redirects authenticated users to order list on invalid order id', async ({ page }) => {
    const customer = buildCustomerIdentity();
    await registerAndLogin(page, customer);

    await gotoFrontend(page, '/weshop/order/view?id=99999999', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1000,
    });

    await expect(page).toHaveURL(/weshop\/order\/list|\/order\/list/i, { timeout: 20000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    await expect(page.locator('body')).toContainText(/Order|订单/i);
  });

  test('redirects authenticated users to order list on non-numeric order id', async ({ page }) => {
    const customer = buildCustomerIdentity();
    await registerAndLogin(page, customer);

    await gotoFrontend(page, '/weshop/order/view?id=abc', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1000,
    });

    await expect(page).toHaveURL(/weshop\/order\/list|\/order\/list/i, { timeout: 20000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
  });
});
