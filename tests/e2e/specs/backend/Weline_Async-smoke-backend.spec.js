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
 * Weline_Async 模块 E2E 测试
 * 关键功能验证：
 * 1. 同步主机管理页面能正常加载
 * 2. 主机列表能正确显示表格
 * 3. 新增主机按钮存在
 */
moduleDescribe(test, 'Weline_Async', '异步同步模块 E2E 冒烟测试', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_Async', id: 'tc01' }, '同步主机管理页面能正确加载并显示主机列表', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'async/backend/host', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含同步主机管理标题
    await expect(page.locator('text=同步主机管理')).toBeVisible({ timeout: 10000 });

    // 验证主机列表表格区域存在
    await expect(page.locator('text=主机列表')).toBeVisible();

    // 验证表头字段存在
    await expect(page.locator('text=主机名称')).toBeVisible();
    await expect(page.locator('text=主机地址')).toBeVisible();
    await expect(page.locator('text=SSH端口')).toBeVisible();
    await expect(page.locator('text=SSH用户')).toBeVisible();
  });

  moduleCase(test, { module: 'Weline_Async', id: 'tc02' }, '新增主机按钮存在且可点击', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'async/backend/host', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证新增主机按钮存在
    await expect(page.locator('text=新增主机')).toBeVisible({ timeout: 10000 });

    // 点击新增主机按钮，验证Offcanvas能打开
    await page.locator('button:has-text("新增主机")').click();
    await page.waitForTimeout(500);

    // 验证Offcanvas打开（主机配置标题出现）
    await expect(page.locator('text=主机配置')).toBeVisible({ timeout: 5000 });
  });

  moduleCase(test, { module: 'Weline_Async', id: 'tc03' }, '项目纳入同步状态卡片正确显示', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'async/backend/host', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含"系统"面包屑
    await expect(page.locator('text=系统')).toBeVisible({ timeout: 10000 });

    // 如果有项目纳入同步，验证状态卡片存在
    const projectCardVisible = await page.locator('text=项目已纳入同步').isVisible().catch(() => false);
    if (projectCardVisible) {
      await expect(page.locator('text=配置文件')).toBeVisible();
      await expect(page.locator('text=运行状态')).toBeVisible();
    }
  });
});
