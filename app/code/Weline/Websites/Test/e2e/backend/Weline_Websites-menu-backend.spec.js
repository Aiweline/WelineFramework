/**
 * R4.3 Websites control-plane menu and decisive WebUI writes.
 *
 * @weline-e2e-spec { module: Weline_Websites, type: flow, layer: backend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  collectBackendMenuSnapshot,
  installBackendBrowserGuards,
  openBackendMenuBySource,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'Weline_Websites-store-channel-fixture.php');
const PARENT = 'Weline_Websites::website_service';
const CAPABILITIES = [
  ['Weline_Websites::website', '/websites/admin/website/index', '[data-testid="website-management"]'],
  ['Weline_Websites::store_management', '/websites/backend/scope-management/stores', '[data-testid="store-management"]'],
  ['Weline_Websites::sales_channel_management', '/websites/backend/scope-management/channels', '[data-testid="sales-channel-management"]'],
  ['Weline_Websites::domain_service', '/websites/admin/domain/index', '[data-testid="domain-management"]'],
  ['Weline_Websites::site_builder_agent', '/websites/backend/site-builder-agent/index', '[data-testid="site-builder-management"]'],
  ['Weline_Websites::website_maintenance', '/websites/backend/maintenance', '[data-testid="website-maintenance-management"]'],
  ['Weline_Websites::website_backup', '/websites/backend/backup', '[data-testid="website-backup-management"]'],
  ['Weline_Websites::store_copy', '/websites/admin/store-copy/wizard', '[data-testid="store-copy-wizard"]'],
  ['Weline_Websites::provisioning', '/websites/backend/provisioning/index', '[data-testid="website-provisioning-management"]'],
].map(([sourceId, urlIncludes, pageAnchor]) => ({ sourceId, parentSource: PARENT, urlIncludes, pageAnchor }));

function fixture(action, payload = {}) {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 write fixture requires WELINE_E2E_ISOLATED_DB=1');
  }
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const parsed = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).at(-1) || '{}');
  if (!parsed.ok) throw new Error('Websites fixture ' + action + ' failed: ' + (parsed.error || output));
  return parsed;
}

async function submit(page, testId) {
  const form = page.locator('[data-testid="' + testId + '"]');
  await expect(form).toBeVisible();
  await form.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
}

moduleDescribe(test, MODULE, 'R4.3 网站控制面菜单与真实写操作', () => {
  test.setTimeout(240000);

  moduleCase(test, { module: MODULE, id: 'CK-R43-WEBSITES-MENU-001' }, '网站服务九个管理工作台各出现一次', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await waitForBackendShellReady(page);
    const snapshot = await collectBackendMenuSnapshot(page);
    for (const capability of CAPABILITIES) {
      const rows = snapshot.filter((row) => row.sourceId === capability.sourceId);
      expect(rows, capability.sourceId).toHaveLength(1);
      expect(rows[0].parentSource, capability.sourceId).toBe(capability.parentSource);
      expect(rows[0].href.trim(), capability.sourceId).not.toBe('');
      expect(rows[0].href, capability.sourceId).not.toMatch(/^(?:#|javascript:)/i);
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-WEBSITES-MENU-002' }, '逐项点击网站服务菜单并验证真实管理页面锚点', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    const guards = installBackendBrowserGuards(page);
    for (const capability of CAPABILITIES) {
      await openBackendMenuBySource(page, capability.sourceId, capability);
    }
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-WEBSITES-STORE-001' }, '从商店管理菜单创建 Store 并验证 PostgreSQL', async ({ page }) => {
    const data = fixture('prepare', { kind: 'store', token: 's' + Date.now().toString(36) });
    const guards = installBackendBrowserGuards(page);
    try {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await openBackendMenuBySource(page, 'Weline_Websites::store_management', CAPABILITIES[1]);
      const form = page.locator('[data-testid="store-management-create-form"]');
      await form.locator('[name="website_id"]').fill(String(data.website_id));
      await form.locator('[name="code"]').fill(data.store_code);
      await form.locator('[name="name"]').fill(data.store_name);
      await form.locator('[name="store_mode"]').selectOption(data.store_mode);
      await submit(page, 'store-management-create-form');
      const persisted = fixture('inspect', data);
      expect(persisted.stores).toHaveLength(1);
      expect(persisted.stores[0].code).toBe(data.store_code);
      expect(persisted.stores[0].store_mode).toBe(data.store_mode);
      guards.assertClean();
    } finally {
      const cleanup = fixture('cleanup', data);
      expect(cleanup.remaining).toEqual({ stores: 0, channels: 0 });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-WEBSITES-CHANNEL-001' }, '从渠道管理菜单创建 Sales Channel 并验证 PostgreSQL', async ({ page }) => {
    const data = fixture('prepare', { kind: 'channel', token: 'c' + Date.now().toString(36) });
    const guards = installBackendBrowserGuards(page);
    try {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await openBackendMenuBySource(page, 'Weline_Websites::sales_channel_management', CAPABILITIES[2]);
      const form = page.locator('[data-testid="sales-channel-management-create-form"]');
      await form.locator('[name="website_id"]').fill(String(data.website_id));
      await form.locator('[name="store_id"]').selectOption(String(data.store_id));
      await form.locator('[name="code"]').fill(data.channel_code);
      await form.locator('[name="name"]').fill(data.channel_name);
      await submit(page, 'sales-channel-management-create-form');
      const persisted = fixture('inspect', data);
      expect(persisted.channels).toHaveLength(1);
      expect(persisted.channels[0].code).toBe(data.channel_code);
      expect(Number(persisted.channels[0].store_id)).toBe(data.store_id);
      guards.assertClean();
    } finally {
      const cleanup = fixture('cleanup', data);
      expect(cleanup.remaining).toEqual({ stores: 0, channels: 0 });
    }
  });
});
