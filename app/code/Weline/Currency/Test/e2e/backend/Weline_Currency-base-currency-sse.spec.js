/**
 * 基准货币切换：预览对照弹窗 + SSE 进度写入
 *
 * @weline-e2e-spec { module: Weline_Currency, type: flow, layer: backend }
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

const MODULE = 'Weline_Currency';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Currency 基准币 SSE', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CURRENCY-BASE-SSE-001' },
    '切换基准币弹出汇率对照并经 SSE 完成写入',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'config'), { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const select = page.locator('#base_currency');
      await expect(select).toBeVisible({ timeout: 15000 });
      const original = await select.getAttribute('data-original-value');
      expect(original).toBeTruthy();

      const optionValues = await select.locator('option').evaluateAll((opts) =>
        opts.map((o) => o.value).filter(Boolean)
      );
      const target = optionValues.find((code) => code && code !== original) || 'EUR';
      await select.selectOption(target);

      const warn = page.locator('#base_currency_warning');
      await expect(warn).toBeVisible({ timeout: 5000 });

      await page.locator('#confirm_base_currency_change').click();
      const previewModal = page.locator('#baseCurrencyChangeModal');
      await expect(previewModal).toBeVisible({ timeout: 10000 });

      await expect(page.locator('#modal_rate_preview_body tr').first()).toBeVisible({ timeout: 25000 });
      const rowCount = await page.locator('#modal_rate_preview_body tr').count();
      expect(rowCount).toBeGreaterThan(0);

      const confirmBtn = page.locator('#confirmBaseCurrencyChangeBtn');
      await expect(confirmBtn).toBeEnabled({ timeout: 5000 });
      await confirmBtn.click();

      const progressModal = page.locator('#currencyUpdateProgressModal');
      await expect(progressModal).toBeVisible({ timeout: 10000 });
      await expect(page.locator('#update_progress_text')).toHaveText(/100%/, { timeout: 60000 });
      await expect(page.locator('#update_sse_log')).toContainText(target, { timeout: 10000 });
      await expect(page.locator('#update_fail_count')).toHaveText('0');
      await expect(select).toHaveValue(target);
      await expect(select).toHaveAttribute('data-original-value', target);
    }
  );
});
