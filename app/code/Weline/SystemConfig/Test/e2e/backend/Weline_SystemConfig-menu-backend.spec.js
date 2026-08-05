/**
 * R4.3 统一配置中心：真实后台侧栏菜单入口验收。
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: flow, layer: backend }
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

const MODULE = 'Weline_SystemConfig';
const SOURCE = 'Weline_SystemConfig::config_center';

moduleDescribe(test, MODULE, 'R4.3 统一配置中心菜单入口', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-SYSTEMCONFIG-MENU-001' },
    '统一配置中心入口唯一且通过真实菜单点击打开',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await waitForBackendShellReady(page);
      const snapshot = await collectBackendMenuSnapshot(page);
      const rows = snapshot.filter((row) => row.sourceId === SOURCE);
      expect(rows, SOURCE).toHaveLength(1);
      expect(rows[0].parentSource).toBe('Weline_Backend::system_config_group');
      expect(rows[0].href.trim()).not.toBe('');
      expect(rows[0].href).not.toMatch(/^(?:#|javascript:)/i);

      const guards = installBackendBrowserGuards(page);
      await openBackendMenuBySource(page, SOURCE, {
        urlIncludes: '/weline_systemconfig/backend/config',
        pageAnchor: '[data-testid="system-config-management"]',
      });
      guards.assertClean();
    }
  );
});
