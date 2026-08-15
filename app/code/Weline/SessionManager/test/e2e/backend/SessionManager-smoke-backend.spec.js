/**
 * Weline_SessionManager 后台设备管理确定性流程。
 *
 * @weline-e2e-spec { module: Weline_SessionManager, type: flow, layer: backend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SessionManager';
const ROUTE = 'session-manager/backend/device';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ADMIN_USERNAME = process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, '../frontend/session-manager-device-management-fixture.php');

function fixture(action, payload = {}) {
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const parsed = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).pop() || '{}');
  if (!parsed.ok) throw new Error(parsed.error || output);
  return parsed;
}

async function prepareLocalCaptcha(page) {
  const challenge = page.locator('[data-weline-captcha-provider="local_image"]').first();
  if (!(await challenge.isVisible({ timeout: 1500 }).catch(() => false))) return;
  const captchaToken = await challenge.locator('input[name="captcha_token"]').inputValue();
  const prepared = fixture('prepare_captcha', { captcha_token: captchaToken });
  await challenge.locator('input[name="captcha_response"]').fill(prepared.answer);
}

async function loginAdminWithRemember(page) {
  await gotoBackend(page, 'admin/login', { timeout: 60000, settleMs: 500, useProxy: false });
  await page.locator('input[name="username"], input[type="text"]').first().fill(ADMIN_USERNAME);
  await page.locator('input[name="password"], input[type="password"]').first().fill(ADMIN_PASSWORD);
  const remember = page.locator('input[name="remember"]').first();
  await expect(remember).toBeVisible({ timeout: 10000 });
  await remember.check();
  await prepareLocalCaptcha(page);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/admin/login'), {
      timeout: 60000,
      waitUntil: 'commit',
    }),
    page.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
}

async function monitorBackendQueries(page) {
  const diagnostics = { requests: 0, responses: [] };
  page.on('request', (request) => {
    if (new URL(request.url()).pathname === '/api/framework/query-bin') diagnostics.requests += 1;
  });
  page.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/framework/query-bin') {
      diagnostics.responses.push(response.status());
    }
  });
  await page.addInitScript(() => {
    window.__welineBackendBootstrapFailures = [];
    window.addEventListener('weline:backend-bootstrap-failed', (event) => {
      const detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
      window.__welineBackendBootstrapFailures.push({
        code: String(detail.code || ''),
        status: Number(detail.status || 0),
      });
    });
  });
  return diagnostics;
}

async function currentSessionCookies(context) {
  const cookies = (await context.cookies()).filter((cookie) => cookie.name.startsWith('WELINE_SESSID'));
  if (cookies.length === 0) throw new Error('Authenticated browser has no Session cookie.');
  return Object.fromEntries(cookies.map((cookie) => [cookie.name, cookie.value]));
}

async function sessionCookieDiagnostics(context, expectedValues) {
  const cookies = (await context.cookies()).filter((cookie) => cookie.name.startsWith('WELINE_SESSID'));
  const names = cookies.map((cookie) => cookie.name);
  const expectedNames = Object.keys(expectedValues || {});
  return {
    count: cookies.length,
    names,
    unchanged: cookies.every((cookie) => expectedValues[cookie.name] === cookie.value)
      && names.length === expectedNames.length,
    added: names.filter((name) => !expectedNames.includes(name)),
    missing: expectedNames.filter((name) => !names.includes(name)),
    secure: cookies.map((cookie) => cookie.secure),
    http_only: cookies.map((cookie) => cookie.httpOnly),
    same_site: cookies.map((cookie) => cookie.sameSite),
    partitioned: cookies.map((cookie) => Boolean(cookie.partitionKey)),
    paths: cookies.map((cookie) => cookie.path),
  };
}

async function openDeviceManager(page, queryDiagnostics, expectedSessionCookies) {
  await gotoBackend(page, ROUTE, { timeout: 60000, settleMs: 800, useProxy: false });
  await waitForBackendShellReady(page);
  const root = page.locator('[data-device-manager="backend"]');
  await expect(root).toBeVisible({ timeout: 30000 });
  await expect(root.locator('h1, h2').first()).toContainText('设备管理');
  const attestation = await page.evaluate(() => {
    const markers = Array.from(document.querySelectorAll('meta[name="weline-worker-backend-bootstrap"]'));
    return {
      marker_count: markers.length,
      marker_valid: markers.length === 1 && /^[A-Za-z0-9_-]{43}$/.test(markers[0].content || ''),
      inert_slot_count: document.querySelectorAll('meta[name="weline-worker-backend-bootstrap-slot"]').length,
    };
  });
  expect(attestation).toEqual({ marker_count: 1, marker_valid: true, inert_slot_count: 0 });
  await expect(root.locator('[data-device-loading]')).toBeHidden({ timeout: 30000 });
  if (await root.locator('[data-device-error]').isVisible()) {
    const bootstrapFailures = await page.evaluate(() => window.__welineBackendBootstrapFailures || []);
    const message = await root.locator('[data-device-error-message]').textContent();
    throw new Error(`Device list failed: ${String(message || '').trim()}; diagnostics=${JSON.stringify({
      query: queryDiagnostics || {},
      bootstrap_failures: bootstrapFailures,
      session_cookie: await sessionCookieDiagnostics(page.context(), expectedSessionCookies),
    })}`);
  }
  await expect(page.locator('body')).not.toContainText(FATAL);
  return root;
}

moduleDescribe(test, MODULE, '后台设备管理', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SESSION-DEVICE-BACKEND-001' },
    '设备管理固定路由可达、当前设备置顶且无控制台错误',
    async ({ page }) => {
      const queryDiagnostics = await monitorBackendQueries(page);
      const consoleErrors = [];
      page.on('console', (message) => {
        if (message.type() === 'error' && !/favicon|Failed to load resource/i.test(message.text())) {
          consoleErrors.push(message.text());
        }
      });
      await loginAdminWithRemember(page);
      const initialSessionCookies = await currentSessionCookies(page.context());
      const root = await openDeviceManager(page, queryDiagnostics, initialSessionCookies);

      const cards = root.locator('[data-device-id]');
      await expect(cards.first()).toHaveAttribute('data-device-current', '1');
      await expect(cards.first().locator('[data-device-revoke]')).toHaveCount(0);
      expect(consoleErrors, `console error: ${consoleErrors.join(' | ')}`).toHaveLength(0);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SESSION-DEVICE-BACKEND-002' },
    '管理员 A 下线管理员 B 后，B 下一次认证请求回到登录页且 A 不受影响',
    async ({ browser, page }) => {
      const otherContext = await browser.newContext({ ignoreHTTPSErrors: true });
      const otherPage = await otherContext.newPage();
      try {
        const queryDiagnosticsA = await monitorBackendQueries(page);
        const queryDiagnosticsB = await monitorBackendQueries(otherPage);
        await loginAdminWithRemember(page);
        await loginAdminWithRemember(otherPage);
        const initialSessionCookiesA = await currentSessionCookies(page.context());
        const initialSessionCookiesB = await currentSessionCookies(otherContext);
        const rootA = await openDeviceManager(page, queryDiagnosticsA, initialSessionCookiesA);
        const rootB = await openDeviceManager(otherPage, queryDiagnosticsB, initialSessionCookiesB);

        const deviceIdB = await rootB
          .locator('[data-device-id][data-device-current="1"]')
          .first()
          .getAttribute('data-device-id');
        expect(deviceIdB).toBeTruthy();
        const cookiesB = await otherContext.cookies();
        expect(cookiesB.some((cookie) => cookie.name.includes('w_backend_ut'))).toBeTruthy();

        const otherCard = rootA.locator(`[data-device-id="${deviceIdB}"]`).first();
        await expect(otherCard).toBeVisible({ timeout: 30000 });
        await otherCard.locator('[data-device-revoke]').click();
        await expect(otherCard.locator('[data-device-confirm]')).toBeVisible();
        await otherCard.locator('[data-device-confirm]').click();
        await expect(otherCard).toHaveCount(0, { timeout: 30000 });

        await gotoBackend(otherPage, ROUTE, { timeout: 60000, settleMs: 800, useProxy: false });
        await expect(otherPage).toHaveURL(/admin\/login/, { timeout: 30000 });
        await expect(otherPage.locator('form[action*="admin/login/post"]')).toBeVisible();
        const cookiesAfterRevoke = await otherContext.cookies();
        const rememberedCookiesAfterRevoke = cookiesAfterRevoke.filter(
          (cookie) => cookie.name.includes('w_backend_ut'),
        );
        const rememberedCookieDiagnostics = rememberedCookiesAfterRevoke.map((cookie) => ({
          name: cookie.name,
          path: cookie.path,
          secure: cookie.secure,
          http_only: cookie.httpOnly,
          same_site: cookie.sameSite,
          partitioned: Boolean(cookie.partitionKey),
        }));
        expect(
          rememberedCookiesAfterRevoke,
          `stale backend remember cookies: ${JSON.stringify(rememberedCookieDiagnostics)}`,
        ).toHaveLength(0);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await waitForBackendShellReady(page);
        await expect(page).not.toHaveURL(/admin\/login/);
        await expect(page.locator('[data-device-manager="backend"]')).toBeVisible();
      } finally {
        await otherContext.close();
      }
    },
  );
});
