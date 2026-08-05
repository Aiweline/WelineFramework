// @weline-e2e-runtime wls
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_DeveloperWorkspace';

moduleDescribe(test, MODULE, 'w_changed async acceptance console', () => {
  test.describe.configure({ retries: 0 });

  moduleCase(
    test,
    { module: MODULE, id: 'ASYNC-WEBUI-001' },
    'resource change crosses Outbox Delivery Queue and async Observer',
    async ({ page }) => {
      const errors = [];
      page.on('pageerror', error => errors.push(String(error.message || error)));

      await gotoBackend(page, 'admin/login', { timeout: 60000, settleMs: 500 });
      const username = page.locator('input[name="username"], input[type="text"]').first();
      if (await username.isVisible({ timeout: 5000 }).catch(() => false)) {
        await username.fill(process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin');
        await page.locator('input[name="password"], input[type="password"]').first()
          .fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin');
        await Promise.all([
          page.waitForURL(url => !url.pathname.includes('/admin/login'), { timeout: 60000, waitUntil: 'commit' }),
          page.locator('button[type="submit"], input[type="submit"]').first().click(),
        ]);
      }

      await gotoBackend(page, 'dev/tool/admin/sandbox', { timeout: 60000, settleMs: 1000 });
      await expect(page.getByRole('heading', { name: '异步资源变更验收台' })).toBeVisible();
      // The backend uses a fixed top bar and a transient full-page loader. In
      // headed runs Playwright may scroll the button underneath that chrome,
      // even though the control is visible and enabled to the user.
      await page.locator('#probe-trigger').click({ force: true });
      await expect(page.locator('#probe-proof')).toContainText('边界已证明', { timeout: 30000 });
      await expect(page.locator('#probe-delivery')).not.toContainText('succeeded');

      await page.locator('#probe-advance').click({ force: true });
      await expect(page.locator('#probe-proof')).toContainText('通过', { timeout: 60000 });
      await expect(page.locator('#probe-delivery')).toContainText('succeeded');
      await expect(page.locator('#probe-delivery')).toContainText('queue #');
      expect(errors, errors.join('\n')).toEqual([]);
    },
  );
});
