/**
 * 免邮规则国际种子模板：列表完整通路（系统徽章 + 种子不可删）
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const PROXY_FAIL = /upstream_request_failed|ECONNRESET/i;
const FREE_RULE_PATH = 'shipping/backend/freeshippingrule/index?target_scope=default.default.default&website_code=default&scope_kind=website';
const FREE_RULE_PATH_CONDITIONS = `${FREE_RULE_PATH}&section=conditions`;
const FREE_RULE_PATH_RULES = `${FREE_RULE_PATH}&section=rules`;
const FREE_RULE_PATH_CREATE = `${FREE_RULE_PATH}&section=create`;

async function openFreeRulePage(page) {
  let lastBody = '';
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await gotoBackend(page, FREE_RULE_PATH, { timeout: 60000, settleMs: 800 });
    await waitForBackendShellReady(page);
    lastBody = await page.locator('body').innerText().catch(() => '');
    if (!PROXY_FAIL.test(lastBody) && !FATAL.test(lastBody)) {
      return lastBody;
    }
    await page.waitForTimeout(2000 * attempt);
  }
  return lastBody;
}

moduleDescribe(test, MODULE, '免邮规则种子模板通路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-FREE-SEED-001' },
    '完整通路：后台免邮规则列表展示系统种子且不可删除',
    async ({ page }) => {
      await loginAsAdmin(page);
      const bodyText = await openFreeRulePage(page);
      expect(PROXY_FAIL.test(bodyText), `代理/上游失败：${bodyText.slice(0, 240)}`).toBeFalsy();
      expect(FATAL.test(bodyText), `页面出现运行期错误：${bodyText.slice(0, 200)}`).toBeFalsy();

      await expect(page.getByTestId('shipping-free-rule-table')).toBeVisible({ timeout: 30000 });
      await expect(page.getByTestId('free-shipping-origin-seed').first()).toBeVisible();
      await expect(page.getByTestId('shipping-free-rule-page-tabs')).toBeVisible();
      await expect(page.getByTestId('shipping-free-condition-type-card')).toHaveCount(0);
      await expect(page.getByTestId('shipping-free-rule-create-form')).toHaveCount(0);

      const conditionCell = page.getByTestId('shipping-free-rule-condition-type').first();
      await expect(conditionCell).toBeVisible();
      await expect(conditionCell).not.toHaveText(/^\s*order_amount\s*$/);
      await expect(conditionCell).toContainText(/订单金额|Order amount|金额/i);

      await gotoBackend(page, FREE_RULE_PATH_CONDITIONS, { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.getByTestId('shipping-free-condition-type-card')).toBeVisible({ timeout: 30000 });
      await expect(page.getByTestId('shipping-free-condition-type-name').first().locator('.w-local-translation__wrap, .w-local-translation__trigger').first()).toBeVisible();
      await expect(page.getByTestId('shipping-free-rule-table')).toHaveCount(0);

      await gotoBackend(page, FREE_RULE_PATH_RULES, { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.getByTestId('shipping-free-rule-table')).toBeVisible({ timeout: 30000 });
      await expect(page.getByTestId('shipping-free-condition-type-card')).toHaveCount(0);

      const seedRow = page.locator('[data-testid="shipping-free-rule-row"][data-rule-code="SEED_FREE_99"]').first();
      await expect(seedRow).toBeVisible();
      await expect(seedRow.getByTestId('free-shipping-origin-seed')).toBeVisible();
      await expect(seedRow.getByTestId('shipping-free-rule-delete')).toHaveCount(0);
      await expect(seedRow.getByText(/系统种子不可删除/)).toBeVisible();

      for (const code of ['SEED_FREE_49', 'SEED_FREE_149', 'SEED_FREE_199', 'SEED_FREE_299', 'SEED_FREE_499']) {
        await expect(page.locator(`[data-testid="shipping-free-rule-row"][data-rule-code="${code}"]`).first()).toBeVisible();
      }
    },
  );
});
