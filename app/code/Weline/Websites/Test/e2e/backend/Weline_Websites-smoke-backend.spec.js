/**
 * Weline_Websites：诚实 smoke + 站点列表搜索交互（深度新增见 website-add.spec.js）
 *
 * @weline-e2e-spec { module: Weline_Websites, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
  submitForm,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const LIST_ROUTE = 'websites/admin/website';

moduleDescribe(test, MODULE, 'Weline_Websites 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'WEBSITES-SMOKE-001' },
    '网站管理列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, LIST_ROUTE, { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/网站|Website|站点|Site/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'WEBSITES-FLOW-SEARCH-001' },
    '网站列表：搜索框 fill 后触发筛选',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, LIST_ROUTE, { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const search = page.locator('#search-input, input[name="search"]').first();
      await expect(search).toBeVisible({ timeout: 15000 });
      await search.fill('default');
      const form = page.locator('form').filter({ has: search }).first();
      if ((await form.count()) > 0) {
        await submitForm(page, form);
        await page.waitForLoadState('domcontentloaded');
      } else {
        await search.press('Enter');
        await page.waitForTimeout(800);
      }
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).toContainText(/网站|Website|站点|default|暂无|代码/i);
      await expect(page.locator('#search-input')).toHaveValue('default');
    }
  );
});
