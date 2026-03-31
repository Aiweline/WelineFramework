// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, moduleDescribe, moduleCase } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

/**
 * Weline_Ai 模块 E2E 测试
 * 关键功能验证：
 * 1. AI管理聚合页能正常加载，包含模型、适配器、供应商账户三个Tab
 * 2. AI模型列表页能正常加载，显示模型表格和筛选条件
 */
moduleDescribe(test, 'Weline_Ai', 'AI模块 E2E 冒烟测试', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Ai', id: 'tc01' }, 'AI管理聚合页能正确加载并显示Tab导航', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'ai/backend/manager', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含AI管理相关元素
    await expect(page.locator('text=AI管理')).toBeVisible({ timeout: 10000 });

    // 验证三个Tab都存在
    await expect(page.locator('text=模型')).toBeVisible();
    await expect(page.locator('text=适配器')).toBeVisible();
    await expect(page.locator('text=供应商账户')).toBeVisible();
  });

  moduleCase(test, { module: 'Weline_Ai', id: 'tc02' }, 'AI模型列表页能正确加载并显示模型表格', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'ai/backend/model', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含模型列表标题
    await expect(page.locator('text=AI模型管理')).toBeVisible({ timeout: 10000 });

    // 验证筛选条件区域存在
    await expect(page.locator('text=筛选条件')).toBeVisible();

    // 验证表格区域存在（包含表头）
    await expect(page.locator('text=模型名称')).toBeVisible();
    await expect(page.locator('text=供应商')).toBeVisible();
    await expect(page.locator('text=模型代码')).toBeVisible();
  });

  moduleCase(test, { module: 'Weline_Ai', id: 'tc03' }, 'AI适配器列表页能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'ai/backend/adapter', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含适配器相关内容（无致命错误）
    expect(text).not.toMatch(FATAL_PATTERN);
  });

  moduleCase(test, { module: 'Weline_Ai', id: 'tc04' }, 'AI供应商账户页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'ai/backend/provider', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含供应商账户相关内容
    await expect(page.locator('text=供应商账户').or(page.locator('text=Account')).or(page.locator('text=账户'))).toBeVisible({ timeout: 10000 });
  });
});
