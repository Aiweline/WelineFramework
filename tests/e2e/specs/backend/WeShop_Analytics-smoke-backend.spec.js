// @weline-e2e-runtime fallback
// @ts-check

const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
} = require('../../framework');

const WESHOP_ANALYTICS_MODULE = 'WeShop_Analytics';

test.describe('WeShop_Analytics smoke backend', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error/i;

  const analyticsIndexRoute = buildModuleBackendRoute(
    WESHOP_ANALYTICS_MODULE,
    'analytics',
  );

  test('renders analytics management dashboard without PHP errors', async ({ page }) => {
    await gotoBackend(page, analyticsIndexRoute, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Analytics Management/i);
    await expect(body).not.toContainText(FATAL_PATTERN);

    const providerForm = page.locator('form[data-analytics-provider-form]');
    await expect(providerForm).toBeVisible();
    await expect(providerForm).toHaveAttribute('action', /analytics\/save/i);
    await expect(page.locator('#analytics-enabled')).toBeVisible();
    await expect(providerForm.locator('input[data-analytics-required]').first()).toBeVisible();

    // 文案会随版本迭代，使用“任一关键提供商字段存在”保证用例稳定。
    const providerKeyFields = providerForm.locator(
      '#google-analytics-id, #facebook-pixel-id, #tiktok-pixel-id, #bing-ads-id, #ga4-measurement-id',
    );
    await expect(providerKeyFields.first()).toBeVisible();
  });
});

