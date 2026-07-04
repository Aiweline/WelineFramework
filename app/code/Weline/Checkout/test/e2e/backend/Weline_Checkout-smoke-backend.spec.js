/**
 * Weline_Checkout 结账管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 结账配置页面：结账流程配置
 * - 结算规则管理
 *
 * 控制器来源：app/code/WeShop/Checkout/Controller/Backend/Checkout.php
 * 模板来源：app/code/WeShop/Checkout/view/templates/Backend/Checkout/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Checkout 结账管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SMOKE-001' },
    '结账配置页面能够正常加载，显示结账管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'checkout');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/结账|Checkout/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CHECKOUT-SMOKE-002' },
    '结账配置页面包含配置表单',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'checkout');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证包含表单元素
      const form = page.locator('form');
      await expect(form).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
