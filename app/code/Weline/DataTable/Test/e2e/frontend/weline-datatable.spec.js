// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i, {
    timeout: 15000,
  });
}

function assertNoPageErrors(errors) {
  expect(errors, errors.join('\n')).toEqual([]);
}

async function frontendPost(page, route, payload = {}) {
  return page.evaluate(async ({ route, payload }) => {
    const rawRoute = String(route || '').trim();
    const normalizedRoute = rawRoute.replace(/^\/+/, '');
    let requestUrl = rawRoute;

    if (!/^https?:\/\//i.test(rawRoute) && typeof window.api === 'function' && normalizedRoute.startsWith('datatable/rest/')) {
      requestUrl = window.api(normalizedRoute);
    } else if (!/^https?:\/\//i.test(rawRoute)) {
      requestUrl = new URL(rawRoute, window.location.origin).toString();
    }

    const response = await fetch(requestUrl, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
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
  }, { route, payload });
}

async function seedDemoData(page) {
  const payload = await frontendPost(page, '/datatable/rest/v1/demo-table/init-data', {});
  expect(payload.ok).toBeTruthy();
  expect(payload.status).toBe(200);
  expect(payload.json.success).toBeTruthy();
}

async function waitForTable(page, tableId) {
  await page.waitForFunction((id) => {
    const root = document.getElementById(`w-datatable-${id}`);
    const instance = root && window.Weline?.UI?.get(root, 'data-table');
    return !!instance && Array.isArray(instance.state?.data) && instance.state.data.length > 0;
  }, tableId, { timeout: 30000 });
}

async function waitForForm(page, formId) {
  await page.waitForFunction((id) => {
    const form = document.getElementById(id);
    const root = form?.closest('[data-w-component~="data-table-form"]');
    return !!(root && window.Weline?.UI?.get(root, 'data-table-form'));
  }, formId, { timeout: 30000 });
}

async function readTableState(page, tableId) {
  return page.evaluate((id) => {
    const root = document.getElementById(`w-datatable-${id}`);
    const instance = root && window.Weline?.UI?.get(root, 'data-table');

    if (!instance) {
      return null;
    }

    return {
      options: JSON.parse(root.dataset.wConfig || '{}'),
      data: Array.isArray(instance.state?.data) ? instance.state.data : [],
      displayFields: Array.isArray(instance.state?.displayFields) ? instance.state.displayFields : [],
      allFields: Array.isArray(instance.state?.allFields) ? instance.state.allFields : [],
      filterFields: Array.isArray(instance.state?.filterFields) ? instance.state.filterFields : [],
    };
  }, tableId);
}

test.describe('Weline DataTable frontend demos', () => {
  test('index, basic and join demos boot with frontend-safe routing', async ({ page }) => {
    const errors = bindPageErrors(page);

    await gotoFrontend(page, '/datatable/test', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1200,
    });

    await expect(page.locator('[data-testid="demo-status"]')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    await page.click('[data-testid="demo-init"]');
    await expect(page.locator('[data-testid="demo-status"]')).toContainText(/initialized|done/i, { timeout: 15000 });

    await gotoFrontend(page, '/datatable/test/basic', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1500,
    });
    await expectNoRuntimeError(page);
    await waitForTable(page, 'demo-basic-table');
    await waitForForm(page, 'demo-basic-form');

    const basicState = await readTableState(page, 'demo-basic-table');
    expect(basicState.options.apiProvider).toBe('datatable');
    expect(basicState.options.operations.data).toBe('data');
    expect(basicState.options.operations.fields).toBe('fields');
    expect(basicState.data.length).toBeGreaterThan(0);

    const basicFormState = await page.evaluate(() => {
      const form = document.getElementById('demo-basic-form');
      const root = form?.closest('[data-w-component~="data-table-form"]');
      return root ? JSON.parse(root.dataset.wConfig || '{}') : null;
    });
    expect(basicFormState.apiProvider).toBe('datatable');
    expect(basicFormState.operations.formFields).toBe('formFields');

    await gotoFrontend(page, '/datatable/test/join', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1500,
    });
    await expectNoRuntimeError(page);
    await waitForTable(page, 'demo-join-table');

    const joinState = await readTableState(page, 'demo-join-table');
    const joinFieldNames = joinState.displayFields.map(field => field.name);
    expect(joinFieldNames).toContain('u.name');
    expect(joinFieldNames).toContain('o.order_no');
    expect(Object.prototype.hasOwnProperty.call(joinState.data[0], 'u.name')).toBeTruthy();
    expect(Object.prototype.hasOwnProperty.call(joinState.data[0], 'o.order_no')).toBeTruthy();

    assertNoPageErrors(errors);
  });

  test('standalone, upload, transaction and dependency forms submit successfully', async ({ page }) => {
    const errors = bindPageErrors(page);

    await gotoFrontend(page, '/datatable/test', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1000,
    });
    await seedDemoData(page);

    await gotoFrontend(page, '/datatable/test/form', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1500,
    });
    await expectNoRuntimeError(page);
    await waitForForm(page, 'demo-standalone-form');
    await waitForTable(page, 'demo-standalone-table');

    await page.fill('#demo-standalone-form input[name="name"]', 'Frontend Form User');
    await page.fill('#demo-standalone-form input[name="email"]', 'frontend.form.user@example.com');
    await page.fill('#demo-standalone-form input[name="phone"]', '13800138088');
    await page.selectOption('#demo-standalone-form select[name="status"]', '1');
    await page.click('button[type="submit"][form="demo-standalone-form"]');

    await page.waitForFunction(() => {
      const root = document.getElementById('w-datatable-demo-standalone-table');
      const instance = root && window.Weline?.UI?.get(root, 'data-table');
      return !!instance && Array.isArray(instance.state?.data) && instance.state.data.some(row => row.email === 'frontend.form.user@example.com');
    }, null, { timeout: 30000 });

    await gotoFrontend(page, '/datatable/test/upload', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1500,
    });
    await expectNoRuntimeError(page);
    await waitForForm(page, 'demo-upload-form');
    await waitForTable(page, 'demo-upload-table');

    await page.fill('#demo-upload-form input[name="name"]', 'Upload Demo User');
    await page.fill('#demo-upload-form input[name="email"]', 'upload.demo.user@example.com');
    await page.locator('#demo-upload-form input[name="photo"]').setInputFiles({
      name: 'demo-photo.png',
      mimeType: 'image/png',
      buffer: Buffer.from('89504E470D0A1A0A', 'hex'),
    });
    await page.locator('#demo-upload-form input[name="attachment"]').setInputFiles({
      name: 'demo-attachment.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('demo attachment body', 'utf8'),
    });

    await expect(page.locator('[data-w-component~="data-table-form"]:has(#demo-upload-form) .w-datatable-form__file-preview img')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-w-component~="data-table-form"]:has(#demo-upload-form) .w-datatable-form__file-preview')).toContainText('demo-attachment.txt', { timeout: 15000 });

    await page.click('button[type="submit"][form="demo-upload-form"]');
    await page.waitForFunction(() => {
      const root = document.getElementById('w-datatable-demo-upload-table');
      const instance = root && window.Weline?.UI?.get(root, 'data-table');
      return !!instance && Array.isArray(instance.state?.data) && instance.state.data.some(row => row.email === 'upload.demo.user@example.com');
    }, null, { timeout: 30000 });

    await gotoFrontend(page, '/datatable/test/transaction', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1800,
    });
    await expectNoRuntimeError(page);
    await waitForForm(page, 'demo-transaction-form');
    await waitForTable(page, 'demo-transaction-table');

    await page.fill('#demo-transaction-form input[name="u.name"]', 'Txn User');
    await page.fill('#demo-transaction-form input[name="u.email"]', 'txn.user@example.com');
    await page.fill('#demo-transaction-form input[name="u.phone"]', '13800138066');
    await page.selectOption('#demo-transaction-form select[name="u.status"]', '1');
    await page.fill('#demo-transaction-form input[name="o.order_no"]', 'TXN-ORDER-001');
    await page.fill('#demo-transaction-form input[name="o.total_amount"]', '123.45');
    await page.selectOption('#demo-transaction-form select[name="o.order_status"]', '1');
    await page.selectOption('#demo-transaction-form select[name="o.payment_status"]', '1');
    await page.click('button[type="submit"][form="demo-transaction-form"]');

    await page.waitForFunction(() => {
      const root = document.getElementById('w-datatable-demo-transaction-table');
      const instance = root && window.Weline?.UI?.get(root, 'data-table');
      return !!instance && Array.isArray(instance.state?.data) && instance.state.data.some(row => row['o.order_no'] === 'TXN-ORDER-001' && row['u.email'] === 'txn.user@example.com');
    }, null, { timeout: 30000 });

    await gotoFrontend(page, '/datatable/test/dependency', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1800,
    });
    await expectNoRuntimeError(page);
    await waitForForm(page, 'demo-dependency-form');
    await waitForTable(page, 'demo-dependency-table');

    await page.fill('#demo-dependency-form input[name="u.name"]', 'Dependency User');
    await page.fill('#demo-dependency-form input[name="u.email"]', 'dependency.user@example.com');
    await page.selectOption('#demo-dependency-form select[name="u.status"]', '1');
    await page.fill('#demo-dependency-form input[name="o.order_no"]', 'DEPEND-ORDER-001');
    await page.fill('#demo-dependency-form input[name="o.final_amount"]', '88.80');
    await page.selectOption('#demo-dependency-form select[name="o.payment_status"]', '1');
    await page.selectOption('#demo-dependency-form select[name="o.order_status"]', '2');
    await page.click('button[type="submit"][form="demo-dependency-form"]');

    await page.waitForFunction(() => {
      const root = document.getElementById('w-datatable-demo-dependency-table');
      const instance = root && window.Weline?.UI?.get(root, 'data-table');
      if (!instance || !Array.isArray(instance.state?.data)) {
        return false;
      }

      return instance.state.data.some(row => row['o.order_no'] === 'DEPEND-ORDER-001' && String(row['o.user_id']) === String(row['u.id']));
    }, null, { timeout: 30000 });

    assertNoPageErrors(errors);
  });

  test('cascade delete and auto-generated performance table stay stable', async ({ page }) => {
    const errors = bindPageErrors(page);

    await gotoFrontend(page, '/datatable/test', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1000,
    });
    await seedDemoData(page);

    await gotoFrontend(page, '/datatable/test/cascade', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1500,
    });
    await expectNoRuntimeError(page);
    await waitForTable(page, 'demo-cascade-users');
    await waitForTable(page, 'demo-cascade-orders');

    const beforeCascade = await frontendPost(page, '/datatable/rest/v1/demo-table/data', {
      model: 'Weline\\DataTable\\Model\\TestOrder',
      page: 1,
      pageSize: 20,
    });
    expect(beforeCascade.json.data.data.some(row => String(row.user_id) === '1')).toBeTruthy();

    const deletePayload = await frontendPost(page, '/datatable/rest/v1/demo-table/delete-data', {
      model: 'Weline\\DataTable\\Model\\TestUser',
      ids: [1],
    });
    expect(deletePayload.ok).toBeTruthy();
    expect(deletePayload.json.success).toBeTruthy();

    await page.click('[data-testid="cascade-refresh"]');
    await page.waitForFunction(() => {
      const userRoot = document.getElementById('w-datatable-demo-cascade-users');
      const orderRoot = document.getElementById('w-datatable-demo-cascade-orders');
      const userTable = userRoot && window.Weline?.UI?.get(userRoot, 'data-table');
      const orderTable = orderRoot && window.Weline?.UI?.get(orderRoot, 'data-table');
      return !!userTable
        && !!orderTable
        && Array.isArray(userTable.state?.data)
        && Array.isArray(orderTable.state?.data)
        && !userTable.state.data.some(row => String(row.id) === '1')
        && !orderTable.state.data.some(row => String(row.user_id) === '1');
    }, null, { timeout: 30000 });

    await gotoFrontend(page, '/datatable/test/performance', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 1500,
    });
    await expectNoRuntimeError(page);
    await waitForTable(page, 'demo-performance-table');

    const performanceState = await readTableState(page, 'demo-performance-table');
    const performanceFieldNames = performanceState.displayFields.map(field => field.name);
    expect(performanceState.allFields.length).toBeGreaterThan(5);
    expect(performanceFieldNames).toContain('name');
    expect(performanceFieldNames).toContain('sku');
    expect(performanceState.data.length).toBeGreaterThan(0);

    await page.click('[data-testid="performance-reload"]');
    await waitForTable(page, 'demo-performance-table');

    assertNoPageErrors(errors);
  });
});
