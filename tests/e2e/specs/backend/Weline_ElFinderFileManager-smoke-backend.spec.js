/**
 * Weline_ElFinderFileManager elFinder文件管理器 E2E 冒烟测试
 *
 * 测试范围：
 * - elFinder文件管理器集成
 *
 * @weline-e2e-spec { module: Weline_ElFinderFileManager, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_ElFinderFileManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_ElFinderFileManager elFinder文件管理器模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'ELFINDER-SMOKE-001' },
    'elFinder文件管理器能够正常加载，显示文件管理界面',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'elfinder');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含elFinder相关内容
      const content = await body.innerText();
      expect(content).toMatch(/elfinder|文件|File|管理器|Manager/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
