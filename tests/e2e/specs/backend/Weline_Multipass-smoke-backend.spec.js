/**
 * Weline_Multipass 多通道认证 E2E 冒烟测试
 *
 * 测试范围：
 * - 多通道认证配置：OAuth/SSO配置
 *
 * 控制器来源：app/code/Weline/Multipass/Controller/Backend/Multipass.php
 * 模板来源：app/code/Weline/Multipass/view/templates/Backend/Multipass/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Multipass, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Multipass';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Multipass 多通道认证模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'MULTIPASS-SMOKE-001' },
    '多通道认证页面能够正常加载，显示认证配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'multipass');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含认证相关内容
      const content = await body.innerText();
      expect(content).toMatch(/多通道|Multipass|认证|Auth|SSO|OAuth/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
