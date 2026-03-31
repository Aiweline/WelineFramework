// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('Weline_GenerativeEngineOptimization backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  /**
   * [module:Weline_GenerativeEngineOptimization] [case:TC-01] Feed管理列表页能正常加载，验证包含Feed相关内容
   */
  test('TC-01: GEO feed list page renders with feed management content', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/generativeengineoptimization/backend/feed', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含Feed相关内容
    expect(text).toMatch(/Feed|RSS|内容/i);
  });

  /**
   * [module:Weline_GenerativeEngineOptimization] [case:TC-02] 平台管理列表页能正常加载，验证包含平台相关内容
   */
  test('TC-02: GEO platform list page renders with platform management content', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/generativeengineoptimization/backend/platform', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含平台管理相关内容
    expect(text).toMatch(/平台|Platform|Cloud/i);
  });

  /**
   * [module:Weline_GenerativeEngineOptimization] [case:TC-03] GEO模块菜单入口能正常访问
   */
  test('TC-03: GEO module main entry is accessible', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/generativeengineoptimization', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
