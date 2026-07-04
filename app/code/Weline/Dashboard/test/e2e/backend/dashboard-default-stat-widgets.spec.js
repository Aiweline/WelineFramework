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

const MODULE = 'Weline_Dashboard';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'dashboard-default-layout-fixture.php');
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|部件渲染失败|500 Internal Server Error/i;

const EXPECTED_WIDGETS = [
  ['Weline_Dashboard', 'stats', 'overview_kpi', 'dashboard-summary', 10],
  ['Weline_Dashboard', 'chart', 'activity_trend', 'dashboard-analysis', 20],
  ['Weline_Dashboard', 'table', 'system_status', 'dashboard-side', 30],
  ['Weline_Dashboard', 'table', 'detail_snapshot', 'dashboard-detail', 40],
  ['Weline_Visitor', 'stats', 'pixel_overview', 'dashboard-summary', 50],
  ['Weline_Visitor', 'chart', 'pixel_event_trend', 'dashboard-analysis', 60],
  ['Weline_Visitor', 'table', 'pixel_top_events', 'dashboard-detail', 70],
  ['Weline_Visitor', 'list', 'pixel_realtime', 'dashboard-side', 80],
];

const VISITOR_EVENT_PAGE_WIDGETS = [
  ['Weline_Visitor', 'stats', 'pixel_overview', 'dashboard-summary'],
  ['Weline_Visitor', 'chart', 'pixel_event_trend', 'dashboard-analysis'],
  ['Weline_Visitor', 'list', 'pixel_realtime', 'dashboard-side'],
  ['Weline_Visitor', 'table', 'pixel_top_events', 'dashboard-detail'],
];

function runFixture(action, payload) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...(payload || {}) }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  return JSON.parse(stdout);
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

function findInjection(items, moduleName, type, code) {
  return items.find((item) => item
    && item.module === moduleName
    && item.type === type
    && item.code === code);
}

function findWidget(rows, moduleName, type, code) {
  return rows.find((row) => row
    && row.widget_module === moduleName
    && row.widget_type === type
    && row.widget_code === code);
}

function activePublishedRows(rows) {
  return (rows || []).filter((row) => row
    && row.status === 'published'
    && Number(row.is_active) === 1);
}

function dashboardRoute(websiteId, viewId) {
  const route = buildModuleBackendRoute(MODULE, 'dashboard', 'index');
  const params = new URLSearchParams({
    website_id: String(websiteId),
    view_id: String(viewId),
  });
  return `${route}?${params.toString()}`;
}

moduleDescribe(test, MODULE, 'dashboard default statistic widgets', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'DASHBOARD-DEFAULT-WIDGETS-001' },
    'first DB collection auto-injects defaults once and leaves deleted defaults as suggestions',
    async ({}, testInfo) => {
      const token = `stat_widgets_${Date.now().toString(36)}_${testInfo.workerIndex || 0}`;
      const modules = ['Weline_Dashboard', 'Weline_Visitor'];
      const fixturePayload = { token };

      try {
        const prepared = runFixture('prepare-empty-default-view', fixturePayload);
        expect(prepared.success, JSON.stringify(prepared)).toBeTruthy();
        expect(prepared.theme_id).toBeGreaterThan(0);
        expect(prepared.website_id).toBeGreaterThanOrEqual(0);
        expect(prepared.view_id).toBeGreaterThan(0);
        expect(prepared.identity).toMatchObject({
          layout_option: 'default',
          scope: `dashboard_view:${prepared.view_id}`,
          target_type: 'website',
          target_id: prepared.website_id,
        });
        expect(prepared.layout || []).toHaveLength(0);

        const secondary = runFixture('prepare-extra-empty-view', {
          ...fixturePayload,
          view_code: 'secondary',
        });
        expect(secondary.success, JSON.stringify(secondary)).toBeTruthy();
        expect(secondary.website_id).toBe(prepared.website_id);
        expect(secondary.view_id).toBeGreaterThan(0);
        expect(secondary.view_id).not.toBe(prepared.view_id);
        expect(secondary.layout || []).toHaveLength(0);

        const refreshed = runFixture('refresh-widget-registry', {
          ...fixturePayload,
          reset_registry: true,
          modules,
        });
        expect(refreshed.success, JSON.stringify(refreshed)).toBeTruthy();
        expect(refreshed.report?.created_default_injection_count).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);
        expect(refreshed.report?.widget_install_event_dispatched).toBeTruthy();
        expect(refreshed.identity).toMatchObject(prepared.identity);
        const rows = (refreshed.layout || []).filter((row) => row && row.status === 'published');
        expect(rows.length).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);
        const secondarySnapshot = runFixture('snapshot', {
          ...fixturePayload,
          view_id: secondary.view_id,
        });
        expect(secondarySnapshot.success, JSON.stringify(secondarySnapshot)).toBeTruthy();
        const secondaryRows = (secondarySnapshot.layout || []).filter((row) => row && row.status === 'published');
        expect(secondaryRows.length).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);

        for (const [moduleName, type, code, slot, sortOrder] of EXPECTED_WIDGETS) {
          const row = findWidget(rows, moduleName, type, code);
          expect(row, `${moduleName}/${type}/${code} missing from layout`).toBeTruthy();
          expect(row.area).toBe('content');
          expect(row.slot_id).toBe(slot);
          expect(Number(row.is_active)).toBe(1);

          const config = parseConfig(row);
          expect(config.dashboard_layout?.sortOrder).toBe(sortOrder);
          expect(config.dashboard_layout?.colSpan).toBeGreaterThan(0);
          expect(config.dashboard_layout?.rowSpan).toBeGreaterThan(0);

          const secondaryRow = findWidget(secondaryRows, moduleName, type, code);
          expect(secondaryRow, `${moduleName}/${type}/${code} missing from secondary identity`).toBeTruthy();
          expect(secondaryRow.area).toBe('content');
          expect(secondaryRow.slot_id).toBe(slot);
          expect(Number(secondaryRow.is_active)).toBe(1);
        }

        const cleared = runFixture('clear-layout', fixturePayload);
        expect(cleared.success, JSON.stringify(cleared)).toBeTruthy();
        const clearedActiveRows = (cleared.layout || []).filter((row) => row && Number(row.is_active) === 1);
        expect(clearedActiveRows).toHaveLength(0);

        const replayed = runFixture('refresh-widget-registry', {
          ...fixturePayload,
          modules,
        });
        expect(replayed.success, JSON.stringify(replayed)).toBeTruthy();
        expect(replayed.report?.widget_install_event_dispatched).toBeFalsy();
        const activeRows = (replayed.layout || []).filter((row) => row && Number(row.is_active) === 1);
        expect(activeRows).toHaveLength(0);

        const suggestions = runFixture('default-injections', fixturePayload);
        expect(suggestions.success, JSON.stringify(suggestions)).toBeTruthy();
        expect(suggestions.total).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);
        for (const [moduleName, type, code, slot, sortOrder] of EXPECTED_WIDGETS) {
          const item = findInjection(suggestions.items || [], moduleName, type, code);
          expect(item, `${moduleName}/${type}/${code} missing from default injection suggestions`).toBeTruthy();
          expect(item.slot_id).toBe(slot);
          expect(item.sort_order).toBe(sortOrder);
        }

        const restoreItem = findInjection(suggestions.items || [], 'Weline_Visitor', 'stats', 'pixel_overview');
        expect(restoreItem?.injection_key).toBeTruthy();
        const restored = runFixture('apply-default-injection', {
          ...fixturePayload,
          injection_key: restoreItem.injection_key,
          apply_scope: 'current',
        });
        expect(restored.success, JSON.stringify(restored)).toBeTruthy();
        expect(restored.apply_scope).toBe('current');
        expect(restored.result?.applied_count).toBe(1);
        expect(restored.result?.total_identities).toBe(1);
        const restoredRow = findWidget(restored.layout || [], 'Weline_Visitor', 'stats', 'pixel_overview');
        expect(restoredRow, 'pixel_overview should be restored into current dashboard identity').toBeTruthy();
        expect(restoredRow.status).toBe('draft');
        expect(restoredRow.slot_id).toBe('dashboard-summary');
        expect(Number(restoredRow.is_active)).toBe(1);
      } finally {
        runFixture('cleanup', { ...fixturePayload, modules });
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'DASHBOARD-DEFAULT-WIDGETS-002' },
    'dashboard page renders default injected widgets in declared slots',
    async ({ page }, testInfo) => {
      const token = `stat_widgets_ui_${Date.now().toString(36)}_${testInfo.workerIndex || 0}`;
      const modules = ['Weline_Dashboard', 'Weline_Visitor'];
      const fixturePayload = { token, system_default: true };

      try {
        const prepared = runFixture('prepare-system-default-view', fixturePayload);
        expect(prepared.success, JSON.stringify(prepared)).toBeTruthy();

        const refreshed = runFixture('refresh-widget-registry', {
          ...fixturePayload,
          reset_registry: true,
          modules,
        });
        expect(refreshed.success, JSON.stringify(refreshed)).toBeTruthy();

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
        await expect(page.locator('[data-dashboard-layout-slots]')).toBeVisible({ timeout: 30000 });

        for (const slotId of ['dashboard-summary', 'dashboard-analysis', 'dashboard-side', 'dashboard-detail']) {
          await expect(page.locator(`[data-wslot="${slotId}"], [data-slot-id="${slotId}"]`).first()).toBeVisible({ timeout: 30000 });
        }

        for (const [moduleName, type, code, slot] of EXPECTED_WIDGETS) {
          const widget = page.locator(
            `.widget-wrapper[data-widget-module="${moduleName}"][data-widget-type="${type}"][data-widget-code="${code}"][data-slot-id="${slot}"]`,
          ).first();
          await expect(widget, `${moduleName}/${type}/${code} should render in ${slot}`).toBeVisible({ timeout: 30000 });
        }

        await expect(page.locator('.widget-wrapper[data-widget-code="overview_kpi"]')).toContainText('后台统计');
        await expect(page.locator('.widget-wrapper[data-widget-code="pixel_overview"]')).toContainText('像素概览');
      } finally {
        runFixture('cleanup', { ...fixturePayload, modules });
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'DASHBOARD-DEFAULT-WIDGETS-003' },
    'dashboard editor modal saves and closes the system default view',
    async ({ page }, testInfo) => {
      const token = `stat_widgets_editor_${Date.now().toString(36)}_${testInfo.workerIndex || 0}`;
      const modules = ['Weline_Dashboard', 'Weline_Visitor'];
      const fixturePayload = { token, system_default: true };

      try {
        const prepared = runFixture('prepare-system-default-view', fixturePayload);
        expect(prepared.success, JSON.stringify(prepared)).toBeTruthy();
        expect(prepared.view_id).toBeGreaterThan(0);

        const refreshed = runFixture('refresh-widget-registry', {
          ...fixturePayload,
          reset_registry: true,
          modules,
        });
        expect(refreshed.success, JSON.stringify(refreshed)).toBeTruthy();
        expect(activePublishedRows(refreshed.layout).length).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);

        await loginAsAdmin(page, { timeout: 90000, settleMs: 1000 });
        await gotoBackend(page, dashboardRoute(prepared.website_id, prepared.view_id), {
          waitUntil: 'domcontentloaded',
          timeout: 90000,
          settleMs: 1500,
        });

        await expect(page.locator('[data-dashboard-root]')).toBeVisible({ timeout: 30000 });
        await page.keyboard.press('Escape');
        const editorOpen = page.locator('[data-dashboard-editor-open]');
        await expect(editorOpen).toBeVisible({ timeout: 30000 });
        await editorOpen.scrollIntoViewIfNeeded();
        await editorOpen.click();

        const modal = page.locator('[data-dashboard-editor-modal]');
        await expect(modal).toBeVisible({ timeout: 30000 });
        const frame = page.locator('[data-dashboard-editor-frame]');
        const frameSrc = await frame.getAttribute('src');
        const frameUrl = new URL(frameSrc || '', page.url());
        expect(frameUrl.searchParams.get('lock_layout_context')).toBe('1');
        expect(frameUrl.searchParams.get('scope')).toBe(`dashboard_view:${prepared.view_id}`);
        expect(frameUrl.searchParams.get('layout_lock_target_type')).toBe('website');
        expect(Number(frameUrl.searchParams.get('layout_lock_target_id'))).toBe(prepared.website_id);
        await expect(page.frameLocator('[data-dashboard-editor-frame]').locator('#themeEditor')).toBeVisible({ timeout: 90000 });

        const saveClose = page.locator('[data-dashboard-editor-save-close]');
        await expect(saveClose).toBeEnabled({ timeout: 30000 });
        await saveClose.click();

        await expect(modal).toBeHidden({ timeout: 90000 });
        await expect(page.locator('[data-dashboard-root]')).toBeVisible({ timeout: 90000 });
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

        const afterSave = runFixture('snapshot', fixturePayload);
        expect(afterSave.success, JSON.stringify(afterSave)).toBeTruthy();
        expect(activePublishedRows(afterSave.layout).length).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);
      } finally {
        runFixture('cleanup', { ...fixturePayload, modules });
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'DASHBOARD-DEFAULT-WIDGETS-004' },
    'dashboard page creates a new view through frontend worker API and copies default widgets',
    async ({ page }, testInfo) => {
      const token = `stat_widgets_create_${Date.now().toString(36)}_${testInfo.workerIndex || 0}`;
      const modules = ['Weline_Dashboard', 'Weline_Visitor'];
      const fixturePayload = { token };
      const viewName = `像素视图 ${Date.now().toString(36)}`;

      try {
        const prepared = runFixture('prepare-empty-default-view', fixturePayload);
        expect(prepared.success, JSON.stringify(prepared)).toBeTruthy();

        const refreshed = runFixture('refresh-widget-registry', {
          ...fixturePayload,
          reset_registry: true,
          modules,
        });
        expect(refreshed.success, JSON.stringify(refreshed)).toBeTruthy();
        expect(activePublishedRows(refreshed.layout).length).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);

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

        await page.locator('[data-dashboard-panel="create"]').click();
        const createForm = page.locator('[data-dashboard-create-form]');
        await expect(createForm).toBeVisible({ timeout: 30000 });
        await createForm.locator('input[name="name"]').fill(viewName);
        await createForm.locator('button[type="submit"]').click();

        await page.waitForURL((url) => {
          const nextViewId = Number(url.searchParams.get('view_id') || 0);
          return nextViewId > 0 && nextViewId !== prepared.view_id;
        }, { timeout: 90000 });

        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        await expect(body).not.toContainText('Operation is not exposed to frontend worker API.');
        await expect(page.locator('[data-dashboard-root]')).toBeVisible({ timeout: 30000 });
        await expect(page.locator('.w-dashboard-view-link.active')).toContainText(viewName);

        const createdUrl = new URL(page.url());
        const createdViewId = Number(createdUrl.searchParams.get('view_id') || 0);
        expect(createdViewId).toBeGreaterThan(0);
        expect(createdViewId).not.toBe(prepared.view_id);

        const createdSnapshot = runFixture('snapshot', {
          ...fixturePayload,
          view_id: createdViewId,
        });
        expect(createdSnapshot.success, JSON.stringify(createdSnapshot)).toBeTruthy();
        expect(activePublishedRows(createdSnapshot.layout).length).toBeGreaterThanOrEqual(EXPECTED_WIDGETS.length);

        for (const [moduleName, type, code, slot] of EXPECTED_WIDGETS) {
          const row = findWidget(activePublishedRows(createdSnapshot.layout), moduleName, type, code);
          expect(row, `${moduleName}/${type}/${code} missing from created view layout`).toBeTruthy();
          expect(row.slot_id).toBe(slot);
          expect(Number(row.is_active)).toBe(1);
        }
      } finally {
        runFixture('cleanup', { ...fixturePayload, modules });
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'DASHBOARD-DEFAULT-WIDGETS-005' },
    'visitor module creates its own dashboard page identity with event statistic widgets',
    async ({ page }, testInfo) => {
      const token = `stat_widgets_visitor_page_${Date.now().toString(36)}_${testInfo.workerIndex || 0}`;
      const modules = ['Weline_Dashboard', 'Weline_Visitor'];
      const fixturePayload = { token };

      try {
        const prepared = runFixture('prepare-empty-default-view', fixturePayload);
        expect(prepared.success, JSON.stringify(prepared)).toBeTruthy();

        const ensured = runFixture('ensure-visitor-dashboard-pages', fixturePayload);
        expect(ensured.success, JSON.stringify(ensured)).toBeTruthy();
        expect(ensured.view_id).toBeGreaterThan(0);
        expect(ensured.identity).toMatchObject({
          layout_option: 'default',
          scope: `dashboard_view:${ensured.view_id}`,
          target_type: 'website',
          target_id: prepared.website_id,
        });

        const publishedRows = activePublishedRows(ensured.layout);
        expect(publishedRows).toHaveLength(VISITOR_EVENT_PAGE_WIDGETS.length);
        for (const [moduleName, type, code, slot] of VISITOR_EVENT_PAGE_WIDGETS) {
          const row = findWidget(publishedRows, moduleName, type, code);
          expect(row, `${moduleName}/${type}/${code} missing from visitor event page`).toBeTruthy();
          expect(row.slot_id).toBe(slot);
          expect(Number(row.is_active)).toBe(1);
        }

        await loginAsAdmin(page, { timeout: 90000, settleMs: 1000 });
        await gotoBackend(page, dashboardRoute(prepared.website_id, ensured.view_id), {
          waitUntil: 'domcontentloaded',
          timeout: 90000,
          settleMs: 1500,
        });

        const body = page.locator('body');
        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        await expect(page.locator('[data-dashboard-root]')).toBeVisible({ timeout: 30000 });
        await expect(page.locator('.w-dashboard-title h1')).toContainText('事件统计');
        await expect(page.locator('.w-dashboard-view-link.active')).toContainText('事件统计');

        const widgets = page.locator('.widget-wrapper[data-widget-module="Weline_Visitor"]');
        await expect(widgets).toHaveCount(VISITOR_EVENT_PAGE_WIDGETS.length, { timeout: 30000 });
        for (const [moduleName, type, code, slot] of VISITOR_EVENT_PAGE_WIDGETS) {
          const widget = page.locator(
            `.widget-wrapper[data-widget-module="${moduleName}"][data-widget-type="${type}"][data-widget-code="${code}"][data-slot-id="${slot}"]`,
          );
          await expect(widget, `${moduleName}/${type}/${code} should render in ${slot}`).toBeVisible({ timeout: 30000 });
        }
      } finally {
        runFixture('cleanup', { ...fixturePayload, modules });
      }
    },
  );
});
