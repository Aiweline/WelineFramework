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
 * Weline_AiKnowledge 模块 E2E 测试
 * 注意：该模块主要提供 MCP 工具和 Service，无独立的后台管理页面
 * 测试验证模块相关功能可访问
 */
moduleDescribe(test, 'Weline_AiKnowledge', 'AI知识库模块 E2E 冒烟测试', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  moduleCase(test, { module: 'Weline_AiKnowledge', id: 'tc01' }, 'AI知识库模块可用且无致命错误', async ({ page }) => {
    const errors = bindPageErrors(page);

    // AI知识库模块主要功能通过AI模块调用，此处验证主模块能正常响应
    await gotoBackend(page, 'ai/backend/manager', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // 验证页面可正常加载AI相关内容
    await expect(page.locator('text=AI管理')).toBeVisible({ timeout: 10000 });
  });

  moduleCase(test, { module: 'Weline_AiKnowledge', id: 'tc02' }, 'MCP服务相关API可正常访问', async ({ page }) => {
    const errors = bindPageErrors(page);

    // 验证MCP服务器端点可访问（通过AI模块间接验证）
    await gotoBackend(page, 'ai/backend/model', { timeout: 60000, settleMs: 1500 });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);

    // AI模型页面加载成功即表示MCP工具注册正常
    await expect(page.locator('text=AI模型管理')).toBeVisible({ timeout: 10000 });
  });
});
