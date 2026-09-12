/**
 * 已刊登：删除入口与本地商品处理选项
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

moduleDescribe(test, MODULE, '已刊登删除入口', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-LISTED-DELETE-001' }, '删除弹窗含 keep/disable/archive 且确认可点', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, LISTED_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-testid="dropship-panel-listed"]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-testid="dropship-listed-delete-selected"]')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-testid="dropship-delete-modal"]')).toBeHidden();

    const rows = page.locator('[data-testid="dropship-listed-row"]');
    const rowCount = await rows.count();
    if (rowCount > 0) {
      await page.locator('[data-testid="dropship-listed-delete"]').first().click();
      await expect(page.locator('[data-testid="dropship-delete-modal"]')).toBeVisible({ timeout: 10000 });
      await expect(page.locator('[data-testid="dropship-delete-form"]')).toBeVisible();
      await expect(page.locator('[data-testid="dropship-delete-local-keep"]')).toBeVisible();
      await expect(page.locator('[data-testid="dropship-delete-local-disable"]')).toBeChecked();
      await expect(page.locator('[data-testid="dropship-delete-local-archive"]')).toBeVisible();
      const confirm = page.locator('[data-testid="dropship-delete-confirm"]');
      await expect(confirm).toBeVisible();
      await expect(confirm).toBeEnabled();
      const hit = await confirm.evaluate((el) => {
        const r = el.getBoundingClientRect();
        const x = r.left + r.width / 2;
        const y = r.top + r.height / 2;
        const top = document.elementFromPoint(x, y);
        return !!(top && (top === el || el.contains(top)));
      });
      expect(hit).toBeTruthy();
      await page.locator('[data-testid="dropship-delete-cancel"]').click();
      await expect(page.locator('[data-testid="dropship-delete-modal"]')).toBeHidden();
    }
  });
});
