/**
 * R4.3 ACL 控制面：真实后台侧栏菜单入口验收。
 *
 * @weline-e2e-spec { module: Weline_Acl, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  collectBackendMenuSnapshot,
  installBackendBrowserGuards,
  openBackendMenuBySource,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Acl';
const CAPABILITIES = [
  ['Weline_Acl::acl_role', 'Weline_Acl::acl', '/acl/backend/acl/role', '[data-testid="acl-role-management"]'],
  ['Weline_Acl::acl_source', 'Weline_Acl::acl', '/acl/backend/acl', '[data-testid="acl-resource-module-management"]'],
  ['Weline_Acl::acl_source_by_tag', 'Weline_Acl::acl', '/acl/backend/acl/by-tag', '[data-testid="acl-resource-tag-management"]'],
  ['Weline_Acl::acl_tag', 'Weline_Acl::acl', '/acl/backend/acl/tag', '[data-testid="acl-tag-management"]'],
  ['Weline_Acl::security_log', 'Weline_Acl::security_settings', '/acl/backend/security-log', '[data-testid="acl-security-log-management"]'],
  ['Weline_Acl::ip_whitelist', 'Weline_Acl::security_settings', '/acl/backend/ip-whitelist', '[data-testid="acl-ip-whitelist-management"]'],
].map(([sourceId, parentSource, urlIncludes, pageAnchor]) => ({
  sourceId,
  parentSource,
  urlIncludes,
  pageAnchor,
}));

moduleDescribe(test, MODULE, 'R4.3 ACL 控制面菜单入口', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-MENU-001' },
    '权限与安全六个管理工作台在真实侧栏中各出现一次',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await waitForBackendShellReady(page);
      const snapshot = await collectBackendMenuSnapshot(page);

      for (const capability of CAPABILITIES) {
        const rows = snapshot.filter((row) => row.sourceId === capability.sourceId);
        expect(rows, capability.sourceId).toHaveLength(1);
        expect(rows[0].parentSource, capability.sourceId).toBe(capability.parentSource);
        expect(rows[0].href.trim(), capability.sourceId).not.toBe('');
        expect(rows[0].href, capability.sourceId).not.toMatch(/^(?:#|javascript:)/i);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-MENU-002' },
    '逐项点击权限与安全菜单并验证真实管理页面锚点',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      const guards = installBackendBrowserGuards(page);

      for (const capability of CAPABILITIES) {
        await openBackendMenuBySource(page, capability.sourceId, capability);
      }

      guards.assertClean();
    }
  );
});
