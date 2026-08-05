/**
 * 万能商城内核计划 Browser 用例固化（顾客账户面 · 布局子集）
 *
 * 计划来源：/Users/weline/.cursor/plans/万能商城内核_f0b923cd.plan.md
 * - TEST-BROWSER-02A/02B：未登录跳转与登录页渲染。
 * - 完整 TEST-BROWSER-02（partial Group/退款/发票）见
 *   `Weline_Order/Test/e2e/frontend/plan-browser02-orders.spec.js`（已 SOLIDIFIED_E2E）。
 *
 * @weline-e2e-spec { module: Weline_Customer, type: plan, layer: frontend }
 */

const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Customer';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, '万能商城内核计划账户面 Browser 用例', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-BROWSER-02A' },
    '未登录访问账户中心安全跳转官方登录页',
    async ({ page }) => {
      const consoleErrors = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error' && !/favicon|Failed to load resource/i.test(msg.text())) {
          consoleErrors.push(msg.text());
        }
      });

      await gotoFrontend(page, '/customer/account');
      await expect(page).toHaveURL(/customer\/account\/login/, { timeout: 15000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
      await expect(page.locator('form').first()).toBeVisible();
      expect(consoleErrors, `console error: ${consoleErrors.join(' | ')}`).toHaveLength(0);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-BROWSER-02B' },
    '登录页表单渲染完整且无致命错误',
    async ({ page }) => {
      await gotoFrontend(page, '/customer/account/login');
      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).not.toContainText(FATAL_PATTERN);
      const html = await page.content();
      expect(
        /type="password"|password/i.test(html),
        '登录页必须包含密码输入要素'
      ).toBeTruthy();
    }
  );
});
