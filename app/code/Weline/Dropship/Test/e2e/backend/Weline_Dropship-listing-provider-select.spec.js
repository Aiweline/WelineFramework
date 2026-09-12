/**
 * 已刊登：供应商 Taglib 多选 chips，默认全部（空值不过滤）
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

moduleDescribe(test, MODULE, '已刊登供应商标签筛选', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-LISTED-PROVIDER-001' }, '默认全部；Taglib chips 可多选；无原生 multiple select', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, LISTED_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-testid="dropship-panel-listed"]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#dropship-listed-providers_container')).toBeVisible();
    await expect(page.locator('#dropship-listed-providers_value')).toHaveValue('');
    await expect(page.locator('select#dropship-listing-sources')).toHaveCount(0);
    await expect(page.getByRole('button', { name: '按供应商筛选' })).toHaveCount(0);

    await page.locator('#dropship-listed-providers_trigger').click();
    const list = page.locator('#dropship-listed-providers_list .w-search-select-item');
    await expect(list.first()).toBeVisible({ timeout: 10000 });
    const count = await list.count();
    expect(count).toBeGreaterThan(0);
    await list.first().click();
    await expect(page.locator('#dropship-listed-providers_value')).not.toHaveValue('', { timeout: 10000 });
    await expect(page.locator('#dropship-listed-providers_chips .w-search-select-chip').first()).toBeVisible({ timeout: 10000 });
  });
});
