/**
 * Weline_TwoFactorAuth 两步认证 E2E 冒烟测试
 *
 * 测试范围：
 * - 两步认证配置：TOTP/短信认证配置
 *
 * 控制器来源：app/code/Weline/TwoFactorAuth/Controller/Backend/TwoFactorAuth.php
 * 模板来源：app/code/Weline/TwoFactorAuth/view/templates/Backend/TwoFactorAuth/*.phtml
 *
 * @weline-e2e-spec { module: Weline_TwoFactorAuth, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_TwoFactorAuth';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_TwoFactorAuth 两步认证模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: '2FA-SMOKE-001' },
    '两步认证页面能够正常加载，显示认证配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'two-factor-auth');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含认证相关内容
      const content = await body.innerText();
      expect(content).toMatch(/两步|2FA|认证|Auth|TOTP|两步验证/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
