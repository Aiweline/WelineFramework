/**
 * Weline_Indexer 索引管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 索引管理：搜索索引列表、索引状态
 *
 * 控制器来源：app/code/Weline/Indexer/Controller/Backend/Indexer.php
 * 模板来源：app/code/Weline/Indexer/view/templates/Backend/Indexer/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Indexer, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Indexer';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Indexer 索引管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'INDEXER-SMOKE-001' },
    '索引管理页面能够正常加载，显示索引列表',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'indexer');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含索引相关内容
      const content = await body.innerText();
      expect(content).toMatch(/索引|Indexer|Index|Search/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
