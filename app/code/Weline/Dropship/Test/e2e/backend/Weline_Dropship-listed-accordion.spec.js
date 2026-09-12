/**
 * 已刊登：点击行先展开手风琴，再异步加载本地规格/售价
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

moduleDescribe(test, MODULE, '已刊列表手风琴本地详情', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-LISTED-ACCORDION-001' }, '点击已刊行：先展开再加载本地规格', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, LISTED_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-testid="dropship-panel-listed"]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-dropship-listed-accordion="1"]')).toBeVisible();

    const expandable = page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').first();
    const count = await page.locator('[data-testid="dropship-listed-row"][data-listed-expandable="1"]').count();
    test.skip(count === 0, '无已刊可展开行，跳过手风琴交互');

    const listingId = await expandable.getAttribute('data-listing-id');
    const detail = page.locator(`[data-testid="dropship-listed-detail"][data-listing-id="${listingId}"]`);

    await expect(detail).toBeHidden();
    const hint = expandable.locator('[data-testid="dropship-listed-expand-hint"]');
    if (await hint.count()) {
      await hint.click();
    } else {
      await expandable.click({ position: { x: 120, y: 20 } });
    }
    await expect(expandable).toHaveAttribute('aria-expanded', 'true');
    await expect(detail).toBeVisible({ timeout: 10000 });
    await expect(detail).toHaveClass(/is-open/);
    if (await hint.count()) {
      await expect(hint).toHaveAttribute('aria-expanded', 'true');
      await expect(hint.locator('[data-listed-expand-label]')).toContainText(/收起/);
    }

    await expect(
      detail.locator('[data-testid="dropship-listed-detail-loading"], [data-testid="dropship-listed-detail-sale"], [data-testid="dropship-listed-variants"], [data-testid="dropship-listed-detail-empty"], [data-testid="dropship-listed-detail-error"]').first()
    ).toBeVisible({ timeout: 15000 });

    await expect(
      detail.locator('[data-testid="dropship-listed-detail-sale"], [data-testid="dropship-listed-variants"], [data-testid="dropship-listed-detail-empty"]').first()
    ).toBeVisible({ timeout: 20000 });

    // 展开后详情应进入视口（避免折线下「看起来没展开」）
    await expect(detail).toBeInViewport({ ratio: 0.05 });

    const variantTable = detail.locator('[data-testid="dropship-listed-variants"]');
    const pricing = detail.locator('[data-testid="dropship-listed-detail-pricing"]');
    if (await pricing.count()) {
      await expect(pricing.locator('[data-testid="dropship-listed-detail-origin"]')).toBeVisible();
      await expect(pricing.locator('[data-testid="dropship-listed-detail-sale"]')).toBeVisible();
      await expect(pricing.locator('[data-testid="dropship-listed-detail-uplift"]')).toBeVisible();
      const saleBlock = pricing.locator('[data-testid="dropship-listed-detail-sale"]');
      const saleText = (await saleBlock.innerText()).trim();
      // Cross-currency: local sale + muted ≈ USD compare; same-currency: no compare line.
      if (await saleBlock.locator('[data-testid="dropship-listed-sale-compare"]').count()) {
        await expect(saleBlock.locator('[data-testid="dropship-listed-sale-compare"]')).toContainText(/≈/);
        expect(saleText).toMatch(/US\$|USD|\$/);
      }
      await expect(detail.locator('[data-testid="dropship-listed-detail-product"]')).toBeVisible();
      await expect(detail.locator('[data-testid="dropship-listed-detail-economics"], [data-testid="dropship-listed-detail-origin-change"]').first()).toBeVisible();
    }
    if (await variantTable.count()) {
      const offerCell = variantTable.locator('[data-testid="dropship-listed-variant-offer"]').first();
      const priceCell = variantTable.locator('[data-testid="dropship-listed-variant-price"]').first();
      const upliftCell = variantTable.locator('[data-testid="dropship-listed-variant-uplift"]').first();
      const labelCell = variantTable.locator('[data-testid="dropship-listed-variant-label"]').first();
      if (await offerCell.count()) {
        const offerText = (await offerCell.innerText()).trim();
        expect(offerText).not.toMatch(/^DS-|^SKU-/i);
        expect(offerText).toMatch(/[\d.,]/);
        await expect(priceCell).toBeVisible();
        await expect(upliftCell).toBeVisible();
        await expect(labelCell).toBeVisible();
      }
    }
  });
});
