/**
 * Weline_SystemConfig：仅 1 条诚实路由 smoke。
 *
 * 配置中心真实交互 flow（TargetScope 四层可见/切换、CSRF/Origin 写保护）由权威用例覆盖：
 *   app/code/Weline/SystemConfig/Test/e2e/backend/plan-p1c-sec07-config-center.spec.js
 *   （TEST-P1C-01 / TEST-SEC-07），本文件不重复造脆弱 flow。
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: smoke, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SystemConfig';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_SystemConfig 路由 smoke', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SYSCONFIG-SMOKE-001' },
    '统一配置中心路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'config'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/统一配置中心|System Config|配置中心/i);
      // TargetScope 选择器存在于 DOM（可见性/切换由权威 flow plan-p1c-sec07 断言）
      await expect(page.locator('#wsc-website-code')).toBeAttached({ timeout: 20000 });
    }
  );
});
