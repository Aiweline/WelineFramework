// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'theme-editor-fixture.php');
const PAGE_TYPE = 'dashboard';
const EDITOR_AREA = 'backend';
let selectedDefaultWidget = null;

function resolveThemeId() {
  const forcedThemeId = Number(process.env.PLAYWRIGHT_THEME_ID || 0);
  if (forcedThemeId > 0) {
    return forcedThemeId;
  }
  const activeTheme = getActiveTheme('backend');
  return Number((activeTheme && activeTheme.id) || 0);
}

function makeScope(testInfo) {
  const worker = Number(testInfo.workerIndex || 0).toString(36);
  return `e2e_default_injection_${Date.now().toString(36)}_${worker}`;
}

function runFixture(action, payload) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...(payload || {}) }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  return JSON.parse(stdout);
}

function expectEditorSuccess(result, label) {
  expect(result, `${label} returned a response`).toBeTruthy();
  expect(result.success, `${label} response: ${JSON.stringify(result)}`).toBeTruthy();
}

function parseConfig(row) {
  const raw = row && row.config;
  if (!raw) {
    return {};
  }
  if (typeof raw === 'object') {
    return raw;
  }
  return JSON.parse(String(raw));
}

function identityPayload(themeId, identity) {
  return {
    theme_id: themeId,
    page_type: PAGE_TYPE,
    layout_type: PAGE_TYPE,
    layout_option: identity.layout_option,
    editor_area: EDITOR_AREA,
    scope: identity.scope,
    target_type: identity.target_type,
    target_id: identity.target_id,
    theme_layout_target_type: identity.target_type,
    theme_layout_target_id: identity.target_id,
    theme_layout_source_target_type: identity.target_type,
    theme_layout_source_target_id: identity.target_id,
  };
}

function buildQueryPath(route, payload) {
  const params = new URLSearchParams();
  Object.entries(payload).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value));
    }
  });
  const query = params.toString();
  if (!query) {
    return route;
  }
  return `${route}${String(route).includes('?') ? '&' : '?'}${query}`;
}

async function waitForThemeEditor(page) {
  await page.locator('#themeEditor').waitFor({ state: 'attached', timeout: 60000 });
  await page.waitForFunction(() => {
    const candidates = [
      window.Weline && window.Weline.Api,
      window.WelineApiModule,
    ];
    return candidates.some((api) => {
      if (!api) {
        return false;
      }
      if (api.__backend === true && typeof api.request === 'function') {
        return true;
      }
      return api.__backend !== true && typeof api.call === 'function';
    });
  }, null, {
    timeout: 60000,
  });
  await expect(page.locator('#widgetLibraryTabs')).toBeVisible({ timeout: 30000 });
}

async function callEditorRequest(page, url, method = 'GET', body = null) {
  return page.evaluate(async (input) => {
    if (window.ThemeEditor && typeof window.ThemeEditor.apiJson === 'function') {
      const options = {
        method: input.method,
        headers: {
          accept: 'application/json',
          'content-type': 'application/json',
          'x-requested-with': 'XMLHttpRequest',
        },
      };
      if (input.body !== null && input.body !== undefined) {
        options.body = JSON.stringify(input.body);
      }
      return window.ThemeEditor.apiJson(input.url, options);
    }

    const backendApi = [
      window.Weline && window.Weline.Api,
      window.WelineApiModule,
    ].find((candidate) => candidate && candidate.__backend === true && typeof candidate.request === 'function');
    if (backendApi) {
      const options = {
        method: input.method,
        headers: {
          accept: 'application/json',
          'content-type': 'application/json',
          'x-requested-with': 'XMLHttpRequest',
        },
      };
      if (input.body !== null && input.body !== undefined) {
        options.body = JSON.stringify(input.body);
      }
      return backendApi.request(input.url, options);
    }

    const providerApi = [
      window.WelineApiModule,
      window.Weline && window.Weline.Api,
    ].find((candidate) => candidate && candidate.__backend !== true && typeof candidate.call === 'function');
    if (!providerApi) {
      throw new Error('Weline API is not available for ThemeEditor E2E.');
    }
    const params = {
      url: input.url,
      method: input.method,
      headers: {
        accept: 'application/json',
        'content-type': 'application/json',
      },
    };
    if (input.body !== null && input.body !== undefined) {
      params.body = JSON.stringify(input.body);
    }
    return providerApi.call('theme', 'editorRequest', params, { silent: true });
  }, { url, method, body });
}

async function getThemeEditorApi(page, datasetKey, fallbackUrl) {
  return page.locator('#themeEditor').evaluate((el, input) => el.dataset[input.datasetKey] || input.fallbackUrl, {
    datasetKey,
    fallbackUrl,
  });
}

function selectedWidget() {
  expect(selectedDefaultWidget, 'No declared default injection was selected.').toBeTruthy();
  return selectedDefaultWidget;
}

function findDefaultInjection(items, expected = selectedWidget()) {
  return (items || []).find((item) => item
    && item.module === expected.module
    && item.type === expected.type
    && item.code === expected.code);
}

function snapshotRows(themeId, identity) {
  const snapshot = runFixture('snapshot', {
    theme_id: themeId,
    page_type: PAGE_TYPE,
    identity,
  });
  expect(snapshot.success).toBeTruthy();
  return (snapshot.layout || []).filter((row) => row
    && row.widget_module === selectedWidget().module
    && row.widget_type === selectedWidget().type
    && row.widget_code === selectedWidget().code);
}

async function waitForWidgetRows(themeId, identity, count) {
  await expect.poll(() => snapshotRows(themeId, identity).length, {
    timeout: 30000,
    intervals: [250, 500, 1000],
  }).toBe(count);
  return snapshotRows(themeId, identity);
}

async function openApplicationsTab(page) {
  const tab = page.locator('[data-widget-library-tab="applications"]').first();
  await expect(tab).toBeVisible({ timeout: 30000 });
  await tab.click();
}

function defaultInjectionItem(page) {
  return page.locator('.widget-default-injection-item')
    .filter({ hasText: `${selectedWidget().module} / ${selectedWidget().type} / ${selectedWidget().code}` })
    .first();
}

async function applyFromApplicationsTab(page, scope = 'current') {
  const item = defaultInjectionItem(page);
  await expect(item).toBeVisible({ timeout: 30000 });
  await expect(item).toContainText(selectedWidget().slot_id);
  await expect(item).toContainText('当前布局身份');
  await expect(item.locator('.btn-apply-default-injection[data-apply-scope="current"]')).toBeVisible();
  await expect(item.locator('.btn-apply-default-injection[data-apply-scope="all"]')).toBeVisible();
  const selector = scope === 'all'
    ? '.btn-apply-default-injection[data-apply-scope="all"]'
    : '.btn-apply-default-injection[data-apply-scope="current"]';
  const responsePromise = page.waitForResponse((response) => (
    response.request().method() === 'POST'
      && response.url().includes('/apply-default-injection')
  ));
  await item.locator(selector).click();
  const response = await responsePromise;
  expect(response.ok()).toBeTruthy();
  const result = await response.json();
  expectEditorSuccess(result, `apply default injection (${scope})`);
  expect(Number(result.applied_count || 0)).toBeGreaterThan(0);
  const currentUrl = new URL(page.url());
  const data = result.data || {};
  expect(data.scope).toBe(currentUrl.searchParams.get('scope'));
  expect(String(data.target_type)).toBe(currentUrl.searchParams.get('theme_layout_target_type'));
  expect(Number(data.target_id)).toBe(Number(currentUrl.searchParams.get('theme_layout_target_id')));
  return result;
}

async function waitForDefaultInjectionAbsent(page, defaultInjectionsApi, payload) {
  await expect.poll(async () => {
    const result = await callEditorRequest(page, buildQueryPath(defaultInjectionsApi, payload), 'GET');
    expectEditorSuccess(result, 'default injections after apply');
    return !findDefaultInjection(result.items || []);
  }, {
    timeout: 30000,
    intervals: [250, 500, 1000],
  }).toBeTruthy();
}

moduleDescribe(test, MODULE, 'theme editor default injections', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-DEFAULT-INJECTION-001' },
    'applications tab reapplies a declared default widget after deletion',
    async ({ page }, testInfo) => {
      const themeId = resolveThemeId();
      test.skip(themeId <= 0, 'No active backend theme found in runtime info.');

      const token = makeScope(testInfo);
      let identity = null;

      try {
        const prepared = runFixture('prepare_dashboard_identity', {
          theme_id: themeId,
          page_type: PAGE_TYPE,
          token,
        });
        expectEditorSuccess(prepared, 'prepare dashboard identity');

        identity = prepared.identity;
        expect(identity).toMatchObject({
          layout_option: 'default',
          scope: `dashboard_view:${prepared.view_id}`,
          target_type: 'website',
          target_id: prepared.website_id,
        });
        const basePayload = identityPayload(themeId, identity);

        runFixture('cleanup', { theme_id: themeId, page_type: PAGE_TYPE, identity });

        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(page, buildQueryPath('theme/backend/theme-editor', basePayload), {
          waitUntil: 'domcontentloaded',
          timeout: 60000,
          settleMs: 1500,
        });
        await waitForThemeEditor(page);
        const defaultInjectionsApi = await getThemeEditorApi(
          page,
          'apiDefaultInjections',
          '/theme/backend/theme-editor/default-injections',
        );

        await expect.poll(() => snapshotRows(themeId, identity).length, {
          timeout: 10000,
          intervals: [250, 500],
        }).toBe(0);

        const missingBefore = await callEditorRequest(
          page,
          buildQueryPath(defaultInjectionsApi, basePayload),
          'GET',
        );
        expectEditorSuccess(missingBefore, 'default injections before apply');
        const defaultItem = (missingBefore.items || []).find((item) => item
          && item.required
          && item.module
          && item.type
          && item.code
          && item.slot_id
          && item.area);
        expect(defaultItem, JSON.stringify(missingBefore.items || [])).toBeTruthy();
        selectedDefaultWidget = defaultItem;

        await openApplicationsTab(page);
        await applyFromApplicationsTab(page);

        let rows = await waitForWidgetRows(themeId, identity, 1);
        const firstLayoutId = Number(rows[0].layout_id || 0);
        expect(firstLayoutId).toBeGreaterThan(0);
        expect(rows[0].slot_id).toBe(selectedWidget().slot_id);
        expect(rows[0].area).toBe(selectedWidget().area);
        expect(rows[0].status).toBe('draft');

        const missingAfterApply = await callEditorRequest(
          page,
          buildQueryPath(defaultInjectionsApi, basePayload),
          'GET',
        );
        expectEditorSuccess(missingAfterApply, 'default injections after apply');
        expect(findDefaultInjection(missingAfterApply.items)).toBeFalsy();
        await expect(defaultInjectionItem(page)).toHaveCount(0, { timeout: 30000 });

        await page.locator('.preview-tab[data-view="structure"]').click();
        const structureWidget = page.locator(`.preview-widget-item[data-layout-id="${firstLayoutId}"]`).first();
        await expect(structureWidget).toBeVisible({ timeout: 30000 });
        await structureWidget.hover();
        await structureWidget.locator('.btn-delete-widget').click({ force: true });
        await page.locator('.custom-confirm-dialog .btn-confirm').click();
        await waitForWidgetRows(themeId, identity, 0);

        await openApplicationsTab(page);
        await expect(defaultInjectionItem(page)).toBeVisible({ timeout: 30000 });
        const missingAfterDelete = await callEditorRequest(
          page,
          buildQueryPath(defaultInjectionsApi, basePayload),
          'GET',
        );
        expectEditorSuccess(missingAfterDelete, 'default injections after delete');
        expect(findDefaultInjection(missingAfterDelete.items)).toBeTruthy();

        await applyFromApplicationsTab(page);
        rows = await waitForWidgetRows(themeId, identity, 1);
        const secondLayoutId = Number(rows[0].layout_id || 0);
        expect(secondLayoutId).toBeGreaterThan(0);
        expect(secondLayoutId).not.toBe(firstLayoutId);
        await expect(defaultInjectionItem(page)).toHaveCount(0, { timeout: 30000 });
      } finally {
        if (identity) {
          runFixture('cleanup', { theme_id: themeId, page_type: PAGE_TYPE, identity });
        }
        runFixture('cleanup_dashboard_identity', { theme_id: themeId, page_type: PAGE_TYPE, token });
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-DEFAULT-INJECTION-002' },
    'applications tab can restore a default widget to every identity of the same layout',
    async ({ page }, testInfo) => {
      const themeId = resolveThemeId();
      test.skip(themeId <= 0, 'No active backend theme found in runtime info.');

      const token = makeScope(testInfo);
      let identities = [];

      try {
        const prepared = runFixture('prepare_dashboard_identities', {
          theme_id: themeId,
          page_type: PAGE_TYPE,
          token,
          count: 2,
        });
        expectEditorSuccess(prepared, 'prepare dashboard identities');
        identities = prepared.identities || [];
        expect(identities.length).toBe(2);
        const [primaryIdentity, secondaryIdentity] = identities;
        const basePayload = identityPayload(themeId, primaryIdentity);

        for (const identity of identities) {
          runFixture('cleanup', { theme_id: themeId, page_type: PAGE_TYPE, identity });
          expect(snapshotRows(themeId, identity)).toHaveLength(0);
        }

        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(page, buildQueryPath('theme/backend/theme-editor', basePayload), {
          waitUntil: 'domcontentloaded',
          timeout: 60000,
          settleMs: 1500,
        });
        await waitForThemeEditor(page);

        // Each case must discover its own declared default.  Keeping this in
        // the previous case made the all-identities path silently depend on
        // Playwright's execution order.
        const defaultInjectionsApi = await getThemeEditorApi(
          page,
          'apiDefaultInjections',
          '/theme/backend/theme-editor/default-injections',
        );
        const missingBefore = await callEditorRequest(
          page,
          buildQueryPath(defaultInjectionsApi, basePayload),
          'GET',
        );
        expectEditorSuccess(missingBefore, 'default injections before apply to all identities');
        selectedDefaultWidget = (missingBefore.items || []).find((item) => item
          && item.required
          && item.module
          && item.type
          && item.code
          && item.slot_id
          && item.area);
        expect(selectedDefaultWidget, JSON.stringify(missingBefore.items || [])).toBeTruthy();

        await openApplicationsTab(page);
        const applyResult = await applyFromApplicationsTab(page, 'all');
        expect(Number(applyResult.applied_count || 0)).toBeGreaterThanOrEqual(2);
        expect(applyResult.data?.slot_id).toBe(selectedWidget().slot_id);
        expect(applyResult.data?.status).toBe('draft');
        await waitForDefaultInjectionAbsent(page, defaultInjectionsApi, identityPayload(themeId, primaryIdentity));
        await waitForDefaultInjectionAbsent(page, defaultInjectionsApi, identityPayload(themeId, secondaryIdentity));
        await expect(defaultInjectionItem(page)).toHaveCount(0, { timeout: 30000 });
      } finally {
        for (const identity of identities) {
          runFixture('cleanup', { theme_id: themeId, page_type: PAGE_TYPE, identity });
        }
        runFixture('cleanup_dashboard_identity', { theme_id: themeId, page_type: PAGE_TYPE, token });
      }
    },
  );
});
