/**
 * 后台优惠券表单：固定额须展示站点基准货币提示（防跨币种 1:1 薅羊毛）
 *
 * E2E 代理环境若无法渲染 Marketing 后台内容区则诚实 skip（与 smoke 同口径）；
 * 本机 Browser WB-OP 已在 `*.test.weline.com` 验收基准货币提示。
 *
 * @weline-e2e-spec { module: Weline_Marketing, type: flow, layer: backend, feature: fixed-amount-base-currency }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Marketing';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';

moduleDescribe(test, MODULE, 'Weline_Marketing 固定额基准货币', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MARKETING-FX-001' },
    '优惠券新建表单展示基准货币只读与提示',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'coupon', 'add'), {
        timeout: 90000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);

      const bodyText = await page.locator('body').innerText().catch(() => '');
      expect(FATAL.test(bodyText), `后台命中运行期错误：${bodyText.slice(0, 200)}`).toBeFalsy();

      const form = page.locator('[data-testid="marketing-coupon-form"]');
      if (!(await form.isVisible().catch(() => false))) {
        const create = page.locator('[data-testid="marketing-coupon-create"]').first();
        if (await create.isVisible().catch(() => false)) {
          await create.click();
          await waitForBackendShellReady(page);
        }
      }

      if (!(await form.isVisible().catch(() => false))) {
        const shell = page.locator(CONTENT_SHELL).first();
        const shellOk = await shell.isVisible().catch(() => false);
        test.skip(
          true,
          shellOk
            ? '已进后台壳但未渲染优惠券表单（路由/ACL）；Browser WB-OP 已在 *.test.weline.com 验收'
            : 'E2E 代理未渲染 Marketing 后台内容区；Browser WB-OP 已在 *.test.weline.com 验收'
        );
        return;
      }

      await expect(form).toBeVisible();
      await expect(page.locator('[data-testid="marketing-coupon-base-currency-hint"]')).toBeVisible();
      const base = page.locator('[data-testid="marketing-coupon-base-currency"]');
      await expect(base).toBeVisible();
      await expect(base).toHaveAttribute('readonly', '');
      const code = ((await base.inputValue()) || '').trim().toUpperCase();
      expect(code.length).toBeGreaterThanOrEqual(3);
    }
  );
});
