/**
 * Weline_CacheManager 缓存管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 缓存管理首页：缓存列表、缓存清理操作
 * - 缓存配置：缓存策略配置
 *
 * 控制器来源：app/code/Weline/CacheManager/Controller/Backend/CacheManager.php
 * 模板来源：app/code/Weline/CacheManager/view/templates/Backend/CacheManager/*.phtml
 *
 * @weline-e2e-spec { module: Weline_CacheManager, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_CacheManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_CacheManager 缓存管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'CACHE-SMOKE-001' },
    '缓存管理首页能够正常加载，显示缓存管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'cachemanager');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/缓存|Cache/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CACHE-SMOKE-002' },
    '缓存管理页面包含缓存清理或刷新按钮',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'cachemanager');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证包含清理或刷新相关按钮
      const content = await body.innerText();
      const hasCleanButton = /清理|Clean|刷新|Refresh|清除/i.test(content);
      expect(hasCleanButton).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
