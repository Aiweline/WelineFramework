/**
 * 万能商城内核计划：CDN Scope 授权绑定跨 HTTP 不串（TEST-P1D-02）
 *
 * - A/B Website 各 bind 不同 account/media_base
 * - 独立 HTTP resolve 不串
 * - restore 后 fallback 到 Global
 * - store_mode normal/test 隔离 + COW URL
 * - 响应无 credentials / secret_ref
 *
 * @weline-e2e-spec { module: Weline_Cdn, type: plan, layer: backend }
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

const MODULE = 'Weline_Cdn';
const ACCOUNT_ROUTE = 'cdn/backend/account';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p1d02-scoped-binding-fixture.php');

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
    throw new Error(`p1d02 fixture ${action} failed: ${parsed.error || stdout}`);
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

async function callCdn(page, operation, params = {}) {
  return page.evaluate(async ({ operation: op, params: p }) => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const cdn = await api.resource('cdn');
    if (!cdn || typeof cdn[op] !== 'function') {
      return { __no_op: op, keys: cdn ? Object.keys(cdn) : [] };
    }
    try {
      const data = await cdn[op](p);
      return { ok: true, data };
    } catch (err) {
      return {
        ok: false,
        message: String(err && (err.message || err)),
        code: err && (err.code || err.status || null),
        response: err && err.response && err.response.data ? err.response.data : null,
      };
    }
  }, { operation, params });
}

function unwrap(result) {
  if (!result || result.__no_api || result.__no_op) {
    throw new Error(`cdn api unavailable: ${JSON.stringify(result)}`);
  }
  if (!result.ok) {
    throw new Error(`cdn op failed: ${JSON.stringify(result)}`);
  }
  const data = result.data;
  if (data && typeof data === 'object' && data.data && typeof data.data === 'object') {
    return data.data;
  }
  return data;
}

function assertNoSecrets(payload) {
  const text = JSON.stringify(payload);
  expect(text.includes('"credentials"'), '响应不得含 credentials').toBeFalsy();
  expect(text.includes('"secret_ref"'), '响应不得含 secret_ref').toBeFalsy();
  expect(text.includes('a-secret') || text.includes('b-secret') || text.includes('g-secret') || text.includes('n-secret') || text.includes('t-secret'), '响应不得含夹具明文 secret').toBeFalsy();
}

moduleDescribe(test, MODULE, '计划 P1D-02 Scope 账户绑定', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P1D-02' },
    '跨 HTTP：A/B 不串、restore fallback、store_mode 隔离、COW URL、脱敏',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      expect(fixture.accounts && fixture.accounts.site_a).toBeTruthy();
      expect(fixture.accounts && fixture.accounts.site_b).toBeTruthy();

      try {
        await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
        await gotoBackend(page, ACCOUNT_ROUTE, { timeout: 60000, settleMs: 1200, useProxy: false });
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureBackendApi(page);

        const scopeA = fixture.scopes.site_a;
        const scopeB = fixture.scopes.site_b;
        const scopeGlobal = fixture.scopes.global;
        const storeNormal = fixture.scopes.store_normal;
        const storeTest = fixture.scopes.store_test;

        const bindA = unwrap(await callCdn(page, 'bindAccountToScope', {
          ...scopeA,
          adapter: 'cloudflare',
          account_id: fixture.accounts.site_a,
          media_base_url: fixture.media_urls.site_a,
          global_alias: 'cf-a',
        }));
        expect(bindA.success, `bindA: ${JSON.stringify(bindA)}`).toBeTruthy();
        expect(bindA.binding.account_id).toBe(fixture.accounts.site_a);
        assertNoSecrets(bindA);

        const bindB = unwrap(await callCdn(page, 'bindAccountToScope', {
          ...scopeB,
          adapter: 'cloudflare',
          account_id: fixture.accounts.site_b,
          media_base_url: fixture.media_urls.site_b,
          global_alias: 'cf-b',
        }));
        expect(bindB.success, `bindB: ${JSON.stringify(bindB)}`).toBeTruthy();
        expect(bindB.binding.account_id).toBe(fixture.accounts.site_b);
        assertNoSecrets(bindB);

        const bindGlobal = unwrap(await callCdn(page, 'bindAccountToScope', {
          ...scopeGlobal,
          adapter: 'cloudflare',
          account_id: fixture.accounts.global,
          global_alias: 'cf-global',
        }));
        expect(bindGlobal.success, `bindGlobal: ${JSON.stringify(bindGlobal)}`).toBeTruthy();

        // 独立 HTTP resolve：跨请求读 DB，A/B 不串
        const resolveA = unwrap(await callCdn(page, 'resolveBinding', {
          ...scopeA,
          adapter: 'cloudflare',
        }));
        const resolveB = unwrap(await callCdn(page, 'resolveBinding', {
          ...scopeB,
          adapter: 'cloudflare',
        }));
        expect(resolveA.success).toBeTruthy();
        expect(resolveB.success).toBeTruthy();
        expect(resolveA.binding.account_id).toBe(fixture.accounts.site_a);
        expect(resolveB.binding.account_id).toBe(fixture.accounts.site_b);
        expect(resolveA.binding.account_id).not.toBe(resolveB.binding.account_id);
        expect(resolveA.binding.media_base_url).toBe(fixture.media_urls.site_a);
        expect(resolveB.binding.media_base_url).toBe(fixture.media_urls.site_b);
        expect(resolveA.binding.source_kind).toBe('exact');
        assertNoSecrets({ resolveA, resolveB });

        const authA = unwrap(await callCdn(page, 'resolveAuthorizedAccount', {
          ...scopeA,
          adapter: 'cloudflare',
        }));
        expect(authA.success).toBeTruthy();
        expect(authA.account.account_id).toBe(fixture.accounts.site_a);
        assertNoSecrets(authA);

        // restore A → fallback Global
        const restoreA = unwrap(await callCdn(page, 'restoreScopeInheritance', {
          ...scopeA,
          adapter: 'cloudflare',
        }));
        expect(restoreA.success).toBeTruthy();
        expect(restoreA.restored).toBeTruthy();

        const afterRestore = unwrap(await callCdn(page, 'resolveBinding', {
          ...scopeA,
          adapter: 'cloudflare',
        }));
        expect(afterRestore.binding.account_id).toBe(fixture.accounts.global);
        expect(afterRestore.binding.source_kind).toBe('fallback');
        expect(afterRestore.binding.global_alias).toBe('cf-global');

        // B 仍保持独立
        const stillB = unwrap(await callCdn(page, 'resolveBinding', {
          ...scopeB,
          adapter: 'cloudflare',
        }));
        expect(stillB.binding.account_id).toBe(fixture.accounts.site_b);

        // store_mode 隔离 + COW
        const bindMediaN = unwrap(await callCdn(page, 'bindAccountToScope', {
          ...storeNormal,
          adapter: 'media',
          account_id: fixture.accounts.media_normal,
          media_base_url: fixture.media_urls.media_normal,
        }));
        const bindMediaT = unwrap(await callCdn(page, 'bindAccountToScope', {
          ...storeTest,
          adapter: 'media',
          account_id: fixture.accounts.media_test,
          media_base_url: fixture.media_urls.media_test,
        }));
        expect(bindMediaN.success, JSON.stringify(bindMediaN)).toBeTruthy();
        expect(bindMediaT.success, JSON.stringify(bindMediaT)).toBeTruthy();

        const cowTest = unwrap(await callCdn(page, 'resolveCowMediaUrl', {
          ...storeTest,
          path: 'x.png',
          shared_base_url: fixture.media_urls.shared,
        }));
        expect(cowTest.success).toBeTruthy();
        expect(cowTest.url).toBe(`${fixture.media_urls.media_test}/x.png`);
        expect(cowTest.is_cow_override).toBeTruthy();

        const cowNormal = unwrap(await callCdn(page, 'resolveCowMediaUrl', {
          ...storeNormal,
          path: 'x.png',
          shared_base_url: fixture.media_urls.shared,
        }));
        expect(cowNormal.success).toBeTruthy();
        expect(cowNormal.url).toBe(`${fixture.media_urls.media_normal}/x.png`);
        expect(cowNormal.is_cow_override).toBeFalsy();
        assertNoSecrets({ cowTest, cowNormal });
      } finally {
        runFixture('cleanup', {
          account_ids: fixture.account_ids || [],
          storage_scopes: fixture.storage_scopes || [],
        });
      }
    },
  );
});
