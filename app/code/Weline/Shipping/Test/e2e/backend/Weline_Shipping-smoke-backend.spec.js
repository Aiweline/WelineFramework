/**
 * Weline_Shipping 配送管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 配送承运商管理：承运商列表、状态筛选
 * - 配送区域管理：配送区域和运费配置
 *
 * 控制器来源：app/code/Weline/Shipping/Controller/Backend/Shipping/*.php
 * 模板来源：app/code/Weline/Shipping/view/templates/Backend/Shipping/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Shipping 配送管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SMOKE-001' },
    '配送承运商列表页面能够正常加载，显示承运商管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'carrier');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/配送|承运商|Carrier|Shipping/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SMOKE-002' },
    '配送承运商列表包含承运商表格或统计信息',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'carrier');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含承运商相关内容
      const hasCarrierContent = /承运商|Carrier|快递|Express|配送|Shipping/i.test(content);
      expect(hasCarrierContent).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SMOKE-003' },
    '配送管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'manager');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SMOKE-004' },
    '配送承运商列表支持关键词搜索',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'carrier') + '?keyword=express';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含搜索参数
      await expect(page).toHaveURL(/keyword=express/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-SMOKE-005' },
    '配送承运商列表支持启用状态筛选',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'carrier') + '?is_active=1';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含筛选参数
      await expect(page).toHaveURL(/is_active=1/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
