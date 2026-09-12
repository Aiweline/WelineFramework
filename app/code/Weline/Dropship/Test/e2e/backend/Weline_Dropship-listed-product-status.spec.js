/**
 * 已刊登：状态列同时展示 sync + 本地商品 lifecycle（能否可用）
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: flow, layer: backend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
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

const MODULE = 'Weline_Dropship';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const LISTED_URL = 'dropship/backend/listing/index?tab=listed';

moduleDescribe(test, MODULE, '已刊登本地商品状态反馈', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-LISTED-PRODUCT-STATUS-001' }, '状态列含本地商品 lifecycle 徽章', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, LISTED_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-testid="dropship-panel-listed"]')).toBeVisible({ timeout: 30000 });

    const row = page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').filter({
      hasText: /DS-CJ-CJYD3153518|CJYD3153518/,
    }).first();
    const fallback = page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').first();
    const target = (await row.count()) > 0 ? row : fallback;
    const count = await page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').count();
    test.skip(count === 0, '无已刊可展开行，跳过状态列断言');

    await expect(target.locator('[data-testid="dropship-listed-status"]')).toBeVisible();
    await expect(target.locator('[data-testid="dropship-listed-status"]')).toContainText(/已刊登|已停用|待刊登/);
    await expect(target.locator('[data-testid="dropship-listed-product-status"]')).toBeVisible();
    await expect(target.locator('[data-testid="dropship-listed-product-status"]')).toContainText(/已发布|已下架|草稿|已归档|状态未知/);
    const productStatusText = (await target.locator('[data-testid="dropship-listed-product-status"]').innerText()).trim();
    expect(productStatusText).not.toMatch(/^(published|draft|disabled|archived)$/i);
  });

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-LISTED-PRODUCT-STATUS-002' }, '展开详情商品状态中文', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, LISTED_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    await expect(page.locator('[data-testid="dropship-panel-listed"]')).toBeVisible({ timeout: 30000 });

    const row = page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').filter({
      hasText: /DS-CJ-CJYD3153518|CJYD3153518/,
    }).first();
    const fallback = page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').first();
    const expandable = (await row.count()) > 0 ? row : fallback;
    const count = await page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').count();
    test.skip(count === 0, '无已刊可展开行，跳过详情状态断言');

    const listingId = await expandable.getAttribute('data-listing-id');
    const detail = page.locator(`[data-testid="dropship-listed-detail"][data-listing-id="${listingId}"]`);
    const hint = expandable.locator('[data-testid="dropship-listed-expand-hint"]');
    if (await hint.count()) {
      await hint.click();
    } else {
      await expandable.click({ position: { x: 120, y: 20 } });
    }

    await expect(detail).toBeVisible({ timeout: 10000 });
    await expect(
      detail.locator('[data-testid="dropship-listed-detail-product-status"]')
    ).toBeVisible({ timeout: 20000 });
    await expect(
      detail.locator('[data-testid="dropship-listed-detail-product-status"]')
    ).toContainText(/已发布|已下架|草稿|已归档/);
    const detailStatus = (await detail.locator('[data-testid="dropship-listed-detail-product-status"]').innerText()).trim();
    expect(detailStatus).not.toMatch(/^(published|draft|disabled|archived)$/i);
  });
});
