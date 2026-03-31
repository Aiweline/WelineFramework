/**
 * Weline_Saas SaaS管理 E2E 冒烟测试
 *
 * 测试范围：
 * - SaaS租户管理：租户列表、订阅管理
 *
 * 控制器来源：app/code/Weline/Saas/Controller/Backend/Saas.php
 * 模板来源：app/code/Weline/Saas/view/templates/Backend/Saas/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Saas, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Saas';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Saas SaaS管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'SAAS-SMOKE-001' },
    'SaaS管理页面能够正常加载，显示租户管理',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'saas');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含SaaS相关内容
      const content = await body.innerText();
      expect(content).toMatch(/SaaS|租户|Tenant|订阅|Subscription/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
