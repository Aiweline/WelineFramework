/**
 * Weline_I18n 国际化管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 国家管理：国家列表
 * - 词典管理：翻译词典
 * - 本地化配置：语言区域设置
 * - 词汇翻译：翻译词汇管理
 *
 * 控制器来源：app/code/Weline/I18n/Controller/Backend/I18n/*.php
 * 模板来源：app/code/Weline/I18n/view/templates/Backend/I18n/*.phtml
 *
 * @weline-e2e-spec { module: Weline_I18n, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_I18n';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_I18n 国际化管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'I18N-SMOKE-001' },
    '国家管理页面能够正常加载，显示国家列表',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'i18n/countries');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含国家相关内容
      const content = await body.innerText();
      expect(content).toMatch(/国家|Country|I18n|International/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'I18N-SMOKE-002' },
    '词典管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'i18n/dictionary');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'I18N-SMOKE-003' },
    '本地化配置页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'i18n/localization');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
