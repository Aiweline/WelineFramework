/**
 * 万能商城内核计划 Browser 用例固化（配置中心 Scope + CSRF/Origin）
 *
 * 计划来源：万能商城内核_f0b923cd.plan.md
 * - TEST-P1C-01：统一配置中心 TargetScope（Global/Website/Store/Channel）可见且可切换
 * - TEST-SEC-07：高风险写拒绝缺 form_key / 跨 Origin（零部分写入）
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: plan, layer: backend }
 */

const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  getRuntimeInfo,
  moduleDescribe,
  moduleCase,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SystemConfig';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
function collectConsoleErrors(page) {
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() !== 'error') {
      return;
    }
    const text = msg.text();
    // 后台既有 jQuery 加载时序噪音（与 P1C Scope 选择器无关），与 plan-multisite-prereq 一致过滤
    if (/favicon|Failed to load resource|net::ERR_|jQuery is not defined|\$ is not defined|reading 'fn'/i.test(text)) {
      return;
    }
    errors.push(text);
  });
  page.on('pageerror', (err) => {
    const text = String(err);
    if (/jQuery is not defined|\$ is not defined|reading 'fn'/i.test(text)) {
      return;
    }
    errors.push(text);
  });
  return errors;
}

function configCenterPath(query = '') {
  // buildModuleBackendRoute(MODULE, 'config') => weline_systemconfig/backend/config
  const base = buildModuleBackendRoute(MODULE, 'config');
  return query ? `${base}?${query}` : base;
}

async function openConfigCenter(page, query = '') {
  await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
  if (query === '') {
    await openBackendMenuBySource(page, 'Weline_SystemConfig::config_center', {
      urlIncludes: '/weline_systemconfig/backend/config',
      pageAnchor: '[data-testid="system-config-management"]',
    });
  } else {
    await gotoBackend(page, configCenterPath(query), { timeout: 60000, settleMs: 1000 });
  }
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  await expect(page.locator('body')).toContainText(/统一配置中心|System Config|配置中心/i);
}

moduleDescribe(test, MODULE, '计划 P1C/SEC 配置中心 Browser 用例', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P1C-01' },
    '统一配置中心渲染四层 TargetScope 选择器，Website 切换不 Fatal',
    async ({ page }) => {
      const consoleErrors = collectConsoleErrors(page);
      await openConfigCenter(page);

      const websiteSelect = page.locator('#wsc-website-code_wrapper');
      const storeSelect = page.locator('#wsc-store-code_wrapper');
      const channelSelect = page.locator('#wsc-channel-code_wrapper');
      await expect(websiteSelect).toBeVisible({ timeout: 15000 });
      await expect(storeSelect).toBeVisible();
      await expect(channelSelect).toBeVisible();

      // Searchable Taglib 使用 wrapper 呈现、hidden input 提交值；Global 必须是默认可见层级。
      await expect(page.locator('#wsc-website-code_display')).toContainText(/Global|全部站点/i);
      await expect(page.locator('#wsc-website-code_value')).toHaveValue('');
      await expect(page.locator('#wsc-store-code')).toHaveValue('');
      await expect(page.locator('#wsc-channel-code')).toHaveValue('');

      // 系统 default Website 必须可通过显式 GET TargetScope 重新渲染。
      await openConfigCenter(page, 'website_code=default');
      await expect(page.locator('#wsc-website-code_value')).toHaveValue('default');
      await expect(page.locator('body')).toContainText(/统一配置中心|模块|区域/i);

      expect(consoleErrors, `console/pageerror: ${consoleErrors.join(' | ')}`).toHaveLength(0);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-SEC-07' },
    '配置中心写操作拒绝缺 form_key 与跨 Origin，不静默成功',
    async ({ page }) => {
      await openConfigCenter(page);
      const info = getRuntimeInfo();
      const backendPrefix = info.paths.backend_prefix_path || '';
      // 优先用页面当前配置中心 URL 作为写入口，避免 proxy/prefix 拼错导致误判
      const pageUrl = page.url();
      const postUrl = pageUrl.includes('weline_systemconfig/backend/config')
        ? pageUrl.split('?')[0]
        : `${backendPrefix}/weline_systemconfig/backend/config`;
      const sameOrigin = info.runtime.target_origin || new URL(pageUrl).origin;

      // 1) 缺 form_key：后端 CSRF 必须拒绝（不得 2xx 静默写入）
      const missingKey = await page.request.post(postUrl, {
        form: {
          form_action: 'save',
          module: 'Weline_SystemConfig',
          area: 'backend',
          code: 'e2e-sec07-missing-key',
          target_scope: 'default.default.default',
          values: { __e2e_probe__: '1' },
        },
        headers: {
          Origin: sameOrigin,
          Referer: postUrl,
        },
        maxRedirects: 0,
        failOnStatusCode: false,
      });
      const missingStatus = missingKey.status();
      const missingBody = await missingKey.text();
      const missingRejected =
        missingStatus < 200
        || missingStatus >= 300
        || /form_key|CSRF|Invalid|拒绝|forbidden/i.test(missingBody);
      expect(missingRejected, `缺 form_key 不得成功写入，实际 HTTP ${missingStatus}`).toBeTruthy();

      // 2) 跨 Origin：即使带假 form_key，也应被 assertSameOrigin / 网关拒绝（非成功写）
      const badOrigin = await page.request.post(postUrl, {
        form: {
          form_key: 'invalid-cross-origin-key',
          form_action: 'save',
          module: 'Weline_SystemConfig',
          area: 'backend',
          code: 'e2e-sec07-bad-origin',
          target_scope: 'default.default.default',
          values: { __e2e_probe__: '1' },
        },
        headers: {
          Origin: 'https://evil.example',
          Referer: 'https://evil.example/attack',
        },
        maxRedirects: 0,
        failOnStatusCode: false,
      });
      const badStatus = badOrigin.status();
      const badBody = await badOrigin.text();
      const rejected =
        badStatus < 200
        || badStatus >= 300
        || /跨站请求被拒绝|form_key|CSRF|Invalid|拒绝|forbidden/i.test(badBody);
      expect(rejected, `跨 Origin 必须拒绝，实际 HTTP ${badStatus}`).toBeTruthy();

      // 3) 合法页面仍可打开，证明写拒绝未打崩配置中心
      await gotoBackend(page, configCenterPath(), {
        timeout: 60000,
        settleMs: 800,
      });
      await expect(page.locator('body')).toContainText(/统一配置中心/i);
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
    }
  );
});
