/**
 * Commerce kernel final dual-Scope acceptance.
 *
 * Covers:
 * - TEST-P1A-05: same route under two trusted Website/Store Hosts
 * - TEST-P1C-02: Meta current-Scope isolation
 * - TEST-P1C-04: I18n current-Scope isolation
 * - TEST-SEC-03: Host/assertion/Cart Scope conflicts fail closed
 * - TEST-SEC-08: HTTPS cookie attributes
 * - TEST-P2A-02: live PostgreSQL shard drift isolation, no business-path DDL
 * - TEST-P2E-01: one guest token, two real Website Hosts, no cart leakage
 *
 * @weline-e2e-spec { module: Weline_Websites, type: plan, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  chromium,
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-commerce-kernel-dual-scope-fixture.php');
const DIRECT = { useProxy: false };
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 240000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`Commerce kernel fixture ${action} failed: ${parsed.error || stdout}`);
  }

  return parsed;
}

function targetPort() {
  const origin = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://127.0.0.1:29821';
  const parsed = new URL(origin);
  const port = Number(parsed.port || (parsed.protocol === 'https:' ? 443 : 80));
  if (!Number.isInteger(port) || port < 9502) {
    throw new Error(`Invalid dedicated WLS target port: ${origin}`);
  }

  return port;
}

async function ensureApi(page) {
  await page.waitForFunction(() => {
    const w = window.Weline;
    return !!(w && ((w.Api && typeof w.Api.resource === 'function') || typeof w.load === 'function'));
  }, undefined, { timeout: 30000 });
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
    undefined,
    { timeout: 15000 },
  );
}

async function runResourceApi(page, provider, operation, params = {}) {
  return page.evaluate(async ({ provider, operation, params }) => {
    try {
      const resource = await window.Weline.Api.resource(provider);
      if (!resource || typeof resource[operation] !== 'function') {
        return {
          __no_operation: operation,
          __provider: provider,
          keys: resource ? Object.keys(resource) : [],
        };
      }
      return await resource[operation](params, { useProxy: false });
    } catch (error) {
      return {
        __error: String(error && error.message ? error.message : error),
        __error_code: error && error.code ? String(error.code) : '',
        __error_response: error && error.response && error.response.data
          ? error.response.data
          : null,
      };
    }
  }, { provider, operation, params });
}

async function runResourceApiBurst(page, provider, operation, params, times) {
  return page.evaluate(async ({ provider, operation, params, times }) => {
    const resource = await window.Weline.Api.resource(provider);
    if (!resource || typeof resource[operation] !== 'function') {
      throw new Error(`Missing resource operation: ${provider}.${operation}`);
    }

    return Promise.all(Array.from({ length: times }, async () => {
      try {
        return await resource[operation](params, { useProxy: false });
      } catch (error) {
        return {
          __error: String(error && error.message ? error.message : error),
          __error_code: error && error.code ? String(error.code) : '',
        };
      }
    }));
  }, { provider, operation, params, times });
}

function pickData(result) {
  if (!result || typeof result !== 'object') {
    return {};
  }

  return result.data && typeof result.data === 'object' ? result.data : result;
}

function isSuccess(result) {
  return !!(result && (
    result.success === true
    || (result.data && result.data.success === true)
  ));
}

function errorCode(result) {
  const response = result && result.__error_response;
  return String(
    (result && result.error_code)
    || (result && result.data && result.data.error_code)
    || (response && response.error_code)
    || (response && response.data && response.data.error_code)
    || (response && response.data && response.data.data && response.data.data.error_code)
    || (result && result.__error_code)
    || '',
  );
}

async function openScopePage(browser, url) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text());
    }
  });
  page.on('pageerror', (error) => consoleErrors.push(String(error.message || error)));
  let response = null;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    response = await gotoFrontend(page, url, {
      timeout: 90000,
      waitUntil: 'domcontentloaded',
      settleMs: 250,
      ...DIRECT,
    });
    if (response && response.status() !== 404) {
      break;
    }
    await page.waitForTimeout(500);
  }
  expect(
    response && response.status(),
    `Scope acceptance page did not become routable: requested=${url}; final=${page.url()}`,
  ).toBe(200);
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  await ensureApi(page);

  return {
    context,
    page,
    response,
    consoleErrors,
  };
}

function assertNoFatalConsole(errors) {
  expect(
    errors.filter((line) => FATAL_PATTERN.test(line)),
    `Unexpected fatal Browser console output: ${JSON.stringify(errors)}`,
  ).toEqual([]);
}

moduleDescribe(test, 'Weline_Websites', '万能商城内核双站点最终验收', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(300000);

  let fixture = null;
  let guestToken = '';
  let scopedBrowser = null;

  test.beforeAll(async ({}, testInfo) => {
    testInfo.setTimeout(300000);
    fixture = runFixture('prepare', {
      port: targetPort(),
      token: `${Date.now().toString(16)}${process.pid.toString(16)}`.slice(-12),
    });
    scopedBrowser = await chromium.launch({
      headless: true,
      args: [
        '--ignore-certificate-errors',
        '--no-proxy-server',
        '--proxy-bypass-list=*',
        '--host-resolver-rules=MAP *.weline.localhost 127.0.0.1',
      ],
    });
  });

  test.afterAll(async ({}, testInfo) => {
    testInfo.setTimeout(300000);
    if (scopedBrowser) {
      await scopedBrowser.close();
      scopedBrowser = null;
    }
    if (fixture && fixture.token) {
      runFixture('cleanup', {
        token: fixture.token,
        guest_token: guestToken,
      });
    }
  });

  moduleCase(
    test,
    { module: 'Weline_Websites', id: 'TEST-P1A-05' },
    '同一路径在两个真实 Host 下冻结为不同 Website/Store Scope',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      try {
        expect(a.response && a.response.status()).toBe(200);
        expect(b.response && b.response.status()).toBe(200);
        expect(new URL(a.page.url()).pathname).toBe('/dev/tool/docs/api');
        expect(new URL(b.page.url()).pathname).toBe('/dev/tool/docs/api');

        const aResult = pickData(await runResourceApi(
          a.page,
          'i18n',
          'resolveCurrentScopeTranslation',
          { source: fixture.i18n_source, locale_code: fixture.locale },
        ));
        const bResult = pickData(await runResourceApi(
          b.page,
          'i18n',
          'resolveCurrentScopeTranslation',
          { source: fixture.i18n_source, locale_code: fixture.locale },
        ));
        expect(aResult.requested_scope.website_id).toBe(fixture.a.website_id);
        expect(bResult.requested_scope.website_id).toBe(fixture.b.website_id);
        expect(aResult.requested_scope.store_code).toBe('scope');
        expect(bResult.requested_scope.store_code).toBe('scope');
        expect(aResult.requested_scope.channel_code).toBe('default');
        expect(bResult.requested_scope.channel_code).toBe('default');
        expect(aResult.requested_scope.website_code)
          .not.toBe(bResult.requested_scope.website_code);
        assertNoFatalConsole(a.consoleErrors);
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        await a.context.close();
        await b.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Websites', id: 'TEST-WLS-02' },
    '同一长生命周期实例按零号站-A-B-零号站顺序请求不串 Scope',
    async () => {
      const defaultUrl = `https://127.0.0.1:${targetPort()}/dev/tool/docs/api`;
      const sequence = [defaultUrl, fixture.a.url, fixture.b.url, defaultUrl];
      const expectedCodes = ['default', fixture.a.website_code, fixture.b.website_code, 'default'];
      const actualCodes = [];

      for (const url of sequence) {
        const current = await openScopePage(scopedBrowser, url);
        try {
          const data = pickData(await runResourceApi(
            current.page,
            'i18n',
            'resolveCurrentScopeTranslation',
            { source: fixture.i18n_source, locale_code: fixture.locale },
          ));
          actualCodes.push(String(data.requested_scope?.website_code || ''));
          expect(String(data.requested_scope?.store_code || '')).toBe(
            url === defaultUrl ? 'default' : 'scope',
          );
        } finally {
          await current.context.close();
        }
      }

      expect(actualCodes).toEqual(expectedCodes);
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Websites', id: 'TEST-WLS-03' },
    '双 Host 各100次并发 Worker API 请求 Scope 与 sentinel mismatch 为0',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      const requestIds = new Set();
      const collectRequestId = async (response) => {
        const headers = await response.allHeaders();
        const requestId = String(headers['x-weline-request-id'] || '');
        if (requestId !== '') {
          requestIds.add(requestId);
        }
      };
      a.page.on('response', collectRequestId);
      b.page.on('response', collectRequestId);

      try {
        const params = { source: fixture.i18n_source, locale_code: fixture.locale };
        const [aResults, bResults] = await Promise.all([
          runResourceApiBurst(a.page, 'i18n', 'resolveCurrentScopeTranslation', params, 100),
          runResourceApiBurst(b.page, 'i18n', 'resolveCurrentScopeTranslation', params, 100),
        ]);

        expect(aResults).toHaveLength(100);
        expect(bResults).toHaveLength(100);
        const mismatches = [];
        for (const [side, results, expected] of [
          ['A', aResults, fixture.a],
          ['B', bResults, fixture.b],
        ]) {
          results.forEach((result, index) => {
            const data = pickData(result);
            if (result.__error
              || Number(data.requested_scope?.website_id || 0) !== Number(expected.website_id)
              || String(data.requested_scope?.website_code || '') !== String(expected.website_code)
              || String(data.requested_scope?.store_code || '') !== 'scope'
              || String(data.requested_scope?.channel_code || '') !== 'default'
              || String(data.text || '') !== (side === 'A' ? fixture.i18n_value_a : fixture.i18n_value_b)) {
              mismatches.push({ side, index, result });
            }
          });
        }

        expect(mismatches, JSON.stringify(mismatches.slice(0, 5))).toEqual([]);
        expect(requestIds.size).toBeGreaterThan(0);
        assertNoFatalConsole(a.consoleErrors);
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        a.page.off('response', collectRequestId);
        b.page.off('response', collectRequestId);
        await a.context.close();
        await b.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Meta', id: 'TEST-P1C-02' },
    'Meta public 配置按可信当前 Scope 隔离且不接受客户端 Scope',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      try {
        const params = {
          namespace: fixture.meta_namespace,
          config_key: fixture.meta_key,
          locale: fixture.locale,
        };
        const aResult = await runResourceApi(a.page, 'meta', 'resolvePublicCurrentScope', params);
        const bResult = await runResourceApi(b.page, 'meta', 'resolvePublicCurrentScope', params);
        expect(isSuccess(aResult), JSON.stringify(aResult)).toBeTruthy();
        expect(isSuccess(bResult), JSON.stringify(bResult)).toBeTruthy();
        const aData = pickData(aResult);
        const bData = pickData(bResult);
        expect(aData.value).toBe(fixture.meta_value_a);
        expect(bData.value).toBe(fixture.meta_value_b);
        expect(aData.requested_scope.website_id).toBe(fixture.a.website_id);
        expect(bData.requested_scope.website_id).toBe(fixture.b.website_id);
        expect(aData.source.source_kind).toBe('exact');
        expect(bData.source.source_kind).toBe('exact');
        expect(aData.source.storage_scope).not.toBe(bData.source.storage_scope);
        assertNoFatalConsole(a.consoleErrors);
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        await a.context.close();
        await b.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_I18n', id: 'TEST-P1C-04' },
    '两个真实 Store Scope 命中各自词条并返回来源证据',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      try {
        const params = {
          source: fixture.i18n_source,
          locale_code: fixture.locale,
        };
        const aResult = await runResourceApi(
          a.page,
          'i18n',
          'resolveCurrentScopeTranslation',
          params,
        );
        const bResult = await runResourceApi(
          b.page,
          'i18n',
          'resolveCurrentScopeTranslation',
          params,
        );
        expect(isSuccess(aResult), JSON.stringify(aResult)).toBeTruthy();
        expect(isSuccess(bResult), JSON.stringify(bResult)).toBeTruthy();
        const aData = pickData(aResult);
        const bData = pickData(bResult);
        expect(aData.text).toBe(fixture.i18n_value_a);
        expect(bData.text).toBe(fixture.i18n_value_b);
        expect(aData.found_scoped).toBe(true);
        expect(bData.found_scoped).toBe(true);
        expect(aData.source.source_kind).toBe('exact');
        expect(bData.source.source_kind).toBe('exact');
        expect(aData.source.lookup_word).not.toBe('');
        expect(bData.source.lookup_word).not.toBe('');
        assertNoFatalConsole(a.consoleErrors);
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        await a.context.close();
        await b.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Websites', id: 'TEST-SEC-03' },
    '错误 Store 断言与跨 Host Cart Scope 均 fail-closed',
    async () => {
      const assertionContext = await scopedBrowser.newContext({ ignoreHTTPSErrors: true });
      const assertionPage = await assertionContext.newPage();
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      try {
        const rejected = await gotoFrontend(
          assertionPage,
          `${fixture.a.url}?__store=default`,
          {
            timeout: 90000,
            waitUntil: 'domcontentloaded',
            settleMs: 200,
            ...DIRECT,
          },
        );
        expect(rejected && rejected.status()).toBe(409);
        await expect(assertionPage.locator('body')).toContainText(
          /Scope|范围|Host|URI|不一致/i,
        );

        const tokenResult = await runResourceApi(b.page, 'cart', 'issueGuestToken');
        expect(isSuccess(tokenResult), JSON.stringify(tokenResult)).toBeTruthy();
        const token = String(pickData(tokenResult).guest_token || tokenResult.guest_token || '');
        const crossScope = await runResourceApi(b.page, 'cart', 'getV2Cart', {
          guest_token: token,
          website_id: fixture.a.website_id,
          website_code: fixture.a.website_code,
          store_code: 'scope',
          channel_code: 'default',
          store_mode: 'normal',
        });
        expect(isSuccess(crossScope), JSON.stringify(crossScope)).toBeFalsy();
        expect(errorCode(crossScope), JSON.stringify(crossScope))
          .toBe('cart_scope_request_conflict');
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        await assertionContext.close();
        await b.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Framework', id: 'TEST-SEC-08' },
    'HTTPS 下商城与购物车 Cookie 属性满足安全基线',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      try {
        const tokenResult = await runResourceApi(a.page, 'cart', 'issueGuestToken');
        expect(isSuccess(tokenResult), JSON.stringify(tokenResult)).toBeTruthy();
        const cookies = await a.context.cookies(fixture.a.origin);
        const byName = Object.fromEntries(cookies.map((cookie) => [cookie.name, cookie]));
        for (const name of [
          'WELINE_USER_LANG',
          'WELINE_USER_CURRENCY',
          'WELINE_WEBSITE_ID',
          'WELINE_WEBSITE_CODE',
          'WELINE_WEBSITE_URL',
          'weline_cart_guest_token',
        ]) {
          expect(byName[name], `Missing HTTPS cookie ${name}: ${JSON.stringify(cookies)}`)
            .toBeTruthy();
          expect(byName[name].secure, `${name} must be Secure`).toBe(true);
          expect(byName[name].sameSite, `${name} must use SameSite=Lax`).toBe('Lax');
        }
        for (const name of [
          'WELINE_WEBSITE_ID',
          'WELINE_WEBSITE_CODE',
          'WELINE_WEBSITE_URL',
          'weline_cart_guest_token',
        ]) {
          expect(byName[name].httpOnly, `${name} must be HttpOnly`).toBe(true);
        }
        expect(await a.page.evaluate(() => location.protocol)).toBe('https:');
        assertNoFatalConsole(a.consoleErrors);
      } finally {
        await a.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Cart', id: 'TEST-P2E-01' },
    '同一 guest token 在两个真实 Website Host 的购物车互不串车',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      try {
        const tokenResult = await runResourceApi(a.page, 'cart', 'issueGuestToken');
        expect(isSuccess(tokenResult), JSON.stringify(tokenResult)).toBeTruthy();
        guestToken = String(pickData(tokenResult).guest_token || tokenResult.guest_token || '');
        expect(guestToken).not.toBe('');

        const offer = {
          provider_code: fixture.provider_code,
          global_offer_uuid: fixture.offer_uuid,
          selection: { color: 'blue' },
          guest_token: guestToken,
        };
        const addedA = await runResourceApi(a.page, 'cart', 'addV2', {
          ...offer,
          qty: 1,
        });
        const addedB = await runResourceApi(b.page, 'cart', 'addV2', {
          ...offer,
          qty: 3,
        });
        expect(isSuccess(addedA), JSON.stringify(addedA)).toBeTruthy();
        expect(isSuccess(addedB), JSON.stringify(addedB)).toBeTruthy();

        const cartA = pickData(await runResourceApi(
          a.page,
          'cart',
          'getV2Cart',
          { guest_token: guestToken },
        ));
        const cartB = pickData(await runResourceApi(
          b.page,
          'cart',
          'getV2Cart',
          { guest_token: guestToken },
        ));
        expect(Number(cartA.item_count || 0)).toBe(1);
        expect(Number(cartB.item_count || 0)).toBe(3);
        expect(String(cartA.owner_id || '')).toBe(guestToken);
        expect(String(cartB.owner_id || '')).toBe(guestToken);
        expect(String(cartA.scope_key || '')).not.toBe(String(cartB.scope_key || ''));
        expect(String(cartA.scope_key || '')).toContain(`|${fixture.a.website_id}|`);
        expect(String(cartB.scope_key || '')).toContain(`|${fixture.b.website_id}|`);
        assertNoFatalConsole(a.consoleErrors);
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        await a.context.close();
        await b.context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: 'Weline_Product', id: 'TEST-P2A-02' },
    'PostgreSQL 单站漂移隔离且 Browser 业务调用不执行 DDL',
    async () => {
      const a = await openScopePage(scopedBrowser, fixture.a.url);
      const b = await openScopePage(scopedBrowser, fixture.b.url);
      try {
        const readyA = pickData(await runResourceApi(
          a.page,
          'product_shard_status',
          'current',
        ));
        const readyB = pickData(await runResourceApi(
          b.page,
          'product_shard_status',
          'current',
        ));
        expect(readyA.database_driver).toBe('pgsql');
        expect(readyB.database_driver).toBe('pgsql');
        expect(readyA.website_id).toBe(fixture.a.website_id);
        expect(readyB.website_id).toBe(fixture.b.website_id);
        expect(readyA.status).toBe('ready');
        expect(readyB.status).toBe('ready');

        const drift = runFixture('drift', {
          website_ids: [fixture.a.website_id, fixture.b.website_id],
        });
        expect(drift.database_driver).toBe('pgsql');
        expect(drift.a_status).toBe('maintenance');
        expect(drift.a_writable).toBe(false);
        expect(drift.b_status).toBe('ready');
        expect(drift.b_writable).toBe(true);

        const harness = await runResourceApi(b.page, 'product_media', 'prepareHarness', {
          run_id: `p2a02-${fixture.token}`,
          website_id: fixture.b.website_id,
        });
        expect(isSuccess(harness), JSON.stringify(harness)).toBeTruthy();
        const blockedBusiness = await runResourceApi(a.page, 'product_media', 'runShareCow', {
          website_id: fixture.a.website_id,
          suffix: `${fixture.token}-a`,
        });
        expect(isSuccess(blockedBusiness), JSON.stringify(blockedBusiness)).toBeFalsy();
        expect(JSON.stringify(blockedBusiness)).toContain('maintenance');

        const healthyBusiness = await runResourceApi(b.page, 'product_media', 'runShareCow', {
          website_id: fixture.b.website_id,
          suffix: `${fixture.token}-b`,
        });
        expect(isSuccess(healthyBusiness), JSON.stringify(healthyBusiness)).toBeTruthy();
        await runResourceApi(b.page, 'product_media', 'clearHarness');

        const inspected = runFixture('inspect', {
          website_ids: [fixture.a.website_id, fixture.b.website_id],
          expected_schema_signature: drift.schema_signature,
        });
        expect(inspected.unchanged, JSON.stringify(inspected)).toBe(true);
        expect(inspected.schema_signature).toBe(drift.schema_signature);
        assertNoFatalConsole(a.consoleErrors);
        assertNoFatalConsole(b.consoleErrors);
      } finally {
        await a.context.close();
        await b.context.close();
      }
    },
  );
});
