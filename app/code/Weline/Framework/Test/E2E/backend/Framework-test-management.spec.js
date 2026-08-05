// @weline-e2e-runtime fallback
// @ts-check
/**
 * 后台「测试管理」交互固化：模块总览、模块详情、空选择保护、单条/整模块运行入参。
 *
 * 不对被测模块 E2E 最终 pass/fail 做断言；运行类操作通过
 * window.__WelineFrameworkTest.setCallImpl 拦截 runE2e/runUnit，避免嵌套 Playwright。
 *
 * @weline-e2e-spec { module: Weline_Framework, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Framework';
const TARGET_MODULE = 'Weline_Acl';
const FATAL_PATTERN =
  /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;
const STUB_RUN_ID = 900001;

async function waitForBackendShellReady(page) {
  const loading = page.locator('section#loading.loader-section, section#loading');
  if ((await loading.count()) > 0) {
    await loading.first().waitFor({ state: 'hidden', timeout: 90000 }).catch(async () => {
      await page.evaluate(() => {
        document.querySelectorAll('section#loading').forEach((el) => {
          el.style.display = 'none';
          el.setAttribute('hidden', 'hidden');
          el.classList.add('d-none');
        });
      });
    });
  }
}

async function gotoModules(page) {
  const route = buildModuleBackendRoute(MODULE, 'test');
  await gotoBackend(page, route, { timeout: 60000, settleMs: 1500 });
  await waitForBackendShellReady(page);
  await expect(page.locator('#framework-test-app')).toBeVisible({ timeout: 20000 });
  await expect(page.locator('#ft-module-rows tr').first()).toBeVisible({ timeout: 30000 });
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
}

async function gotoModuleCases(page) {
  const route =
    buildModuleBackendRoute(MODULE, 'test/module') +
    '?module=' +
    encodeURIComponent(TARGET_MODULE);
  await gotoBackend(page, route, { timeout: 60000, settleMs: 1500 });
  await waitForBackendShellReady(page);
  await expect(page.locator('#framework-test-app')).toBeVisible({ timeout: 20000 });
  await expect(page.locator('#ft-panel-module')).toBeVisible({ timeout: 20000 });

  const checks = page.locator('#ft-case-rows .ft-case-check');
  try {
    await expect(checks.first()).toBeVisible({ timeout: 20000 });
  } catch (_error) {
    await page.locator('#ft-refresh').click({ force: true });
    await waitForBackendShellReady(page);
    await expect(checks.first()).toBeVisible({ timeout: 30000 });
  }
}

/** 拦截 runE2e/runUnit 并禁止跳转到运行页。 */
async function installRunCapture(page) {
  await page.waitForFunction(
    () => !!(window.__WelineFrameworkTest && typeof window.__WelineFrameworkTest.setCallImpl === 'function'),
    null,
    { timeout: 20000 }
  );
  await page.evaluate((stubRunId) => {
    const hooks = window.__WelineFrameworkTest;
    window.__ftCapturedRuns = [];
    hooks.suppressNavigate = true;
    const realCall = hooks.defaultCall;
    hooks.setCallImpl(async (operation, params) => {
      if (operation === 'runE2e' || operation === 'runUnit') {
        window.__ftCapturedRuns.push({
          op: operation,
          params: JSON.parse(JSON.stringify(params || {})),
          at: Date.now(),
        });
        return { run_id: stubRunId };
      }
      return realCall(operation, params);
    });
  }, STUB_RUN_ID);
}

async function capturedRuns(page) {
  return page.evaluate(() => window.__ftCapturedRuns || []);
}

async function clearCapturedRuns(page) {
  await page.evaluate(() => {
    window.__ftCapturedRuns = [];
  });
}

async function setUiEnabledPersist(page, enabled) {
  const box = page.locator('#ft-ui-enabled');
  const currently = await box.isChecked();
  if (currently === enabled) {
    if (enabled) {
      await expect(box).toBeChecked();
    } else {
      await expect(box).not.toBeChecked();
    }
    return;
  }
  if (enabled) {
    await box.check({ force: true });
  } else {
    await box.uncheck({ force: true });
  }
  await expect(page.locator('#ft-status')).toContainText(
    enabled ? /已保存：UI 测试开启|Saved: UI testing on/i : /已保存：UI 测试关闭|Saved: UI testing off/i,
    { timeout: 15000 }
  );
  if (enabled) {
    await expect(box).toBeChecked();
  } else {
    await expect(box).not.toBeChecked();
  }
}

async function waitRunAccepted(page) {
  await expect(page.locator('#ft-status')).toContainText(
    new RegExp(String(STUB_RUN_ID) + '|已创建运行|created run|started', 'i'),
    { timeout: 15000 }
  );
}

moduleDescribe(test, MODULE, '后台测试管理功能固化', () => {
  test.describe.configure({ mode: 'serial', retries: 1 });

  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await loginAsAdmin(page, { timeout: 120000 });
  });

  test.afterAll(async () => {
    if (page) {
      await page.close();
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-001' },
    '模块总览页加载目录并展示模块直跑按钮',
    async () => {
      await gotoModules(page);

      await expect(page.locator('#ft-ui-enabled')).toBeVisible();
      await setUiEnabledPersist(page, false);
      await expect(page.locator('#ft-ui-enabled')).not.toBeChecked();
      await expect(page.locator('#ft-refresh')).toBeVisible();
      await expect(page.locator('#ft-panel-modules')).toBeVisible();

      const aclRow = page.locator('#ft-module-rows tr', { hasText: TARGET_MODULE });
      await expect(aclRow).toBeVisible({ timeout: 30000 });
      await expect(aclRow.locator('a[href*="module="]')).toContainText(/查看用例|cases/i);
      await expect(
        aclRow.locator('button[data-ft-run-module][data-type="e2e"]')
      ).toBeEnabled();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-002' },
    '模块详情列出 E2E 用例并支持复选与运行选中文案',
    async () => {
      await gotoModuleCases(page);

      await expect(page.locator('#ft-module-title')).toContainText(TARGET_MODULE);
      await expect(page.locator('#ft-run-selected')).toBeVisible();
      await expect(page.locator('#ft-run-all')).toBeVisible();
      await expect(page.locator('#ft-run-selected')).toContainText(/运行选中|Run selected/i);
      await expect(page.locator('#ft-run-selected')).toContainText('(0)');

      const firstCheck = page.locator('#ft-case-rows .ft-case-check').first();
      await firstCheck.check({ force: true });
      await expect(page.locator('#ft-run-selected')).toContainText('(1)');
      await expect(page.locator('#ft-case-rows [data-ft-run-case]').first()).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-003' },
    '未选择用例时「运行选中」阻断且不提交 runE2e',
    async () => {
      await gotoModuleCases(page);
      await installRunCapture(page);
      await clearCapturedRuns(page);
      await waitForBackendShellReady(page);

      // 确保无勾选
      await page.locator('#ft-check-all').uncheck({ force: true }).catch(() => {});
      await page.locator('#ft-run-selected').click({ force: true });
      await expect(page.locator('#ft-status')).toBeVisible({ timeout: 10000 });
      await expect(page.locator('#ft-status')).toContainText(/请至少选择一条用例|no_selection/i);

      const runs = await capturedRuns(page);
      expect(runs, JSON.stringify(runs)).toEqual([]);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-004' },
    '单条「运行此用例」提交仅含该文件的 files',
    async () => {
      await gotoModuleCases(page);
      await installRunCapture(page);
      await clearCapturedRuns(page);
      await waitForBackendShellReady(page);
      await setUiEnabledPersist(page, false);

      const runCaseBtn = page.locator('#ft-case-rows [data-ft-run-case]').first();
      const file = await runCaseBtn.getAttribute('data-file');
      expect(file).toBeTruthy();

      await runCaseBtn.click({ force: true });
      await waitRunAccepted(page);

      const runs = await capturedRuns(page);
      expect(runs.length).toBeGreaterThanOrEqual(1);
      const last = runs[runs.length - 1];
      expect(last.op).toBe('runE2e');
      expect(last.params.module).toBe(TARGET_MODULE);
      expect(last.params.files).toEqual([file]);
      expect(last.params.ui_enabled).toBe(false);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-005' },
    '「运行整个模块」以空 files 提交整模块 E2E',
    async () => {
      await gotoModuleCases(page);
      await installRunCapture(page);
      await clearCapturedRuns(page);
      await waitForBackendShellReady(page);
      await setUiEnabledPersist(page, true);

      await page.locator('#ft-run-all').click({ force: true });
      await waitRunAccepted(page);

      const runs = await capturedRuns(page);
      expect(runs.length).toBeGreaterThanOrEqual(1);
      const last = runs[runs.length - 1];
      expect(last.op).toBe('runE2e');
      expect(last.params.module).toBe(TARGET_MODULE);
      expect(last.params.files).toEqual([]);
      expect(last.params.ui_enabled).toBe(true);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-006' },
    '模块总览直跑 E2E 以空 files 提交目标模块',
    async () => {
      await gotoModules(page);

      const aclRow = page.locator('#ft-module-rows tr', { hasText: TARGET_MODULE });
      await expect(aclRow).toBeVisible({ timeout: 30000 });
      const runE2e = aclRow.locator('button[data-ft-run-module][data-type="e2e"]');
      await expect(runE2e).toBeEnabled();

      await installRunCapture(page);
      await clearCapturedRuns(page);
      await waitForBackendShellReady(page);
      await setUiEnabledPersist(page, false);

      await runE2e.click({ force: true });
      await waitRunAccepted(page);

      const runs = await capturedRuns(page);
      expect(runs.length).toBeGreaterThanOrEqual(1);
      const last = runs[runs.length - 1];
      expect(last.op).toBe('runE2e');
      expect(last.params.module).toBe(TARGET_MODULE);
      expect(last.params.files).toEqual([]);
      expect(last.params.ui_enabled).toBe(false);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-007' },
    '总览开启 UI 测试后行内直跑必须提交 ui_enabled=true',
    async () => {
      await gotoModules(page);

      const aclRow = page.locator('#ft-module-rows tr', { hasText: TARGET_MODULE });
      await expect(aclRow).toBeVisible({ timeout: 30000 });
      const runE2e = aclRow.locator('button[data-ft-run-module][data-type="e2e"]');
      await expect(runE2e).toBeEnabled();

      await installRunCapture(page);
      await clearCapturedRuns(page);
      await waitForBackendShellReady(page);
      await setUiEnabledPersist(page, true);

      await runE2e.click({ force: true });
      await waitRunAccepted(page);
      await expect(page.locator('#ft-status')).toContainText(/UI=ON/i);

      const runs = await capturedRuns(page);
      expect(runs.length).toBeGreaterThanOrEqual(1);
      const last = runs[runs.length - 1];
      expect(last.op).toBe('runE2e');
      expect(last.params.module).toBe(TARGET_MODULE);
      expect(last.params.files).toEqual([]);
      expect(last.params.ui_enabled).toBe(true);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'FW-TEST-MGMT-008' },
    'UI 测试开关写入系统配置后刷新仍保持',
    async () => {
      await gotoModules(page);
      await installRunCapture(page);
      await setUiEnabledPersist(page, true);

      await gotoModules(page);
      await expect(page.locator('#ft-ui-enabled')).toBeChecked();

      await installRunCapture(page);
      await setUiEnabledPersist(page, false);
      await gotoModules(page);
      await expect(page.locator('#ft-ui-enabled')).not.toBeChecked();
    }
  );
});
