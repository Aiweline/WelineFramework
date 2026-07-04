/**
 * Weline_TranslationService 翻译服务 E2E 冒烟测试
 *
 * 测试范围：
 * - 翻译服务配置：第三方翻译API配置
 *
 * 控制器来源：app/code/Weline/TranslationService/Controller/Backend/TranslationService.php
 * 模板来源：app/code/Weline/TranslationService/view/templates/Backend/TranslationService/*.phtml
 *
 * @weline-e2e-spec { module: Weline_TranslationService, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_TranslationService';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_TranslationService 翻译服务模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'TRANSLATIONSERVICE-SMOKE-001' },
    '翻译服务页面能够正常加载，显示翻译API配置',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'translation-service');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面包含翻译服务相关内容
      const content = await body.innerText();
      expect(content).toMatch(/翻译|Translation|服务|Service|API/i);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
