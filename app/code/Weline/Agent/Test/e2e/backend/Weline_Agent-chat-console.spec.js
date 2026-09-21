/**
 * Agent 聊天控制台可对话
 *
 * @weline-e2e-spec { module: Weline_Agent, type: chapter, layer: backend, feature: backend-chat-console }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport proxy
 */
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const artifactDir = 'tests/e2e/artifacts/agent-chat-console';
fs.mkdirSync(artifactDir, { recursive: true });

test.describe('Agent chat console', () => {
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
    await loginAsAdmin(page, { timeout: 90000, useProxy: true, allowPasswordFallback: true });
  });

  test('TC-01: select role enables composer and send shows user bubble', async ({ page }) => {
    await gotoBackend(page, 'agent/backend/chat/index', { timeout: 60000, settleMs: 1000, useProxy: true });
    await expect(page.locator('#agentChatConsole')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('body')).not.toContainText('完整对话体验即将接入');

    const roleBtn = page.locator('[data-agent-role-code]').first();
    await expect(roleBtn).toBeVisible({ timeout: 15000 });
    await roleBtn.click();
    await expect(page.locator('#agentChatInput')).toBeEnabled({ timeout: 5000 });
    await expect(page.locator('#agentChatSend')).toBeEnabled();

    const apiReady = await page.evaluate(() => {
      return !!(window.Weline && window.Weline.Api && window.WelineAgentAdmin && typeof window.WelineAgentAdmin.call === 'function');
    });
    expect(apiReady).toBeTruthy();

    await page.fill('#agentChatInput', 'e2e hello ' + Date.now());
    await page.locator('#agentChatSend').click();
    await expect(page.locator('#agentChatTimeline .w-agent-msg[data-role="user"]').first()).toBeVisible({ timeout: 20000 });
    await page.screenshot({ path: `${artifactDir}/tc01-after-send.png`, fullPage: true });

    const body = await page.locator('body').innerText();
    expect(body.trim().startsWith('{')).toBeFalsy();
  });
});
