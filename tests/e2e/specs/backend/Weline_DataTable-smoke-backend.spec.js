/**
 * Weline_DataTable DataTable组件 E2E 冒烟测试
 *
 * 测试范围：
 * - DataTable组件演示页面
 *
 * @weline-e2e-spec { module: Weline_DataTable, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_DataTable';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_DataTable DataTable组件模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'DATATABLE-SMOKE-001' },
    'DataTable页面能够正常加载，显示数据表格',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'data-table');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含DataTable相关内容
      const content = await body.innerText();
      expect(content).toMatch(/DataTable|数据|Table|列表|List/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
