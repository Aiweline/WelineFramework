/**
 * 选品悬浮操作栏：选择入货源列表 / 已入列表禁勾选 / 徽章移出 / 已拉取不可操作
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
const PICK_URL = 'dropship/backend/listing/index?tab=pick&provider_code=fake&country_code=US';

moduleDescribe(test, MODULE, '选品悬浮操作栏货源列表', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-PICK-FLOAT-001' }, '勾选后选择入列表禁勾选；点徽章移出；已拉取无勾选', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, PICK_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);
    expect(bodyText).not.toContain('upstream_request_failed');

    const layout = page.locator('[data-testid="dropship-pick-layout"]');
    await expect(layout).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-testid="dropship-browse-loading"]')).toBeHidden({ timeout: 120000 });
    await expect(page.locator('[data-testid="dropship-browse-tbody"] tr').first()).toBeVisible({ timeout: 30000 });

    const floatBar = page.locator('[data-testid="dropship-pick-float"]');
    await expect(page.locator('[data-testid="dropship-float-select"]')).toHaveCount(1);
    await expect(page.locator('[data-testid="dropship-float-cancel"]')).toHaveCount(1);

    // 清空本页已入列表：点状态徽章移出（已入列表无勾选）
    for (const badge of await page.locator('[data-testid="dropship-pull-basket"]').all()) {
      await badge.click();
    }
    await expect(page.locator('[data-testid="dropship-pull-basket"]')).toHaveCount(0, { timeout: 15000 });
    for (const box of await page.locator('.ds-pick-check:checked').all()) {
      await box.uncheck();
    }
    await expect(floatBar).toBeHidden();

    const operableRow = page.locator('tr[data-pull-locked="0"][data-pull-basket="0"]').first();
    await expect(operableRow).toBeVisible({ timeout: 60000 });
    await operableRow.click();
    await expect(floatBar).toBeVisible();
    await expect(operableRow.locator('.ds-pick-check')).toBeChecked();
    await expect(page.locator('[data-testid="dropship-float-select"]')).toBeEnabled();

    await page.locator('[data-testid="dropship-float-select"]').click();
    const basketBadge = page.locator('[data-testid="dropship-pull-basket"]').first();
    await expect(basketBadge).toBeVisible({ timeout: 15000 });
    const basketRow = basketBadge.locator('xpath=ancestor::tr[1]');
    await expect(basketRow.locator('.ds-pick-check')).toHaveCount(0);
    await expect(basketRow).toHaveAttribute('data-pull-basket', '1');
    await expect(floatBar).toBeHidden();

    await basketBadge.click();
    await expect(page.locator('[data-testid="dropship-pull-basket"]')).toHaveCount(0, { timeout: 15000 });

    const locked = page.locator('[data-testid="dropship-pull-locked"]');
    const lockedCount = await locked.count();
    if (lockedCount > 0) {
      const lockedRow = locked.first().locator('xpath=ancestor::tr[1]');
      await expect(lockedRow.locator('.ds-pick-check')).toHaveCount(0);
      await expect(locked.first()).toContainText('已拉取');
    }

    // 刊登弹窗：官方 <w:scope> 四级范围（禁止拆分网站/店铺手选）
    const again = page.locator('tr[data-pull-locked="0"][data-pull-basket="0"]').first();
    await again.click();
    await expect(floatBar).toBeVisible();
    await page.locator('[data-testid="dropship-publish-selected"]').click();
    const publishModal = page.locator('[data-testid="dropship-publish-modal"]');
    await expect(publishModal).toBeVisible({ timeout: 10000 });
    await expect(publishModal.locator('#dropship-publish-scope')).toHaveCount(1);
    await expect(publishModal.locator('input[name="target_scope"]')).toHaveCount(1);
    await expect(publishModal.locator('#dropship-publish-website_wrapper')).toHaveCount(0);
    await expect(publishModal.locator('#dropship-publish-store_wrapper')).toHaveCount(0);
    await expect(publishModal.locator('label[for="dropship-publish-scope"]')).toHaveText('刊登范围');
    await expect(publishModal.locator('.w-tree-select, [data-w-component="scope-select"], .w-tree-select-display').first()).toBeVisible();
    await page.locator('#modal-close').click();
    await expect(publishModal).toBeHidden();
  });
});
