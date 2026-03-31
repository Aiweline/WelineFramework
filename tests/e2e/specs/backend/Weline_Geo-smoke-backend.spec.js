/**
 * Weline_Geo 地理区域管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 地理数据管理：国家、省份、城市数据
 *
 * 控制器来源：app/code/Weline/Geo/Controller/Backend/Geo.php
 * 模板来源：app/code/Weline/Geo/view/templates/Backend/Geo/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Geo, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Geo';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Geo 地理区域管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'GEO-SMOKE-001' },
    '地理管理页面能够正常加载，显示地理数据',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'geo');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含地理相关内容
      const content = await body.innerText();
      expect(content).toMatch(/地理|Geo|国家|Country|区域/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
