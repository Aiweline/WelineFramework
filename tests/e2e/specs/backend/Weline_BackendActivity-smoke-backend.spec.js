/**
 * Weline_BackendActivity 后台活动记录 E2E 冒烟测试
 *
 * 测试范围：
 * - 活动日志：后台用户操作记录
 *
 * 控制器来源：app/code/Weline/BackendActivity/Controller/Backend/BackendActivity.php
 * 模板来源：app/code/Weline/BackendActivity/view/templates/Backend/BackendActivity/*.phtml
 *
 * @weline-e2e-spec { module: Weline_BackendActivity, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_BackendActivity';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_BackendActivity 后台活动记录模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'BACKENDACTIVITY-SMOKE-001' },
    '活动记录页面能够正常加载，显示活动日志',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'backend-activity');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含活动相关内容
      const content = await body.innerText();
      expect(content).toMatch(/活动|Activity|操作|Action|日志|Log/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
