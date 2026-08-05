/**
 * Weline_Acl：诚实 smoke + 资源搜索 / IP 白名单交互 flow
 *
 * @weline-e2e-spec { module: Weline_Acl, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  openBackendMenuBySource,
  waitForBackendShellReady,
  submitAndExpectParam,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Acl';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Acl 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ACL-SMOKE-001' },
    'ACL 资源列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, 'Weline_Acl::acl_source', {
        urlIncludes: '/acl/backend/acl',
        pageAnchor: '[data-testid="acl-resource-module-management"]',
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('#acl-list-filter-form, .weline-acl-toolbar, .card').first()).toBeVisible({ timeout: 15000 });
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ACL-FLOW-SEARCH-001' },
    'ACL 资源列表：填搜索词并提交',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, 'Weline_Acl::acl_source', {
        urlIncludes: '/acl/backend/acl',
        pageAnchor: '[data-testid="acl-resource-module-management"]',
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const form = page.locator('#acl-list-filter-form');
      const input = form.locator('input[name="search"]');
      await expect(input).toBeVisible({ timeout: 15000 });
      await input.fill('dashboard');
      const req = await submitAndExpectParam(page, form, 'search=dashboard');
      expect(req).toBeTruthy();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ACL-FLOW-IP-001' },
    'IP 白名单：搜索框交互并可见添加按钮',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, 'Weline_Acl::ip_whitelist', {
        urlIncludes: '/acl/backend/ip-whitelist',
        pageAnchor: '[data-testid="acl-ip-whitelist-management"]',
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-ip-whitelist-action="add"], input[name="keyword"]').first()).toBeVisible({ timeout: 15000 });

      const form = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
      const keyword = form.locator('input[name="keyword"]');
      await expect(keyword).toBeVisible({ timeout: 15000 });
      await keyword.fill('127.0.0.1');
      const req = await submitAndExpectParam(page, form, 'keyword=127.0.0.1');
      expect(req).toBeTruthy();
    }
  );
});
