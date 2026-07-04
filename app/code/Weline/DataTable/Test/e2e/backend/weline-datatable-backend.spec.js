// @weline-e2e-runtime wls
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildApiUrl } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

function assertNoPageErrors(errors) {
  expect(errors, errors.join('\n')).toEqual([]);
}

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN, {
    timeout: 15000,
  });
}

async function postJson(page, url, payload = {}) {
  return page.evaluate(async ({ url, payload }) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    const text = await response.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch (error) {
      json = { raw: text };
    }

    return {
      ok: response.ok,
      status: response.status,
      json,
    };
  }, { url, payload });
}

async function seedDemoData(page) {
  const payload = await postJson(page, buildApiUrl('datatable/rest/v1/demo-table/init-data'), {});
  expect(payload.ok).toBeTruthy();
  expect(payload.status).toBe(200);
  expect(payload.json.success).toBeTruthy();
}

async function waitForTable(page, tableId) {
  await page.waitForFunction((id) => {
    const instance = window.DataTableManager && window.DataTableManager.instances && window.DataTableManager.instances[id];
    return !!instance && Array.isArray(instance.data);
  }, tableId, { timeout: 30000 });
}

async function waitForForm(page, formId) {
  await page.waitForFunction((id) => {
    return !!(window.DataTableFormManager && window.DataTableFormManager.instances && window.DataTableFormManager.instances[id]);
  }, formId, { timeout: 30000 });
}

async function readTableState(page, tableId) {
  return page.evaluate((id) => {
    const instance = window.DataTableManager && window.DataTableManager.instances
      ? window.DataTableManager.instances[id]
      : null;

    if (!instance) {
      return null;
    }

    return {
      options: instance.options || {},
      data: Array.isArray(instance.data) ? instance.data : [],
      displayFields: Array.isArray(instance.displayFields) ? instance.displayFields : [],
      allFields: Array.isArray(instance.allFields) ? instance.allFields : [],
    };
  }, tableId);
}

test.describe('Weline_DataTable backend management and demos', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('dashboard, docs, tag verification and blank comprehensive index render with layout switching', async ({ page }) => {
    const errors = bindPageErrors(page);

    await gotoBackend(page, 'datatable/backend/test/index', {
      timeout: 60000,
      waitUntil: 'domcontentloaded',
      settleMs: 1200,
    });
    await expect(page.locator('[data-testid="datatable-admin-open-comprehensive"]')).toBeVisible();
    await expect(page.locator('[data-testid="datatable-layout-switcher"]')).toBeVisible();
    await expect(page.locator('body')).toContainText(/DataTable Backend Dashboard/i);
    await expectNoRuntimeError(page);

    await page.locator('[data-testid="datatable-layout-option-1280"]').click();
    await expect(page).toHaveURL(/layout=1280/);

    await page.locator('[data-testid="datatable-admin-open-doc"]').click();
    await expect(page.locator('[data-testid="datatable-doc-content"]')).toBeVisible();
    await expect(page.locator('[data-testid="datatable-doc-content"]')).toContainText(/Quick Start|蹇€熷叆闂?/i);
    await expect(page).toHaveURL(/layout=1280/);
    await page.locator('[data-testid="datatable-doc-link-guide"]').click();
    await expect(page.locator('[data-testid="datatable-doc-content"]')).toContainText(/Usage Guide|浣跨敤鎸囧崡/i);

    await page.locator('[data-testid="datatable-doc-open-tag"]').click();
    await expect(page.locator('[data-testid="datatable-tag-summary"]')).toBeVisible();
    await expect(page.locator('body')).toContainText(/tag_registration/i);
    await expectNoRuntimeError(page);

    await gotoBackend(page, 'datatable/backend/test/comprehensive/index?layout=1440', {
      timeout: 60000,
      waitUntil: 'domcontentloaded',
      settleMs: 1200,
    });
    await expect(page.locator('[data-testid="datatable-comprehensive-page"]')).toBeVisible();
    await expect(page.locator('[data-testid="datatable-layout-switcher"]')).toBeVisible();
    await expect(page.locator('[data-testid="backend-demo-status"]')).toBeVisible();
    await expectNoRuntimeError(page);

    assertNoPageErrors(errors);
  });

  test('direct backend demo routes boot working DataTable instances across layout variants', async ({ page }) => {
    const errors = bindPageErrors(page);
    await seedDemoData(page);

    const routeCases = [
      { route: 'datatable/backend/test/comprehensive/basic?layout=1440', tableId: 'demo-basic-table', formId: 'demo-basic-form' },
      { route: 'datatable/backend/test/comprehensive/join?layout=1280', tableId: 'demo-join-table' },
      { route: 'datatable/backend/test/comprehensive/form?layout=default', tableId: 'demo-standalone-table', formId: 'demo-standalone-form' },
      { route: 'datatable/backend/test/comprehensive/upload?layout=1440', tableId: 'demo-upload-table', formId: 'demo-upload-form' },
      { route: 'datatable/backend/test/comprehensive/transaction?layout=1280', tableId: 'demo-transaction-table', formId: 'demo-transaction-form' },
      { route: 'datatable/backend/test/comprehensive/dependency?layout=blank', tableId: 'demo-dependency-table', formId: 'demo-dependency-form' },
      { route: 'datatable/backend/test/comprehensive/cascade?layout=1440', tableId: 'demo-cascade-users' },
      { route: 'datatable/backend/test/comprehensive/performance?layout=1280', tableId: 'demo-performance-table' },
    ];

    for (const routeCase of routeCases) {
      await gotoBackend(page, routeCase.route, {
        timeout: 60000,
        waitUntil: 'domcontentloaded',
        settleMs: 1800,
      });
      await expectNoRuntimeError(page);
      await expect(page.locator('[data-testid="datatable-layout-switcher"]')).toBeVisible();
      await waitForTable(page, routeCase.tableId);
      if (routeCase.formId) {
        await waitForForm(page, routeCase.formId);
      }
    }

    const performanceState = await readTableState(page, 'demo-performance-table');
    expect(performanceState.allFields.length).toBeGreaterThan(5);
    expect(performanceState.displayFields.map(field => field.name)).toContain('name');

    await gotoBackend(page, 'datatable/backend/test/comprehensive/join?layout=1280', {
      timeout: 60000,
      waitUntil: 'domcontentloaded',
      settleMs: 1800,
    });
    const joinedState = await readTableState(page, 'demo-join-table');
    expect(joinedState.displayFields.map(field => field.name)).toContain('u.name');
    expect(joinedState.displayFields.map(field => field.name)).toContain('o.order_no');

    assertNoPageErrors(errors);
  });

  test('compatibility routes and inheritance verification stay functional', async ({ page }) => {
    const errors = bindPageErrors(page);
    await seedDemoData(page);

    const compatibilityRoutes = [
      { route: 'datatable/backend/test/comprehensive/filter?layout=1280', tableId: 'demo-basic-table', formId: 'demo-basic-form' },
      { route: 'datatable/backend/test/comprehensive/sorting?layout=1440', tableId: 'demo-basic-table', formId: 'demo-basic-form' },
      { route: 'datatable/backend/test/comprehensive/crud?layout=default', tableId: 'demo-basic-table', formId: 'demo-basic-form' },
      { route: 'datatable/backend/test/comprehensive/field-types?layout=1280', tableId: 'demo-upload-table', formId: 'demo-upload-form' },
      { route: 'datatable/backend/test/comprehensive/multi-model?layout=blank', tableId: 'demo-join-table' },
      { route: 'datatable/backend/test/comprehensive/auto-generation?layout=1440', tableId: 'demo-performance-table' },
    ];

    for (const routeCase of compatibilityRoutes) {
      await gotoBackend(page, routeCase.route, {
        timeout: 60000,
        waitUntil: 'domcontentloaded',
        settleMs: 1800,
      });
      await expectNoRuntimeError(page);
      await expect(page.locator('[data-testid="datatable-layout-switcher"]')).toBeVisible();
      await waitForTable(page, routeCase.tableId);
      if (routeCase.formId) {
        await waitForForm(page, routeCase.formId);
      }
    }

    await gotoBackend(page, 'datatable/backend/test/comprehensive/index?layout=1440', {
      timeout: 60000,
      waitUntil: 'domcontentloaded',
      settleMs: 1200,
    });
    await page.locator('[data-testid="backend-demo-verify"]').click();
    await expect(page.locator('[data-testid="backend-verify-output"]')).toContainText(/attribute_inheritance|auto_generation/i, {
      timeout: 15000,
    });

    await gotoBackend(page, 'datatable/backend/test/comprehensive/inheritance?layout=1280', {
      timeout: 60000,
      waitUntil: 'domcontentloaded',
      settleMs: 1200,
    });
    await expect(page.locator('[data-testid="datatable-layout-switcher"]')).toBeVisible();
    await page.locator('[data-testid="datatable-inheritance-run"]').click();
    await expect(page.locator('[data-testid="datatable-inheritance-output"]')).toContainText(/model_inheritance|sortable_inheritance/i, {
      timeout: 15000,
    });
    await expectNoRuntimeError(page);

    assertNoPageErrors(errors);
  });
});
