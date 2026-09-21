/**
 * Agent 后台 BinQuery 写操作 — 计划收口套件（完整功能通路）
 *
 * @weline-e2e-spec { module: Weline_Agent, type: plan-suite, layer: backend, feature: agent-admin-binquery }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport proxy
 */
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const artifactDir = 'tests/e2e/artifacts/agent-admin-binquery-plan-suite';
fs.mkdirSync(artifactDir, { recursive: true });

async function assertNotRawJsonPage(page) {
  const url = page.url();
  expect(url).not.toMatch(/\/(save|models|adapters|suggest)(\?|$)/);
  const text = ((await page.locator('body').innerText().catch(() => '')).trim() || '');
  if (/^\s*\{\s*"success"\s*:/.test(text) || /^\s*\{\s*"msg"\s*:/.test(text)) {
    throw new Error('unexpected raw JSON page at ' + url + ' body=' + text.slice(0, 160));
  }
  await expect(page.locator('.w-agent-admin, .w-backend-page, #admin-main, body').first()).toBeVisible({ timeout: 10000 });
}

test.describe('计划链路/功能链路 e2e组: Agent 后台 BinQuery', () => {
  test('完整功能通路：登录 → 列表壳 → 角色保存回列表 → 调度保存回列表', async ({ page }) => {
    await page.context().clearCookies();
    await loginAsAdmin(page, { timeout: 90000, useProxy: true, allowPasswordFallback: true });

    await gotoBackend(page, 'agent/backend/role/listing', { timeout: 60000, settleMs: 800, useProxy: true });
    await assertNotRawJsonPage(page);
    await expect(page.locator('.w-agent-admin h1').first()).toContainText(/角色管理/);

    await gotoBackend(page, 'agent/backend/role/edit?id=1', { timeout: 60000, settleMs: 1000, useProxy: true });
    await expect(page.locator('#agentRoleForm')).toBeVisible({ timeout: 15000 });
    await page.locator('#agentRoleForm button[type="submit"]').click();
    await page.waitForURL(/agent\/backend\/role\/listing/, { timeout: 30000 });
    await assertNotRawJsonPage(page);

    await gotoBackend(page, 'agent/backend/schedule/add', { timeout: 60000, settleMs: 1000, useProxy: true });
    await expect(page.locator('#agentScheduleForm')).toBeVisible({ timeout: 15000 });
    await page.fill('#agent-schedule-name', 'plan-suite-schedule-' + Date.now());
    const roleSelect = page.locator('#agent-schedule-role');
    if (await roleSelect.count()) {
      const optionCount = await roleSelect.locator('option').count();
      if (optionCount > 1) await roleSelect.selectOption({ index: 1 });
    }
    await page.fill('#agent-schedule-cron', '30 7 * * *');
    await page.fill('#agent-schedule-prompt', 'plan suite prompt');
    await page.locator('#agentScheduleForm button[type="submit"]').click();
    await page.waitForURL(/agent\/backend\/schedule\/listing/, { timeout: 30000 });
    await assertNotRawJsonPage(page);

    for (const path of [
      'agent/backend/skill/listing',
      'agent/backend/session/listing',
      'agent/backend/memory/listing',
      'agent/backend/chat/index',
    ]) {
      await gotoBackend(page, path, { timeout: 60000, settleMs: 500, useProxy: true });
      await assertNotRawJsonPage(page);
    }

    await page.screenshot({ path: `${artifactDir}/plan-suite-final.png`, fullPage: true });
  });
});
