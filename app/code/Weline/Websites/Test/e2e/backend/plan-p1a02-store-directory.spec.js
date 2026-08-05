/**
 * 万能商城内核计划：后台 Store/Channel 只读目录 + mode/default 不变量（TEST-P1A-02）
 *
 * - website_id=0 下列出夹具创建的 normal/dev/test 非默认 Store
 * - 列表呈现「只读」目录文案 / badge
 * - Catalog 写 createStore 关闭或失败（写入口保持 off）
 * - 夹具侧已断言：mode 创建后不可变、默认店不可删
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
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const LIST_ROUTE = 'websites/admin/website';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p1a02-store-directory-fixture.php');

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
    throw new Error(`p1a02 fixture ${action} failed: ${parsed.error || stdout}`);
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

moduleDescribe(test, MODULE, '计划 P1A-02 Store 只读目录', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P1A-02' },
    '后台只读 Store 目录展示夹具店铺；写 API 关闭；mode/default 不变量由夹具断言',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      expect(Array.isArray(fixture.stores), '夹具必须返回 stores').toBeTruthy();
      expect(fixture.stores.length, '须创建 normal/dev/test 三店').toBe(3);
      expect(fixture.invariants && fixture.invariants.mode_immutable).toBeTruthy();
      expect(fixture.invariants && fixture.invariants.default_undeletable).toBeTruthy();

      try {
        await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
        await gotoBackend(page, LIST_ROUTE, { timeout: 60000, settleMs: 1200, useProxy: false });
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

        const directory = page.locator('.weline-websites-directory').first();
        await expect(directory, 'Website 列表必须渲染 Store/Channel 只读目录').toBeVisible({ timeout: 20000 });
        await expect(directory).toContainText(/只读/);

        for (const store of fixture.stores) {
          await expect(
            page.locator('body'),
            `列表必须出现夹具 Store code=${store.code}`,
          ).toContainText(store.code);
        }

        await ensureBackendApi(page);
        const createStore = await callWebsites(page, 'createStore', {
          website_id: 0,
          code: `e2e-p1a02-write-${fixture.token}`,
        });
        expect(
          createStore.__no_op || createStore.ok === false,
          `写 createStore 必须关闭或失败：${JSON.stringify(createStore)}`,
        ).toBeTruthy();
      } finally {
        runFixture('cleanup', {
          token: fixture.token,
          stores: fixture.stores,
        });
      }
    },
  );
});
