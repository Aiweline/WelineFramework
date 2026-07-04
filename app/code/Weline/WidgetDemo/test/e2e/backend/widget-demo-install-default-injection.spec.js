// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  buildModuleBackendRoute,
  gotoBackend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_WidgetDemo';
const DASHBOARD_MODULE = 'Weline_Dashboard';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'widget-demo-default-injection-fixture.php');
const PAGE_TYPE = 'dashboard';
const EDITOR_AREA = 'backend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|部件渲染失败|500 Internal Server Error/i;
const DEMO_WIDGET = {
  module: MODULE,
  type: 'stats',
  code: 'install_default_card',
  slot: 'dashboard-side',
  area: 'content',
  sortOrder: 15,
  label: 'Widget Demo Install Card',
};

function runFixture(action, payload) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...(payload || {}) }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  return JSON.parse(stdout);
}

function runBinW(args, options = {}) {
  try {
    return execFileSync('php', ['bin/w', ...args], {
      cwd: ROOT_DIR,
      env: process.env,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: options.timeout || 180000,
    });
  } catch (error) {
    const stdout = error.stdout ? String(error.stdout) : '';
    const stderr = error.stderr ? String(error.stderr) : '';
    if (options.allowFailure === true) {
      return [
        stdout.trim(),
        stderr.trim(),
      ].filter(Boolean).join('\n');
    }
    throw new Error([
      `php bin/w ${args.join(' ')} failed`,
      stdout.trim(),
      stderr.trim(),
    ].filter(Boolean).join('\n'));
  }
}

function disableMaintenanceBestEffort() {
  try {
    runBinW(['maintenance:disable'], { timeout: 60000 });
  } catch (error) {
    // setup:upgrade is the assertion-worthy command; maintenance cleanup is best effort.
  }
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

function demoRows(rows) {
  return (rows || []).filter((row) => row
    && row.widget_module === DEMO_WIDGET.module
    && row.widget_type === DEMO_WIDGET.type
    && row.widget_code === DEMO_WIDGET.code
    && Number(row.is_active) === 1);
}

function dashboardRoute(websiteId, viewId) {
  const route = buildModuleBackendRoute(DASHBOARD_MODULE, 'dashboard', 'index');
  const params = new URLSearchParams({
    website_id: String(websiteId),
    view_id: String(viewId),
  });
  return `${route}?${params.toString()}`;
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
  return query ? `${route}${String(route).includes('?') ? '&' : '?'}${query}` : route;
}

function findDefaultInjection(items) {
  return (items || []).find((item) => item
    && item.module === DEMO_WIDGET.module
    && item.type === DEMO_WIDGET.type
    && item.code === DEMO_WIDGET.code);
}

function snapshotRows(token) {
  const snapshot = runFixture('snapshot', { token });
  expect(snapshot.success, JSON.stringify(snapshot)).toBeTruthy();
  return {
    ...snapshot,
    rows: demoRows(snapshot.layout),
  };
}

async function waitForWidgetRows(token, count) {
  await expect.poll(() => snapshotRows(token).rows.length, {
    timeout: 30000,
    intervals: [250, 500, 1000],
  }).toBe(count);
  return snapshotRows(token).rows;
}

async function waitForWidgetRowsByStatus(token, status, count) {
  await expect.poll(() => snapshotRows(token).rows.filter((row) => row.status === status).length, {
    timeout: 30000,
    intervals: [250, 500, 1000],
  }).toBe(count);
  return snapshotRows(token).rows.filter((row) => row.status === status);
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
  }, null, { timeout: 60000 });
  await expect(page.locator('#widgetLibraryTabs')).toBeVisible({ timeout: 30000 });
}

async function openApplicationsTab(page) {
  const tab = page.locator('[data-widget-library-tab="applications"]').first();
  await expect(tab).toBeVisible({ timeout: 30000 });
  await tab.click();
  await expect(tab).toHaveClass(/active/, { timeout: 30000 });
}

function defaultInjectionItem(page) {
  return page.locator('.widget-default-injection-item')
    .filter({ hasText: `${DEMO_WIDGET.module} / ${DEMO_WIDGET.type} / ${DEMO_WIDGET.code}` })
    .first();
}

async function applyFromApplicationsTab(page) {
  const item = defaultInjectionItem(page);
  await expect(item).toBeVisible({ timeout: 30000 });
  await expect(item).toContainText(DEMO_WIDGET.label);
  await expect(item).toContainText('Demo widget should be installed into Dashboard side slot on first DB registration');
  await expect(item).toContainText(DEMO_WIDGET.slot);
  await item.locator('.btn-apply-default-injection').click();
}

async function applyDefaultInjectionFromBrowser(page, editorPayload, injectionKey) {
  const result = await page.locator('#themeEditor').evaluate(async (el, input) => {
    const url = el.dataset.apiApplyDefaultInjection || '/theme/backend/theme-editor/apply-default-injection';
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        accept: 'application/json',
        'content-type': 'application/json',
        'x-requested-with': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        ...input.editorPayload,
        injection_key: input.injectionKey,
      }),
    });
    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch (error) {
      return {
        success: false,
        status: response.status,
        message: text.slice(0, 500),
      };
    }
  }, { editorPayload, injectionKey });

  expect(result.success, JSON.stringify(result)).toBeTruthy();
  return result;
}

moduleDescribe(test, MODULE, 'widget install default injection', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'WIDGET-DEMO-DEFAULT-INJECTION-001' },
    'demo module first DB registration injects, deletion moves to applications tab, and apply restores the slot',
    async ({ page }, testInfo) => {
      const token = `install_default_${Date.now().toString(36)}_${testInfo.workerIndex || 0}`;
      let prepared = null;

      try {
        runFixture('cleanup-demo-widget-everywhere', { token });
        prepared = runFixture('prepare-empty-default-view', { token });
        expect(prepared.success, JSON.stringify(prepared)).toBeTruthy();
        expect(prepared.theme_id).toBeGreaterThan(0);
        expect(prepared.website_id).toBeGreaterThan(0);
        expect(prepared.view_id).toBeGreaterThan(0);
        expect(prepared.identity).toMatchObject({
          layout_option: 'default',
          scope: `dashboard_view:${prepared.view_id}`,
          target_type: 'website',
          target_id: prepared.website_id,
        });
        expect(demoRows(prepared.layout)).toHaveLength(0);

        const reset = runFixture('reset-demo-widget-registry', { token });
        expect(reset.success, JSON.stringify(reset)).toBeTruthy();
        expect(Object.keys(reset.entry || {})).toHaveLength(0);

        const setupOutput = runBinW([
          'setup:upgrade',
          '--module=Weline_WidgetDemo',
          '--skip-composer-dump',
        ], { allowFailure: true, timeout: 240000 });
        expect(setupOutput).toContain('Weline_WidgetDemo');
        disableMaintenanceBestEffort();

        const registry = runFixture('registry-entry', { token });
        expect(registry.success, JSON.stringify(registry)).toBeTruthy();
        expect(registry.entry?.widget_module).toBe(DEMO_WIDGET.module);
        expect(registry.entry?.widget_type).toBe(DEMO_WIDGET.type);
        expect(registry.entry?.widget_code).toBe(DEMO_WIDGET.code);
        expect(Number(registry.entry?.has_default_injections || 0)).toBe(1);

        let rows = await waitForWidgetRows(token, 2);
        const autoPublished = rows.find((row) => row.status === 'published');
        const autoDraft = rows.find((row) => row.status === 'draft');
        expect(autoPublished, JSON.stringify(rows)).toBeTruthy();
        expect(autoDraft, JSON.stringify(rows)).toBeTruthy();
        expect(autoPublished.slot_id).toBe(DEMO_WIDGET.slot);
        expect(autoPublished.area).toBe(DEMO_WIDGET.area);
        expect(Number(autoPublished.sort_order)).toBe(DEMO_WIDGET.sortOrder);
        const autoConfig = parseConfig(autoPublished);
        expect(autoConfig.demo_label).toBe('first-db-registration');
        expect(autoConfig.dashboard_layout?.sortOrder).toBe(DEMO_WIDGET.sortOrder);

        await loginAsAdmin(page, { timeout: 90000, settleMs: 1000 });
        await gotoBackend(page, dashboardRoute(prepared.website_id, prepared.view_id), {
          waitUntil: 'domcontentloaded',
          timeout: 90000,
          settleMs: 1500,
        });

        const body = page.locator('body');
        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        await expect(page.locator('[data-dashboard-root]')).toBeVisible({ timeout: 30000 });
        const renderedWidget = page.locator(
          `.widget-wrapper[data-widget-module="${DEMO_WIDGET.module}"][data-widget-type="${DEMO_WIDGET.type}"][data-widget-code="${DEMO_WIDGET.code}"][data-slot-id="${DEMO_WIDGET.slot}"]`,
        ).first();
        await expect(renderedWidget).toBeVisible({ timeout: 30000 });
        await expect(renderedWidget).toContainText(DEMO_WIDGET.label);
        await expect(renderedWidget).toContainText('first-db-registration');

        const publishedDeleted = runFixture('delete-demo-widget-status', {
          token,
          status: 'published',
        });
        expect(publishedDeleted.success, JSON.stringify(publishedDeleted)).toBeTruthy();
        await waitForWidgetRowsByStatus(token, 'published', 0);

        const editorPayload = identityPayload(prepared.theme_id, prepared.identity);
        await gotoBackend(page, buildQueryPath('theme/backend/theme-editor', editorPayload), {
          waitUntil: 'domcontentloaded',
          timeout: 90000,
          settleMs: 1500,
        });
        await waitForThemeEditor(page);

        const missingBeforeDelete = runFixture('default-injections', { token });
        expect(missingBeforeDelete.success, JSON.stringify(missingBeforeDelete)).toBeTruthy();
        expect(findDefaultInjection(missingBeforeDelete.items)).toBeFalsy();

        await page.locator('.preview-tab[data-view="structure"]').click();
        const draftLayoutId = Number(autoDraft.layout_id || 0);
        expect(draftLayoutId).toBeGreaterThan(0);
        const structureWidget = page.locator(`.preview-widget-item[data-layout-id="${draftLayoutId}"]`).first();
        await expect(structureWidget).toBeVisible({ timeout: 30000 });
        await structureWidget.hover();
        await structureWidget.locator('.btn-delete-widget').click({ force: true });
        await page.locator('.custom-confirm-dialog .btn-confirm').click();
        await waitForWidgetRowsByStatus(token, 'draft', 0);

        const missingAfterDelete = runFixture('default-injections', { token });
        expect(missingAfterDelete.success, JSON.stringify(missingAfterDelete)).toBeTruthy();
        const suggested = findDefaultInjection(missingAfterDelete.items);
        expect(suggested, JSON.stringify(missingAfterDelete.items || [])).toBeTruthy();
        expect(suggested.slot_id).toBe(DEMO_WIDGET.slot);
        expect(suggested.area).toBe(DEMO_WIDGET.area);
        expect(suggested.sort_order).toBe(DEMO_WIDGET.sortOrder);

        await openApplicationsTab(page);
        await applyDefaultInjectionFromBrowser(page, editorPayload, suggested.injection_key);
        rows = await waitForWidgetRowsByStatus(token, 'draft', 1);
        const restored = rows[0];
        expect(restored.status).toBe('draft');
        expect(restored.slot_id).toBe(DEMO_WIDGET.slot);
        expect(restored.area).toBe(DEMO_WIDGET.area);
        expect(Number(restored.sort_order)).toBe(DEMO_WIDGET.sortOrder);
        expect(Number(restored.layout_id || 0)).not.toBe(draftLayoutId);

        await gotoBackend(page, buildQueryPath('theme/backend/theme-editor', editorPayload), {
          waitUntil: 'domcontentloaded',
          timeout: 90000,
          settleMs: 1500,
        });
        await waitForThemeEditor(page);
        await page.locator('.preview-tab[data-view="structure"]').click();
        await expect(page.locator(`.preview-widget-item[data-layout-id="${restored.layout_id}"]`).first())
          .toBeVisible({ timeout: 30000 });
      } finally {
        disableMaintenanceBestEffort();
        runFixture('cleanup', { token });
        runFixture('cleanup-demo-widget-everywhere', { token });
      }
    },
  );
});
