// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WeShop_Inventory';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => {
    errors.push(String(error && error.message ? error.message : error));
  });
  return errors;
}

function parseNumberFromCellText(text) {
  const cleaned = String(text || '').replace(/,/g, '').trim();
  const value = Number.parseFloat(cleaned);
  if (Number.isNaN(value)) {
    throw new Error(`Failed to parse numeric table cell value: "${text}"`);
  }
  return value;
}

function nextQuantityValue(currentValue) {
  return Number((currentValue + 1.25).toFixed(4));
}

function toFixedQuantityString(value) {
  return Number(value).toFixed(4).replace(/\.?0+$/, '');
}

function parseNumberFromInputValue(rawValue) {
  const value = Number.parseFloat(String(rawValue ?? '').trim());
  if (Number.isNaN(value)) {
    throw new Error(`Failed to parse numeric input value: "${rawValue}"`);
  }
  return value;
}

test.describe('WeShop_Inventory backend smoke', () => {
  // loginAsAdmin 可达 90s；与用例导航叠加时 120s 总超时易在 beforeEach 触发中断
  test.describe.configure({ timeout: 200000, retries: 1 });
  const sourceRoute = buildModuleBackendRoute(MODULE_NAME, 'inventory', 'source');
  const sourceItemRoute = buildModuleBackendRoute(MODULE_NAME, 'inventory', 'source-item');

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  const routesTried = [
    sourceRoute,
    sourceItemRoute,
  ];

  for (const route of routesTried) {
    test(`GET ${route} renders without fatal runtime errors`, async ({ page }) => {
      const pageErrors = bindPageErrors(page);

      await gotoBackend(page, route, {
        timeout: 60000,
        settleMs: 1200,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      await expect(body).not.toContainText(FATAL_PATTERN);
      expect(pageErrors, pageErrors.join('\n')).toEqual([]);
    });
  }

  test(`GET ${sourceRoute} renders source management affordances`, async ({ page }) => {
    await gotoBackend(page, sourceRoute, {
      timeout: 60000,
      settleMs: 1200,
    });

    await expect(page.locator('h1.h3')).toContainText(/Inventory Sources/i);
    await expect(page.locator('form.inventory-source-delete-form, .card .table, .text-center.py-5').first()).toBeVisible({
      timeout: 15000,
    });
    const deleteForm = page.locator('form.inventory-source-delete-form').first();
    if (await deleteForm.count()) {
      await expect(deleteForm).toHaveAttribute('data-confirm-message', /.+/);
    }
  });

  test(`POST ${sourceItemRoute}/edit updates quantity and reflects in list`, async ({ page }) => {
    await gotoBackend(page, sourceItemRoute, {
      timeout: 60000,
      settleMs: 1200,
    });

    const tableRows = page.locator('table tbody tr:has(a[href*="source-item/edit?id="])');
    const rowCount = await tableRows.count();
    test.skip(rowCount === 0, 'No source-item data available to validate inventory edit-loop.');

    const editableRow = page.locator('table tbody tr:has(a[href*="source-item/edit?id="])').first();
    await expect(editableRow).toBeVisible({ timeout: 15000 });

    const sourceItemIdText = (await editableRow.locator('td').nth(0).innerText()).trim();
    const sourceItemId = Number.parseInt(sourceItemIdText, 10);
    expect(Number.isFinite(sourceItemId) && sourceItemId > 0).toBeTruthy();

    const currentQuantityText = (await editableRow.locator('td').nth(4).innerText()).trim();
    const currentQuantity = parseNumberFromCellText(currentQuantityText);
    const updatedQuantity = nextQuantityValue(currentQuantity);
    const currentQuantityNormalized = toFixedQuantityString(currentQuantity);
    const updatedQuantityNormalized = toFixedQuantityString(updatedQuantity);

    await editableRow.locator('a[href*="source-item/edit"]').click();
    await expect(page.locator('h1.h3')).toContainText(/Edit Inventory Source Item/i);

    const quantityInput = page.locator('input[name="quantity"]');
    await expect(quantityInput).toBeVisible();
    await quantityInput.fill(updatedQuantityNormalized);

    await page.locator('button[type="submit"]').click();
    await expect(page.locator('body')).toContainText(/Inventory stock updated\./i, { timeout: 30000 });
    const updatedValueFromInput = await quantityInput.inputValue();
    expect(parseNumberFromInputValue(updatedValueFromInput)).toBeCloseTo(updatedQuantity, 3);

    await gotoBackend(page, `${sourceItemRoute}?search=${encodeURIComponent(sourceItemIdText)}`, {
      timeout: 60000,
      settleMs: 1200,
    });

    const targetRow = page.locator(`tr:has(a[href*="source-item/edit?id=${sourceItemId}"])`).first();
    await expect(targetRow).toBeVisible({ timeout: 15000 });

    const persistedQuantityText = (await targetRow.locator('td').nth(4).innerText()).trim();
    const persistedQuantity = parseNumberFromCellText(persistedQuantityText);
    expect(persistedQuantity).toBeCloseTo(updatedQuantity, 3);
    expect(toFixedQuantityString(persistedQuantity)).not.toBe(currentQuantityNormalized);

    await gotoBackend(page, `${sourceItemRoute}/edit?id=${sourceItemId}`, {
      timeout: 60000,
      settleMs: 1200,
    });
    await expect(page.locator('h1.h3')).toContainText(/Edit Inventory Source Item/i);
    await quantityInput.fill(currentQuantityNormalized);
    await page.locator('button[type="submit"]').click();
    await expect(page.locator('body')).toContainText(/Inventory stock updated\./i, { timeout: 30000 });
    const restoredValueFromInput = await quantityInput.inputValue();
    expect(parseNumberFromInputValue(restoredValueFromInput)).toBeCloseTo(currentQuantity, 3);

    await gotoBackend(page, `${sourceItemRoute}?search=${encodeURIComponent(sourceItemIdText)}`, {
      timeout: 60000,
      settleMs: 1200,
    });
    const restoredRow = page.locator(`tr:has(a[href*="source-item/edit?id=${sourceItemId}"])`).first();
    await expect(restoredRow).toBeVisible({ timeout: 15000 });
    const restoredQuantityText = (await restoredRow.locator('td').nth(4).innerText()).trim();
    const restoredQuantity = parseNumberFromCellText(restoredQuantityText);
    expect(restoredQuantity).toBeCloseTo(currentQuantity, 3);
  });
});
