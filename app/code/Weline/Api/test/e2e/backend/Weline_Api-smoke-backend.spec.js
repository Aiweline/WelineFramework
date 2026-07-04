/**
 * Weline_Api API管理 E2E 冒烟测试
 *
 * 测试范围：
 * - API模块后台入口：API配置页面
 * - API端点管理（如有独立页面）
 *
 * Weline_Api 模块主要提供 REST API 功能，部分配置通过后台管理
 *
 * @weline-e2e-spec { module: Weline_Api, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Api';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Api API管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'API-SMOKE-001' },
    'API管理页面能够正常加载，显示API相关内容',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'api');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含API相关内容
      const content = await body.innerText();
      expect(content).toMatch(/API|接口|REST/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'API-SMOKE-002' },
    'API后台入口页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'api');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
