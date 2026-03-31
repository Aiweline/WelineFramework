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
 * Weline_AutoLeadAgent 模块 E2E 测试
 * 关键功能验证：
 * 1. 自动寻客任务列表页面能正常加载
 * 2. 任务列表、候选客户统计正确显示
 * 3. 目标网站管理页面能正常加载
 */
moduleDescribe(test, 'Weline_AutoLeadAgent', '自动获客Agent模块 E2E 冒烟测试', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_AutoLeadAgent', id: 'tc01' }, '自动寻客任务列表页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'auto-lead-agent/backend/index', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含自动寻客相关内容
    await expect(page.locator('text=自动寻客')).toBeVisible({ timeout: 10000 });

    // 验证任务列表区域存在
    await expect(page.locator('text=任务').or(page.locator('text=Task'))).toBeVisible();
  });

  moduleCase(test, { module: 'Weline_AutoLeadAgent', id: 'tc02' }, '目标网站管理页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'auto-lead-agent/backend/targetWebsite', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含目标网站相关内容
    const targetWebsiteVisible = await page.locator('text=目标网站').isVisible().catch(() => false);
    const websiteVisible = await page.locator('text=Website').isVisible().catch(() => false);
    expect(targetWebsiteVisible || websiteVisible).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_AutoLeadAgent', id: 'tc03' }, 'Token管理页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'auto-lead-agent/backend/token', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面无致命错误
    expect(text).not.toMatch(FATAL_PATTERN);
  });

  moduleCase(test, { module: 'Weline_AutoLeadAgent', id: 'tc04' }, '候选客户管理页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'auto-lead-agent/backend/candidate', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面包含候选客户相关内容
    const candidateVisible = await page.locator('text=候选客户').isVisible().catch(() => false);
    const candidateEnVisible = await page.locator('text=Candidate').isVisible().catch(() => false);
    expect(candidateVisible || candidateEnVisible).toBeTruthy();
  });

  moduleCase(test, { module: 'Weline_AutoLeadAgent', id: 'tc05' }, '浏览器扩展管理页面能正确加载', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoBackend(page, 'auto-lead-agent/backend/wasm', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面无致命错误
    expect(text).not.toMatch(FATAL_PATTERN);
  });
});
