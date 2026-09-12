/**
 * 货源售后：dead 终态与补偿态列
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
const AFTERSALE_URL = 'dropship/backend/aftersale/index';

moduleDescribe(test, MODULE, '货源售后 dead 补偿态', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'CK-DROPSHIP-AFTERSALE-DEAD-001' }, '售后页可打开并含补偿列', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await gotoBackend(page, AFTERSALE_URL, { timeout: 90000, settleMs: 800, useProxy: false });
    await waitForBackendShellReady(page);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(FATAL);

    await expect(page.locator('[data-testid="dropship-aftersale"]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-dropship-admin="aftersale"] .w-card')).toBeVisible();
    const empty = page.locator('[data-testid="dropship-aftersale-empty"]');
    const table = page.locator('[data-testid="dropship-aftersale-table"]');
    const hasTable = await table.count();
    if (hasTable > 0) {
      await expect(table.locator('th', { hasText: '补偿进度' })).toBeVisible();
      await expect(page.locator('[data-testid="dropship-aftersale-compensation"]').first()).toBeVisible();
      const status = page.locator('[data-testid="dropship-aftersale-status"]').first();
      if ((await status.count()) > 0) {
        const text = await status.innerText();
        expect(text).not.toMatch(/^dead$/i);
      }
      // 已幂等恢复的 Order exist 样例不应再以「无法履约」出现
      const body = await page.locator('[data-testid="dropship-aftersale"]').innerText();
      expect(body).not.toMatch(/dropship:cj:create:sandbox-ob3-20260911162331[\s\S]{0,200}无法履约/);
    } else {
      await expect(empty).toBeVisible();
      await expect(empty.getByText('暂无售后记录')).toBeVisible();
      await expect(page.locator('[data-testid="dropship-aftersale-goto-orders"]')).toBeVisible();
      await expect(page.locator('[data-testid="dropship-aftersale-goto-config"]')).toBeVisible();
    }
  });
});
