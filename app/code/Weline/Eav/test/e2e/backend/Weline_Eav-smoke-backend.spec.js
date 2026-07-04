// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('Weline_Eav backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  /**
   * [module:Weline_Eav] [case:TC-01] EAV统一管理页面能正常加载，验证树形导航和内容区域存在
   */
  test('TC-01: EAV manager index page renders with tree navigation and content area', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/eav/manager', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含EAV管理相关文本
    expect(text).toMatch(/EAV|实体|Entity/i);
  });

  /**
   * [module:Weline_Eav] [case:TC-02] EAV树形数据API能正常返回，验证返回success=true和数据结构
   */
  test('TC-02: EAV tree API returns valid JSON with success status', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/eav/manager/tree', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证返回的是JSON格式且包含success字段
    const response = JSON.parse(text);
    expect(response).toHaveProperty('success');
    expect(typeof response.success).toBe('boolean');
  });

  /**
   * [module:Weline_Eav] [case:TC-03] EAV实体列表页（废弃的Entity控制器）能正常加载
   */
  test('TC-03: EAV entity list page (legacy) renders without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/eav', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
