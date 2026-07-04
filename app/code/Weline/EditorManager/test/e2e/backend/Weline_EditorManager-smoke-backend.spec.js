// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('Weline_EditorManager backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  /**
   * [module:Weline_EditorManager] [case:TC-01] 编辑器管理器首页能正常加载，验证页面包含"编辑器"相关文本
   */
  test('TC-01: editor manager index page renders with title and editor list', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/editor-manager', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含编辑器管理器相关内容
    expect(text).toMatch(/编辑器|Editor/i);
  });

  /**
   * [module:Weline_EditorManager] [case:TC-02] 编辑器管理器页面包含有意义的内容区域
   */
  test('TC-02: editor manager page contains content area elements', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'admin/editor-manager', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 页面应该有实际内容，不是空白页
    expect(text.trim().length).toBeGreaterThan(10);
  });
});
