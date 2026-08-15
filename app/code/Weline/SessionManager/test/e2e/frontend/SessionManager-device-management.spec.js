/**
 * Weline_SessionManager 前台顾客双设备下线流程。
 *
 * @weline-e2e-spec { module: Weline_SessionManager, type: flow, layer: frontend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SessionManager';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'session-manager-device-management-fixture.php');
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const DIRECT = { useProxy: false };

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

async function loginCustomer(page, account) {
  await gotoFrontend(page, '/customer/account/login', { timeout: 60000, settleMs: 600, ...DIRECT });
  const form = page.locator('#loginForm');
  await expect(form).toBeVisible({ timeout: 30000 });
  await form.locator('input[name="username"]').fill(account.email);
  await form.locator('input[name="password"]').fill(account.password);
  await form.locator('select[name="remember_duration"]').selectOption('21600');
  await prepareLocalCaptcha(page);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/customer/account/login'), {
      timeout: 60000,
      waitUntil: 'commit',
    }),
    form.locator('button[type="submit"]').click(),
  ]);
  await gotoFrontend(page, '/customer/account/index#devices', { timeout: 60000, settleMs: 800, ...DIRECT });
  await expect(page).not.toHaveURL(/customer\/account\/login/);
}

async function openDevices(page) {
  const nav = page.locator('[data-account-nav-link="true"][data-section="devices"]').first();
  await expect(nav).toBeVisible({ timeout: 30000 });
  await nav.click();
  const root = page.locator('[data-device-manager="frontend"]');
  await expect(root).toBeVisible({ timeout: 30000 });
  await expect(root.locator('[data-device-loading]')).toBeHidden({ timeout: 30000 });
  await expect(page.locator('body')).not.toContainText(FATAL);
  return root;
}

moduleDescribe(test, MODULE, '顾客设备管理', () => {
  let account;

  test.beforeAll(() => {
    account = fixture('prepare', { token: `pw${Date.now()}` });
  });

  test.afterAll(() => {
    if (account?.customer_id) fixture('cleanup', { customer_id: account.customer_id });
  });

  moduleCase(
    test,
    { module: MODULE, id: 'SESSION-DEVICE-FRONTEND-001' },
    '顾客 A 下线顾客 B 后，B 的 Session 与记住凭证都不能恢复，A 保持登录',
    async ({ browser }) => {
      const contextA = await browser.newContext({ ignoreHTTPSErrors: true });
      const contextB = await browser.newContext({ ignoreHTTPSErrors: true });
      const pageA = await contextA.newPage();
      const pageB = await contextB.newPage();
      const consoleErrors = [];
      for (const page of [pageA, pageB]) {
        page.on('console', (message) => {
          if (message.type() === 'error' && !/favicon|Failed to load resource/i.test(message.text())) {
            consoleErrors.push(message.text());
          }
        });
      }
      try {
        await loginCustomer(pageA, account);
        const devicesAfterA = fixture('inspect', { customer_id: account.customer_id });
        expect(devicesAfterA.device_count).toBe(1);
        expect(devicesAfterA.distinct_session_count).toBe(1);
        expect(devicesAfterA.credential_count).toBe(1);

        await loginCustomer(pageB, account);
        const sessionCookieA = (await contextA.cookies()).find((cookie) => cookie.name.includes('WELINE_SESSID'));
        const sessionCookieB = (await contextB.cookies()).find((cookie) => cookie.name.includes('WELINE_SESSID'));
        expect(Boolean(sessionCookieA?.value && sessionCookieB?.value)).toBeTruthy();
        expect(sessionCookieA?.value === sessionCookieB?.value).toBeFalsy();
        const devicesAfterB = fixture('inspect', { customer_id: account.customer_id });
        expect(devicesAfterB.device_count).toBe(2);
        expect(devicesAfterB.distinct_session_count).toBe(2);
        expect(devicesAfterB.credential_count).toBe(2);

        await pageA.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
        const rootA = await openDevices(pageA);
        const rootB = await openDevices(pageB);

        const cookiesB = await contextB.cookies();
        expect(cookiesB.some((cookie) => cookie.name.includes('w_frontend_ut'))).toBeTruthy();

        const deviceIdB = await rootB
          .locator('[data-device-id][data-device-current="1"]')
          .first()
          .getAttribute('data-device-id');
        expect(deviceIdB).toBeTruthy();

        const currentA = rootA.locator('[data-device-id][data-device-current="1"]').first();
        const otherB = rootA.locator(`[data-device-id="${deviceIdB}"]`).first();
        await expect(currentA).toBeVisible();
        await expect(currentA.locator('[data-device-revoke]')).toHaveCount(0);
        await expect(otherB).toBeVisible();
        await otherB.locator('[data-device-revoke]').click();
        await expect(otherB.locator('[data-device-confirm]')).toBeVisible();
        await otherB.locator('[data-device-confirm]').click();
        await expect(otherB).toHaveCount(0, { timeout: 30000 });

        await pageB.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
        await expect(pageB).toHaveURL(/customer\/account\/login/, { timeout: 30000 });
        await expect(pageB.locator('form').first()).toBeVisible();
        const cookiesAfterRevoke = await contextB.cookies();
        expect(cookiesAfterRevoke.some((cookie) => cookie.name.includes('w_frontend_ut'))).toBeFalsy();

        await pageA.reload({ waitUntil: 'domcontentloaded' });
        await expect(pageA).not.toHaveURL(/customer\/account\/login/);
        await expect(pageA.locator('[data-account-nav-link="true"][data-section="devices"]')).toBeVisible();
        expect(consoleErrors, `console error: ${consoleErrors.join(' | ')}`).toHaveLength(0);
      } finally {
        await contextA.close();
        await contextB.close();
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SESSION-DEVICE-FRONTEND-002' },
    '记住凭证在 Session Cookie 丢失后恢复原设备并轮换 Token',
    async ({ browser }) => {
      const restoreAccount = fixture('prepare', { token: `remember${Date.now()}` });
      const context = await browser.newContext({ ignoreHTTPSErrors: true });
      const page = await context.newPage();
      const consoleErrors = [];
      page.on('console', (message) => {
        if (message.type() === 'error' && !/favicon|Failed to load resource/i.test(message.text())) {
          consoleErrors.push(message.text());
        }
      });
      try {
        await loginCustomer(page, restoreAccount);
        const issuedCookies = await context.cookies();
        const issuedSessionCookies = issuedCookies.filter((cookie) => cookie.name.includes('WELINE_SESSID'));
        expect(issuedSessionCookies).toHaveLength(1);
        const sessionCookie = issuedSessionCookies[0];
        const rememberedCookie = issuedCookies.find((cookie) => cookie.name.includes('w_frontend_ut'));
        expect(Boolean(sessionCookie?.name && rememberedCookie?.value)).toBeTruthy();

        await context.clearCookies({ name: sessionCookie.name });
        const cookiesAfterSessionClear = await context.cookies();
        expect(cookiesAfterSessionClear.filter((cookie) => cookie.name.includes('WELINE_SESSID'))).toHaveLength(0);
        const restorePage = await context.newPage();
        restorePage.on('console', (message) => {
          if (message.type() === 'error' && !/favicon|Failed to load resource/i.test(message.text())) {
            consoleErrors.push(message.text());
          }
        });
        const restoreResponse = await gotoFrontend(restorePage, '/customer/account/index#devices', {
          timeout: 60000,
          settleMs: 800,
          ...DIRECT,
        });
        expect(restoreResponse).not.toBeNull();
        const navigationRequests = [];
        let navigationRequest = restoreResponse.request();
        while (navigationRequest) {
          navigationRequests.unshift(navigationRequest);
          navigationRequest = navigationRequest.redirectedFrom();
        }
        const initialCookieHeader = (await navigationRequests[0].headerValue('cookie')) || '';
        expect(initialCookieHeader.includes(`${sessionCookie.name}=`)).toBeFalsy();
        expect(initialCookieHeader.includes(`${rememberedCookie.name}=`)).toBeTruthy();
        const restoreSetCookies = [];
        for (const request of navigationRequests) {
          const response = await request.response();
          if (!response) continue;
          restoreSetCookies.push(...(await response.headersArray())
            .filter((header) => header.name.toLowerCase() === 'set-cookie')
            .map((header) => header.value));
        }
        const restoreCookieNames = restoreSetCookies.map((value) => value.split('=', 1)[0]);
        const restoredSessionHeader = restoreSetCookies.find((value) =>
          value.startsWith(`${sessionCookie.name}=`),
        );
        expect(restoreCookieNames).toContain(sessionCookie.name);
        expect(/;\s*Secure/i.test(restoredSessionHeader || '')).toBeTruthy();
        expect(/SameSite=None/i.test(restoredSessionHeader || '')).toBeTruthy();
        expect(/Partitioned/i.test(restoredSessionHeader || '')).toBeTruthy();
        await expect(restorePage).not.toHaveURL(/customer\/account\/login/);
        await expect(restorePage.locator('[data-account-nav-link="true"][data-section="devices"]')).toBeVisible();

        const restoredCookies = await context.cookies();
        const restoredSession = restoredCookies.find((cookie) => cookie.name.includes('WELINE_SESSID'));
        const rotatedRemembered = restoredCookies.find((cookie) => cookie.name === rememberedCookie.name);
        expect(Boolean(restoredSession?.value)).toBeTruthy();
        expect(restoredSession?.name === sessionCookie.name).toBeTruthy();
        expect(Boolean(rotatedRemembered?.value)).toBeTruthy();
        expect(rotatedRemembered?.value === rememberedCookie.value).toBeFalsy();
        const restoredDevice = fixture('inspect', { customer_id: restoreAccount.customer_id });
        expect(restoredDevice.device_count).toBe(1);
        expect(restoredDevice.distinct_session_count).toBe(1);
        expect(restoredDevice.credential_count).toBe(1);
        expect(consoleErrors, `console error: ${consoleErrors.join(' | ')}`).toHaveLength(0);
      } finally {
        await context.close();
        fixture('cleanup', { customer_id: restoreAccount.customer_id });
      }
    },
  );
});
