// @weline-e2e-runtime fallback
// @ts-check
/**
 * Weline_Framework 是框架核心库，不提供独立 UI 后台页面。
 * 本 spec 验证框架关键路由可访问且无致命错误。
 * 框架内部机制由单元测试覆盖: php bin/w phpunit:run --module=Weline_Framework
 */
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Framework';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => {
    errors.push(String(error && error.message ? error.message : error));
  });
  return errors;
}

async function assertNoFatal(page) {
  await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
}

test.describe(MODULE + ' backend smoke (framework core - no direct UI)', () => {
  test.describe.configure({ retries: 0 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 60000 });
  });

  test('TC-01: framework routing serves login page without fatal errors [module:Weline_Framework][case:FRAMEWORK-001]', async ({ page }) => {
    const pageErrors = bindPageErrors(page);
    // Verify framework routing can serve the login page
    await gotoBackend(page, 'admin/login', { timeout: 60000, settleMs: 1200 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await assertNoFatal(page);

    // Login form should be present with correct action
    await expect(page.locator('form[action*="/admin/login/post"]')).toBeVisible({ timeout: 10000 });
    // Username field
    await expect(page.locator('input[name="username"]')).toBeVisible();
    // Password field
    await expect(page.locator('input[name="password"]')).toBeVisible();

    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  });

  test('TC-02: framework routing serves dashboard after login without fatal errors [module:Weline_Framework][case:FRAMEWORK-002]', async ({ page }) => {
    const pageErrors = bindPageErrors(page);
    // Login first
    await loginAsAdmin(page, { timeout: 60000 });

    // Navigate to dashboard - this verifies framework routing works
    await gotoBackend(page, 'admin/dashboard', { timeout: 60000, settleMs: 1200 });
    await assertNoFatal(page);

    // Dashboard should contain key elements
    const body = page.locator('body');
    await expect(body).toContainText(/Dashboard|面板|概览/);

    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  });

  test('TC-03: framework routing serves admin index without fatal errors [module:Weline_Framework][case:FRAMEWORK-003]', async ({ page }) => {
    const pageErrors = bindPageErrors(page);
    await loginAsAdmin(page, { timeout: 60000 });

    // Access admin index
    await gotoBackend(page, 'admin', { timeout: 60000, settleMs: 1200 });
    await assertNoFatal(page);

    // Should load without errors
    const body = page.locator('body');
    await expect(body).toBeVisible();

    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  });
});
