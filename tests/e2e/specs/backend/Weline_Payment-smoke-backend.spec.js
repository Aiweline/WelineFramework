/**
 * Weline_Payment 支付管理 E2E 冒烟测试
 *
 * 测试范围：
 * - 支付交易列表：交易记录查询、状态筛选
 * - 支付方式管理：支付方式配置
 *
 * 控制器来源：app/code/Weline/Payment/Controller/Backend/Payment/*.php
 * 模板来源：app/code/Weline/Payment/view/templates/Backend/Payment/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Payment, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Payment';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Payment 支付管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-SMOKE-001' },
    '支付交易列表页面能够正常加载，显示交易管理标题',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'transaction');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/交易|支付|Payment|Transaction/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-SMOKE-002' },
    '支付交易列表包含交易表格或统计信息',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'transaction');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      const content = await body.innerText();
      // 验证包含交易相关内容
      const hasTransactionContent = /交易|Transaction|支付|Payment|订单|Order|金额|Amount/i.test(content);
      expect(hasTransactionContent).toBe(true);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-SMOKE-003' },
    '支付方式管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'method');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-SMOKE-004' },
    '支付交易列表支持关键词搜索',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'transaction') + '?keyword=TEST';
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证 URL 包含搜索参数
      await expect(page).toHaveURL(/keyword=TEST/);

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
