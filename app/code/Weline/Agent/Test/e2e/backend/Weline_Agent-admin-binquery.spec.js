/**
 * Agent 后台：写操作走 BinQuery，禁止浏览器整页 JSON
 *
 * @weline-e2e-spec { module: Weline_Agent, type: chapter, layer: backend, feature: agent-admin-binquery }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport proxy
 */
// @ts-check
const fs = require('fs');
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

const artifactDir = 'tests/e2e/artifacts/agent-admin-binquery';
fs.mkdirSync(artifactDir, { recursive: true });

async function assertNotRawJsonPage(page) {
  const url = page.url();
  expect(url).not.toMatch(/\/(save|models|adapters|suggest)(\?|$)/);
  const text = ((await page.locator('body').innerText().catch(() => '')).trim() || '');
  // Chrome JSON viewer / bare API dump
  if (/^\s*\{\s*"success"\s*:/.test(text) || /^\s*\{\s*"msg"\s*:/.test(text)) {
    throw new Error('unexpected raw JSON page at ' + url + ' body=' + text.slice(0, 160));
  }
  await expect(page.locator('.w-agent-admin, .w-backend-page, #admin-main, body').first()).toBeVisible({ timeout: 10000 });
}

test.describe('Agent admin BinQuery mutations', () => {
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies();
    await loginAsAdmin(page, { timeout: 90000, useProxy: true, allowPasswordFallback: true });
  });

  test('TC-01: role edit save via BinQuery lands on listing HTML', async ({ page }) => {
    await gotoBackend(page, 'agent/backend/role/listing', { timeout: 60000, settleMs: 800, useProxy: true });
    await assertNotRawJsonPage(page);
    await expect(page.locator('.w-agent-admin h1').first()).toContainText(/角色管理/);

    await gotoBackend(page, 'agent/backend/role/edit?id=1', { timeout: 60000, settleMs: 1000, useProxy: true });
    await expect(page.locator('#agentRoleForm')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#agentRoleForm button[type="submit"]')).toContainText(/保存角色/);

    const apiReady = await page.evaluate(async () => {
      return !!(window.Weline && window.Weline.Api && window.WelineAgentAdmin && typeof window.WelineAgentAdmin.call === 'function');
    });
    expect(apiReady).toBeTruthy();

    await page.locator('#agentRoleForm button[type="submit"]').click();
    await page.waitForURL(/agent\/backend\/role\/listing/, { timeout: 30000 });
    await assertNotRawJsonPage(page);
    await page.screenshot({ path: `${artifactDir}/tc01-role-listing.png`, fullPage: true });
  });

  test('TC-02: schedule add save via BinQuery lands on listing HTML', async ({ page }) => {
    await gotoBackend(page, 'agent/backend/schedule/add', { timeout: 60000, settleMs: 1000, useProxy: true });
    await expect(page.locator('#agentScheduleForm')).toBeVisible({ timeout: 15000 });
    await page.fill('#agent-schedule-name', 'e2e-schedule-' + Date.now());
    const roleSelect = page.locator('#agent-schedule-role');
    if (await roleSelect.count()) {
      const options = await roleSelect.locator('option').all();
      if (options.length > 1) {
        await roleSelect.selectOption({ index: 1 });
      }
    }
    await page.fill('#agent-schedule-cron', '0 8 * * *');
    await page.fill('#agent-schedule-prompt', 'e2e prompt');
    await page.locator('#agentScheduleForm button[type="submit"]').click();
    await page.waitForURL(/agent\/backend\/schedule\/listing/, { timeout: 30000 });
    await assertNotRawJsonPage(page);
    await page.screenshot({ path: `${artifactDir}/tc02-schedule-listing.png`, fullPage: true });
  });

  test('TC-03: HTML accept on models/adapters redirects away from raw JSON', async ({ page }) => {
    await gotoBackend(page, 'agent/backend/role/models', { timeout: 60000, settleMs: 800, useProxy: true });
    await assertNotRawJsonPage(page);
    expect(page.url()).toMatch(/role\/listing|admin\/login/);

    await gotoBackend(page, 'agent/backend/role/adapters', { timeout: 60000, settleMs: 800, useProxy: true });
    await assertNotRawJsonPage(page);
    expect(page.url()).toMatch(/role\/listing|admin\/login/);
  });

  test('TC-04: skill/session/memory/chat listings are HTML shells', async ({ page }) => {
    for (const path of [
      'agent/backend/skill/listing',
      'agent/backend/session/listing',
      'agent/backend/memory/listing',
      'agent/backend/chat/index',
    ]) {
      await gotoBackend(page, path, { timeout: 60000, settleMs: 600, useProxy: true });
      await assertNotRawJsonPage(page);
      await expect(page.locator('.w-agent-admin h1')).toBeVisible({ timeout: 15000 });
    }
    await page.screenshot({ path: `${artifactDir}/tc04-chat.png`, fullPage: true });
  });
});
