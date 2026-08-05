/**
 * 万能商城内核计划：Catalog v1 Query + ACL LIST / IDOR 空列表（TEST-ACL-01）
 *
 * - websites.getStoreCatalogV1 / getSalesChannelCatalogV1 已发布且可后台调用
 * - 有 All Sites 只读授权的 admin：返回 Store/Channel 投影
 * - 有 website_list 但无 ObjectScopeGrant 的受限角色：Catalog 返回空数组（不泄漏对象）
 * - 越界/缺参/额外参数 fail-closed；写 CRUD 未发布
 * - 入口：websites/admin/website；直连 TARGET_ORIGIN（PLAYWRIGHT_DISABLE_PROXY=1）
 *
 * Provider 直连对 `'00'` 的规范整数拒绝由单元/集成覆盖；worker 网关会对数字字符串做规范化。
 *
 * @weline-e2e-spec { module: Weline_Websites, type: plan, layer: backend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const LIST_ROUTE = 'websites/admin/website';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-acl01-denied-fixture.php');

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  const parsed = JSON.parse(last);
  if (!parsed.ok) {
    throw new Error(`acl01 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function ensureBackendApi(page) {
  await waitForBackendShellReady(page);
  await page.waitForFunction(() => {
    const w = window.Weline;
    if (w && w.Api && typeof w.Api.resource === 'function') {
      return true;
    }
    return !!(w && typeof w.load === 'function');
  }, { timeout: 30000 });
  await page.evaluate(async () => {
    const w = window.Weline;
    if (w && w.Api && typeof w.Api.resource === 'function') {
      return;
    }
    if (w && typeof w.load === 'function') {
      await w.load('api');
    }
  });
  await page.waitForFunction(
    () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
    { timeout: 15000 },
  );
}

async function callWebsites(page, operation, params) {
  return page.evaluate(async ({ operation: op, params: p }) => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const websites = await api.resource('websites');
    if (!websites || typeof websites[op] !== 'function') {
      return { __no_op: op, keys: websites ? Object.keys(websites) : [] };
    }
    try {
      const data = await websites[op](p);
      return { ok: true, data };
    } catch (err) {
      return {
        ok: false,
        message: String(err && (err.message || err)),
        code: err && (err.code || err.status || null),
      };
    }
  }, { operation, params });
}

moduleDescribe(test, MODULE, '计划 ACL Catalog Query Browser 用例', () => {
  test.setTimeout(240000);

  /** @type {{ role_id:number, user_id:number, username:string, password:string }|null} */
  let denied = null;

  test.beforeAll(() => {
    denied = runFixture('prepare');
  });

  test.afterAll(() => {
    if (!denied) {
      return;
    }
    try {
      runFixture('cleanup', { role_id: denied.role_id, user_id: denied.user_id });
    } catch (_) {
      // best-effort；残留可按 e2e_acl01_* 前缀人工清理
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-ACL-01' },
    '后台 Catalog v1：授权可读；受限角色空列表；非法参拒绝；写 CRUD 关闭',
    async ({ page, context }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
      await gotoBackend(page, LIST_ROUTE, { timeout: 60000, settleMs: 1200, useProxy: false });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      await expect(page.locator('body')).toContainText(/网站|Website|站点|Site/i);
      await ensureBackendApi(page);

      const stores = await callWebsites(page, 'getStoreCatalogV1', { website_id: 0 });
      expect(stores.__no_api, '后台必须暴露 Weline.Api').toBeUndefined();
      expect(stores.__no_op, `必须发布 getStoreCatalogV1，keys=${JSON.stringify(stores.keys || [])}`).toBeUndefined();
      expect(stores.ok, `getStoreCatalogV1 失败: ${stores.message || ''}`).toBeTruthy();
      expect(Array.isArray(stores.data), 'Store Catalog 必须返回数组').toBeTruthy();
      expect(stores.data.length, 'default Website 至少有一个 Store（需 All Sites 只读授权）').toBeGreaterThan(0);
      expect(stores.data[0]).toMatchObject({
        website_id: 0,
        code: expect.any(String),
        store_mode: expect.any(String),
      });

      const storeId = Number(stores.data[0].store_id);
      expect(storeId).toBeGreaterThan(0);
      const channels = await callWebsites(page, 'getSalesChannelCatalogV1', { store_id: storeId });
      expect(channels.ok, `getSalesChannelCatalogV1 失败: ${channels.message || ''}`).toBeTruthy();
      expect(Array.isArray(channels.data)).toBeTruthy();
      expect(channels.data.length).toBeGreaterThan(0);
      expect(channels.data[0]).toMatchObject({
        store_id: storeId,
        website_id: 0,
        code: expect.any(String),
      });

      const badWebsite = await callWebsites(page, 'getStoreCatalogV1', { website_id: -1 });
      expect(badWebsite.ok, '越界 website_id 必须失败').toBeFalsy();

      const missing = await callWebsites(page, 'getStoreCatalogV1', {});
      expect(missing.ok, '缺 website_id 必须失败').toBeFalsy();

      const badExtra = await callWebsites(page, 'getStoreCatalogV1', { website_id: 0, unexpected: true });
      expect(badExtra.ok, '额外参数必须失败').toBeFalsy();

      const createStore = await callWebsites(page, 'createStore', { website_id: 0, code: 'e2e-acl01' });
      expect(
        createStore.__no_op || createStore.ok === false,
        'Store 写 CRUD 不得通过 Catalog Query 成功写入',
      ).toBeTruthy();

      // —— IDOR / default-deny：受限角色可调 Catalog，但列表为空 ——
      expect(denied, '受限角色 fixture 必须准备成功').toBeTruthy();
      await context.clearCookies();
      // 完整登录：menus 源 Weline_Websites::website 保证登录后可落地，不会因 dashboard 无权限被踢出
      await loginAsAdmin(page, {
        timeout: 90000,
        settleMs: 800,
        useProxy: false,
        username: denied.username,
        password: denied.password,
      });
      await gotoBackend(page, LIST_ROUTE, { timeout: 60000, settleMs: 1200, useProxy: false });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      await ensureBackendApi(page);

      const deniedStores = await callWebsites(page, 'getStoreCatalogV1', { website_id: 0 });
      expect(deniedStores.__no_api, '受限角色页也必须暴露 Weline.Api').toBeUndefined();
      expect(deniedStores.__no_op, '受限角色仍须能调用 getStoreCatalogV1').toBeUndefined();
      expect(deniedStores.ok, `受限角色 getStoreCatalogV1 失败: ${deniedStores.message || ''}`).toBeTruthy();
      expect(Array.isArray(deniedStores.data)).toBeTruthy();
      expect(
        deniedStores.data.length,
        '无 ObjectScopeGrant 时 Store Catalog 必须空列表（不泄漏对象）',
      ).toBe(0);

      const deniedChannels = await callWebsites(page, 'getSalesChannelCatalogV1', { store_id: storeId });
      expect(deniedChannels.ok, `受限角色 getSalesChannelCatalogV1 失败: ${deniedChannels.message || ''}`).toBeTruthy();
      expect(Array.isArray(deniedChannels.data)).toBeTruthy();
      expect(
        deniedChannels.data.length,
        '无 ObjectScopeGrant 时 Channel Catalog 必须空列表（不泄漏对象）',
      ).toBe(0);

      const info = getRuntimeInfo();
      expect(info.runtime && info.runtime.target_origin).toBeTruthy();
    }
  );
});
