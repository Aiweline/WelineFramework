/**
 * Weline_PlatformAppStore 平台应用商店 E2E 冒烟测试
 *
 * 测试范围：
 * - 应用商店首页：应用列表、应用分类
 *
 * 控制器来源：app/code/Weline/PlatformAppStore/Controller/Backend/PlatformAppStore.php
 * 模板来源：app/code/Weline/PlatformAppStore/view/templates/Backend/PlatformAppStore/*.phtml
 *
 * @weline-e2e-spec { module: Weline_PlatformAppStore, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_PlatformAppStore';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_PlatformAppStore 平台应用商店模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'PLATFORMAPPSTORE-SMOKE-001' },
    '应用商店页面能够正常加载，显示应用列表',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'platform-app-store');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含应用商店相关内容
      const content = await body.innerText();
      expect(content).toMatch(/应用|App|商店|Store|平台|Platform/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
