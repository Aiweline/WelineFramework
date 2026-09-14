/**
 * Wholesale config · default tier policy + loss guard surfaces.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: backend }
 */

const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const CONFIG = 'b2b/backend/config';

moduleDescribe(test, MODULE, '批发配置 · 默认模板与护栏', () => {
  test.setTimeout(240000);

  moduleCase(test, { module: MODULE, id: 'CK-B2B-DEFAULT-POLICY-CFG-001' }, '配置页露出最大折扣默认20%与13行表及不回刷说明', async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
    await gotoBackend(page, CONFIG + '?website_id=0', {
      waitUntil: 'domcontentloaded',
      useProxy: false,
      timeout: 90000,
    });
    await waitForBackendShellReady(page);

    await expect(page.getByTestId('b2b-selling-mode-config')).toBeVisible({ timeout: 60000 });
    await expect(page.getByTestId('b2b-default-policy-no-rebrush')).toContainText(/不回刷|价目表|历史订单/);

    const maxDiscount = page.locator(
      '[data-testid="b2b-max-discount-bps"], [data-config-key="b2b_max_discount_bps"] input, [data-config-key="b2b_max_discount_bps"] [data-w-config-embed-control], input[name*="b2b_max_discount_bps"]'
    ).first();
    await expect(maxDiscount).toBeVisible({ timeout: 30000 });
    const maxVal = await maxDiscount.inputValue().catch(() => '');
    if (maxVal !== '') {
      expect(['2000', '2,000']).toContain(maxVal.replace(/,/g, ''));
    } else {
      await expect(page.locator('body')).toContainText(/2000|20%/);
    }

    const table = page.getByTestId('b2b-default-tier-policy-table');
    await expect(table).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-testid^="b2b-default-tier-row-"]')).toHaveCount(13, { timeout: 30000 });
    await expect(page.getByTestId('b2b-default-tier-discount-percent-0')).toBeVisible();
    await expect(page.getByTestId('b2b-default-tier-policy-heading')).toBeVisible();

    // Raw JSON embed must stay persisted but not visible to operators.
    const jsonRow = page.locator('[data-testid="b2b-selling-mode-config"] [data-config-key="b2b_default_tier_policy_json"]');
    await expect(jsonRow).toHaveCount(1);
    await expect.poll(async () => jsonRow.getAttribute('data-b2b-json-hidden')).toBe('1');
    const jsonBox = await jsonRow.boundingBox();
    expect(!jsonBox || jsonBox.width <= 1 || jsonBox.height <= 1, 'JSON 字段行应被裁剪隐藏').toBeTruthy();
    await expect(page.locator('textarea[data-testid="b2b-default-tier-policy-json"]')).toBeHidden();

    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    const residual = (guards.failures || []).filter((msg) => !/query-bin|403/i.test(String(msg)));
    expect(residual, '后台页面存在未处理浏览器错误').toEqual([]);
  });
});
