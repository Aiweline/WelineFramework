/**
 * Weline_WarmCache 缓存预热 E2E 冒烟测试
 *
 * 测试范围：
 * - 缓存预热配置：预热任务配置
 *
 * 控制器来源：app/code/Weline/WarmCache/Controller/Backend/WarmCache.php
 * 模板来源：app/code/Weline/WarmCache/view/templates/Backend/WarmCache/*.phtml
 *
 * @weline-e2e-spec { module: Weline_WarmCache, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_WarmCache';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_WarmCache 缓存预热模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'WARMCACHE-SMOKE-001' },
    '缓存预热页面能够正常加载，显示预热任务',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'warm-cache');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含预热相关内容
      const content = await body.innerText();
      expect(content).toMatch(/预热|Warm|缓存|Cache/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
