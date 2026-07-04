/**
 * Weline_Sticker Sticker贴纸 E2E 冒烟测试
 *
 * 测试范围：
 * - Sticker贴纸管理
 *
 * @weline-e2e-spec { module: Weline_Sticker, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Sticker';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Sticker Sticker贴纸模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'STICKER-SMOKE-001' },
    'Sticker页面能够正常加载，无PHP致命错误',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'sticker');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
