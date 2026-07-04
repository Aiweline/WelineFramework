/**
 * Weline_Smtp 邮件发送管理 E2E 冒烟测试
 *
 * 测试范围：
 * - SMTP配置：邮件服务器配置、发送测试
 *
 * 控制器来源：app/code/Weline/Smtp/Controller/Backend/Smtp.php
 * 模板来源：app/code/Weline/Smtp/view/templates/Backend/Smtp/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Smtp, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Smtp';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Smtp 邮件发送管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'SMTP-SMOKE-001' },
    'SMTP配置页面能够正常加载，显示邮件配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'smtp');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含SMTP相关内容
      const content = await body.innerText();
      expect(content).toMatch(/SMTP|邮件|Email|服务器|Server/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SMTP-SMOKE-002' },
    'SMTP配置页面包含配置表单',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'smtp');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证包含表单元素
      const inputs = page.locator('input, select, textarea');
      expect(await inputs.count()).toBeGreaterThan(0);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
