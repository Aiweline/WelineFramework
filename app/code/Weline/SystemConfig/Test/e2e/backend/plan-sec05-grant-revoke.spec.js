/**
 * 万能商城内核计划：配置中心预览后撤权再提交（TEST-SEC-05）
 *
 * - 受限角色持有 Global Scope VIEW+UPDATE ObjectScopeGrant 时可打开配置中心并读取 expected_grant_version
 * - 撤权后带合法 form_key / expected_grant_version 的 save 被拒（操作授权条件不满足），零静默写入
 * - 撤权前同参数提交可越过对象授权（正向对照）
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: plan, layer: backend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SystemConfig';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-sec05-grant-fixture.php');

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
    throw new Error(`sec05 fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

function configCenterPath(query = '') {
  const base = buildModuleBackendRoute(MODULE, 'config');
  return query ? `${base}?${query}` : base;
}

function normalizeProbeValue(value) {
  if (value === true || value === 1 || value === '1') {
    return '1';
  }
  if (value === false || value === 0 || value === '0' || value === null || value === undefined) {
    return '0';
  }
  return String(value);
}

function flipProbe(value) {
  return normalizeProbeValue(value) === '1' ? '0' : '1';
}

async function openConfigCenterAs(page, username, password, query = '') {
  await loginAsAdmin(page, {
    username,
    password,
    timeout: 90000,
    settleMs: 800,
    useProxy: false,
  });
  await gotoBackend(page, configCenterPath(query), {
    timeout: 60000,
    settleMs: 1000,
    useProxy: false,
  });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
}

/**
 * 同页 XHR 提交，绕过可能被 Weline.Api 劫持的 window.fetch，并强制携带 Cookie。
 */
async function postConfigSave(page, postUrl, formFields, headers) {
  return page.evaluate(async ({ url, fields, hdrs }) => {
    const body = new URLSearchParams();
    Object.keys(fields).forEach((key) => {
      body.append(key, String(fields[key]));
    });
    return await new Promise((resolve) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('content-type', 'application/x-www-form-urlencoded');
      xhr.setRequestHeader('x-requested-with', 'XMLHttpRequest');
      Object.keys(hdrs || {}).forEach((key) => {
        try {
          xhr.setRequestHeader(key, String(hdrs[key]));
        } catch (_) {
          // forbidden header names are ignored
        }
      });
      xhr.onload = () => {
        resolve({
          status: xhr.status,
          url: xhr.responseURL || url,
          body: String(xhr.responseText || '').slice(0, 4000),
        });
      };
      xhr.onerror = () => {
        resolve({
          status: 0,
          url,
          body: 'xhr_network_error',
        });
      };
      xhr.send(body.toString());
    });
  }, { url: postUrl, fields: formFields, hdrs: headers });
}

moduleDescribe(test, MODULE, '计划 SEC-05 配置中心撤权重鉴权', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-SEC-05' },
    '预览后撤权再提交：配置中心 save 返回对象授权拒绝且零写入',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      const originalProbe = normalizeProbeValue(fixture.probe && fixture.probe.value);
      let cleaned = false;

      try {
        await openConfigCenterAs(
          page,
          fixture.username,
          fixture.password,
          `target_scope=${encodeURIComponent(fixture.target_scope)}&module=${encodeURIComponent(fixture.probe_module)}`,
        );
        await expect(page.locator('body')).toContainText(/统一配置中心|System Config|配置中心/i);

        const formKey = await page.locator('input[name="form_key"]').first().inputValue();
        const grantVersionRaw = await page.locator('input[name="expected_grant_version"]').first().inputValue();
        const grantVersion = Number(grantVersionRaw);
        expect(formKey, '页面必须提供 Session form_key').toBeTruthy();
        expect(grantVersion, `expected_grant_version 必须等于夹具版本 ${fixture.grant_version}`).toBe(
          Number(fixture.grant_version),
        );

        const info = getRuntimeInfo();
        const pageUrl = page.url();
        const postUrl = pageUrl.includes('weline_systemconfig/backend/config')
          ? pageUrl.split('?')[0]
          : `${info.paths.backend_prefix_path || ''}/weline_systemconfig/backend/config`;
        const sameOrigin = info.runtime.target_origin || new URL(pageUrl).origin;
        const flipped = flipProbe(originalProbe);

        const saveFormBase = {
          form_action: 'save',
          module: fixture.probe_module,
          area: fixture.probe_area,
          code: fixture.probe_code,
          target_scope: fixture.target_scope,
        };

        // 正向：撤权前同会话提交必须越过对象授权（允许保存成功，或其它非授权业务错误）
        const allowed = await postConfigSave(page, postUrl, {
          ...saveFormBase,
          form_key: formKey,
          expected_grant_version: String(grantVersion),
          [`values[${fixture.probe_key}]`]: flipped,
        }, {
          Origin: sameOrigin,
          Referer: pageUrl,
        });
        const allowedBody = allowed.body || '';
        expect(
          /操作授权条件不满足|object_scope_access_denied/i.test(allowedBody),
          `撤权前不得对象授权拒绝：HTTP ${allowed.status} body=${allowedBody.slice(0, 400)}`,
        ).toBeFalsy();
        expect(
          /配置已保存|版本冲突|未在配置模板声明|配置保存失败/i.test(allowedBody)
            || allowed.status === 200
            || allowed.status === 302,
          `撤权前提交应完成鉴权路径：HTTP ${allowed.status}`,
        ).toBeTruthy();

        const afterAllowed = runFixture('read_probe');
        const probeAtGrant = normalizeProbeValue(afterAllowed.value);

        // form_key 一次性：重新登录拿新 key，再撤权（撤权后 GET 也会 403，必须先取 key）
        await page.context().clearCookies();
        await openConfigCenterAs(
          page,
          fixture.username,
          fixture.password,
          `target_scope=${encodeURIComponent(fixture.target_scope)}&module=${encodeURIComponent(fixture.probe_module)}`,
        );
        const formKey2 = await page.locator('input[name="form_key"]').first().inputValue();
        const grantVersion2 = Number(
          await page.locator('input[name="expected_grant_version"]').first().inputValue(),
        );
        expect(formKey2, '二次登录必须提供新 form_key').toBeTruthy();
        expect(grantVersion2).toBe(Number(fixture.grant_version));
        const pageUrl2 = page.url();
        const postUrl2 = pageUrl2.includes('weline_systemconfig/backend/config')
          ? pageUrl2.split('?')[0]
          : postUrl;

        const revoked = runFixture('revoke', {
          role_id: fixture.role_id,
          grant_id: fixture.grant_id,
        });
        expect(revoked.remaining_grants, '撤权后角色不得再持有 ObjectScopeGrant').toBe(0);

        const blockedFlip = flipProbe(probeAtGrant);
        const blocked = await postConfigSave(page, postUrl2, {
          ...saveFormBase,
          form_key: formKey2,
          expected_grant_version: String(grantVersion2),
          [`values[${fixture.probe_key}]`]: blockedFlip,
        }, {
          Origin: sameOrigin,
          Referer: pageUrl2,
        });
        const blockedStatus = blocked.status;
        const blockedBody = blocked.body || '';
        const denied =
          blockedStatus === 403
          || /操作授权条件不满足|object_scope_access_denied/i.test(blockedBody);
        expect(
          denied,
          `撤权后 save 必须对象授权拒绝：HTTP ${blockedStatus} url=${blocked.url} body=${blockedBody.slice(0, 600)}`,
        ).toBeTruthy();
        expect(
          /配置已保存/i.test(blockedBody),
          `撤权后不得出现配置已保存：HTTP ${blockedStatus}`,
        ).toBeFalsy();

        const afterBlocked = runFixture('read_probe');
        expect(
          normalizeProbeValue(afterBlocked.value),
          `撤权后探针不得被写入（零静默写入）：before=${probeAtGrant} after=${afterBlocked.value}`,
        ).toBe(probeAtGrant);
      } finally {
        try {
          runFixture('restore_probe', { value: originalProbe });
        } catch (_) {
          // best-effort restore
        }
        runFixture('cleanup', {
          role_id: fixture.role_id,
          user_id: fixture.user_id,
        });
        cleaned = true;
      }

      expect(cleaned).toBeTruthy();
    },
  );
});
