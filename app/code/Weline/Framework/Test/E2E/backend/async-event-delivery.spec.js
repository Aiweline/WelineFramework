// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Framework';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

moduleDescribe(test, MODULE, 'async event delivery operations', () => {
  test.describe.configure({ retries: 0 });

  moduleCase(
    test,
    { module: MODULE, id: 'BR01-ASYNC-DELIVERY-001' },
    'authorized admin can open the scoped dead-letter console',
    async ({ page }) => {
      const pageErrors = [];
      page.on('pageerror', error => pageErrors.push(String(error && error.message ? error.message : error)));

      await gotoBackend(page, 'admin/login', { timeout: 60000, settleMs: 500 });
      const username = page.locator('input[name="username"], input[type="text"]').first();
      if (await username.isVisible({ timeout: 5000 }).catch(() => false)) {
        await username.fill(process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin');
        await page.locator('input[name="password"], input[type="password"]').first()
          .fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin');
        await Promise.all([
          page.waitForURL(url => !url.pathname.includes('/admin/login'), {
            timeout: 60000,
            waitUntil: 'commit',
          }),
          page.locator('button[type="submit"], input[type="submit"]').first().click(),
        ]);
      }
      await gotoBackend(page, 'weline_framework/backend/event-delivery', {
        timeout: 60000,
        settleMs: 1000,
      });

      const app = page.locator('#event-delivery-app');
      await expect(app).toBeVisible({ timeout: 15000 });
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      await expect(page.getByRole('heading', { name: '异步事件死信运维' })).toBeVisible();
      await expect(page.getByLabel('Delivery 摘要')).toBeVisible();
      await expect(page.locator('#ed-status')).toHaveValue('dead');
      await expect(page.locator('#ed-website_value')).toBeAttached();
      await expect(page.locator('#ed-replay-reason')).toHaveAttribute('maxlength', '500');
      expect(pageErrors, pageErrors.join('\n')).toEqual([]);
    },
  );
});
