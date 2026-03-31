// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  buildModuleBackendRoute,
} = require('../../framework');

const WESHOP_QA_MODULE = 'WeShop_QA';

test.describe('WeShop QA backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test('renders QA management index without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_QA_MODULE, 'qa');

    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('renders QA detail page (id=1) without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute(WESHOP_QA_MODULE, 'qa', 'view');

    // id=1 可能不存在：控制器会回退到列表；这里主要验证不出现 PHP 致命错误。
    await gotoBackend(page, `${route}?id=1`, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
