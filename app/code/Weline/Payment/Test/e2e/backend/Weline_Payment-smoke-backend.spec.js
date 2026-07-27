/**
 * Weline_Payment：诚实 smoke + 交易列表筛选交互
 *
 * @weline-e2e-spec { module: Weline_Payment, type: flow, layer: backend }
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
  submitAndExpectParam,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Payment';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

async function openTransactionList(page) {
  const candidates = [
    buildModuleBackendRoute(MODULE, 'transaction'),
    buildModuleBackendRoute(MODULE, 'transaction/index'),
    'payment/backend/transaction',
    'payment/backend/transaction/index',
  ];
  for (const route of candidates) {
    await gotoBackend(page, route, { timeout: 60000, settleMs: 800 });
    await waitForBackendShellReady(page);
    const bodyText = await page.locator('body').innerText();
    if (!FATAL.test(bodyText) && /交易|支付|Payment|Transaction/i.test(bodyText)) {
      return;
    }
  }
}

moduleDescribe(test, MODULE, 'Weline_Payment 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-SMOKE-001' },
    '支付交易列表路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openTransactionList(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('h4, .page-title, .card-title').first()).toContainText(
        /交易|支付|Payment|Transaction/i
      );
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-FLOW-FILTER-001' },
    '交易列表：关键词 + 状态筛选后点搜索',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openTransactionList(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const form = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
      const keyword = form.locator('input[name="keyword"]');
      const status = form.locator('select[name="status"]');
      await expect(keyword).toBeVisible({ timeout: 15000 });
      await keyword.fill('PAY');
      await status.selectOption('pending');
      const req = await submitAndExpectParam(page, form, 'keyword=PAY');
      expect(decodeURIComponent(req.url())).toContain('status=pending');
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'PAYMENT-FLOW-METHOD-001' },
    '支付方式页：点击查看配置或确认空列表文案',
    async ({ page }) => {
      await loginAsAdmin(page);
      // 菜单深链携带显式 target_scope（Payment 对象授权硬前置）；不带则 403「操作授权条件不满足」。
      const methodRoutes = [
        `${buildModuleBackendRoute(MODULE, 'method')}?target_scope=default.default.default`,
        `${buildModuleBackendRoute(MODULE, 'method/index')}?target_scope=default.default.default`,
        'payment/backend/method?target_scope=default.default.default',
      ];
      let opened = false;
      for (const route of methodRoutes) {
        await gotoBackend(page, route, { timeout: 60000, settleMs: 800 });
        await waitForBackendShellReady(page);
        const bodyText = await page.locator('body').innerText();
        if (FATAL.test(bodyText)) {
          continue;
        }
        if (/操作授权条件不满足|object_scope_access_denied/i.test(bodyText)) {
          throw new Error(`Payment method page denied object scope ACL for route=${route}`);
        }
        if (/支付方式|Payment/i.test(bodyText)) {
          opened = true;
          break;
        }
      }
      expect(opened).toBeTruthy();
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('h4.page-title, .card-title, h4').first()).toContainText(/支付方式|Payment/i);

      const editLink = page.locator('a[href*="method/edit"], a.btn-primary').first();
      if ((await editLink.count()) > 0 && (await editLink.isVisible().catch(() => false))) {
        await editLink.click({ force: true });
        await page.waitForLoadState('domcontentloaded');
        await waitForBackendShellReady(page);
        await expect(page).toHaveURL(/method\/edit|method\/getEdit|code=/i);
        await expect(page.locator('body')).not.toContainText(FATAL);
        await expect(page.locator('body')).not.toContainText(/操作授权条件不满足/i);
      } else {
        await expect(page.locator('body')).toContainText(/暂无支付方式|支付方式|method/i);
      }
    }
  );
});
