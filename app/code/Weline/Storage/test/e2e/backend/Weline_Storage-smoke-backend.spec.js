/**
 * Weline_Storage 存储管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 存储配置：本地存储、云存储配置
 *
 * 控制器来源：app/code/Weline/Storage/Controller/Backend/Storage.php
 * 模板来源：app/code/Weline/Storage/view/templates/Backend/Storage/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Storage, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Storage';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Storage 存储管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'STORAGE-SMOKE-001' },
    '存储配置页面能够正常加载，显示存储配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'storage');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含存储相关内容
      const content = await body.innerText();
      expect(content).toMatch(/存储|Storage|云|Cloud|文件|File/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
