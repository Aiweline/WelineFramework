/**
 * Weline_Framework：诚实 smoke 指向测试管理；权威 flow 见 Framework-test-management.spec.js
 *
 * @weline-e2e-spec { module: Weline_Framework, type: smoke, layer: backend }
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

const MODULE = 'Weline_Framework';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Framework 测试管理入口', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'FRAMEWORK-SMOKE-TEST-001' },
    '测试管理页路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'test'), {
        timeout: 60000,
        settleMs: 1000,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('#framework-test-app')).toBeVisible({ timeout: 20000 });
      await expect(page.locator('#ft-ui-enabled')).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FRAMEWORK-FLOW-UI-TOGGLE-001' },
    '测试管理：切换 UI 测试开关并看到保存反馈或保持勾选态',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'test'), {
        timeout: 60000,
        settleMs: 1000,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('#framework-test-app')).toBeVisible({ timeout: 20000 });

      const toggle = page.locator('#ft-ui-enabled');
      await expect(toggle).toBeVisible();
      const before = await toggle.isChecked();

      const savePending = page
        .waitForResponse(
          (res) => {
            const body = res.request().postData() || '';
            const url = res.url();
            return (
              res.ok() &&
              (/setUiEnabled/i.test(url + body) || /"operation"\s*:\s*"setUiEnabled"/.test(body))
            );
          },
          { timeout: 20000 }
        )
        .catch(() => null);

      await toggle.click({ force: true });
      const hit = await savePending;
      const after = await toggle.isChecked();
      expect(after).toBe(!before);

      const status = page.locator('#ft-status');
      if (hit) {
        await expect(status).toBeVisible({ timeout: 10000 });
        await expect(status).toContainText(/已保存|UI 测试/i);
      }

      // 还原，避免污染环境
      await toggle.click({ force: true });
    }
  );
});
