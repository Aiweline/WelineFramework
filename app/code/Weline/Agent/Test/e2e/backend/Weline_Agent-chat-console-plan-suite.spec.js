/**
 * Agent 聊天控制台 — 计划收口套件
 *
 * @weline-e2e-spec { module: Weline_Agent, type: plan-suite, layer: backend, feature: backend-chat-console }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport proxy
 */
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const artifactDir = 'tests/e2e/artifacts/agent-chat-console-plan-suite';
fs.mkdirSync(artifactDir, { recursive: true });

test.describe('计划链路/功能链路 e2e组: Agent 聊天控制台', () => {
  test('完整功能通路：登录 → 选角色 → 发送 → 时间线出现用户消息', async ({ page }) => {
    await page.context().clearCookies();
    await loginAsAdmin(page, { timeout: 90000, useProxy: true, allowPasswordFallback: true });

    await gotoBackend(page, 'agent/backend/chat/index', { timeout: 60000, settleMs: 1500, useProxy: true });
    await expect(page.locator('#agentChatConsole')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#agentChatForm')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('.w-agent-admin h1').first()).toContainText(/聊天控制台/);

    const roleBtn = page.locator('[data-agent-role-code]').first();
    await roleBtn.click();
    await page.fill('#agentChatInput', 'plan-suite ping');
    await page.locator('#agentChatSend').click();
    await expect(page.locator('#agentChatTimeline .w-agent-msg[data-role="user"]').first()).toBeVisible({ timeout: 30000 });
    await page.screenshot({ path: `${artifactDir}/plan-suite-chat.png`, fullPage: true });
  });
});
