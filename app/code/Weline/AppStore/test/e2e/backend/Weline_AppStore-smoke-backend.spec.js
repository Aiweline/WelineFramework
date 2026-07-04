// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

/**
 * Weline_AppStore 模块 E2E 测试
 * 关键功能验证：
 * 1. 应用商城首页能正常加载，显示绑定状态
 * 2. 已安装模块页面能正常加载
 * 3. 下载历史页面能正常加载
 */
moduleDescribe(test, 'Weline_AppStore', '应用商城模块 E2E 冒烟测试', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_AppStore', id: 'tc01' }, '应用商城首页能正确加载并显示绑定状态', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'appstore', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含应用商城标题
    await expect(page.locator('text=应用商城')).toBeVisible({ timeout: 10000 });

    // 验证显示绑定状态提示（已绑定或未绑定）
    const isBound = await page.locator('text=已绑定').isVisible().catch(() => false);
    const isNotBound = await page.locator('text=尚未绑定').isVisible().catch(() => false);
    expect(isBound || isNotBound).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_AppStore', id: 'tc02' }, '已安装模块页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'appstore/backend/installed', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含"我的模块"标题
    await expect(page.locator('text=我的模块')).toBeVisible({ timeout: 10000 });
  });

  moduleCase(test, { module: 'Weline_AppStore', id: 'tc03' }, '下载历史页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'appstore/backend/downloadHistory', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面无致命错误
    expect(text).not.toMatch(FATAL_PATTERN);
  });

  moduleCase(test, { module: 'Weline_AppStore', id: 'tc04' }, '账户绑定页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'appstore/backend/account', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面无致命错误
    expect(text).not.toMatch(FATAL_PATTERN);
  });
});
