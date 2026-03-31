// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
} = require('../../framework');

const MODULE_NAME = 'WeShop_Logistics';

test.describe('WeShop Logistics backend (smoke)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('TC-01: renders tracking management page', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'tracking');
    await gotoBackend(page, route, {
      timeout: 180000,
      settleMs: 1200,
    });
  });

  test('TC-02: renders shipment management page', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'shipment');
    await gotoBackend(page, route, {
      timeout: 180000,
      settleMs: 1500,
    });
  });

  test('TC-03: keeps selected filters on tracking index query', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'tracking');
    await gotoBackend(page, `${route}?carrier=DHL&status=pending&tracking_number=E2E-TRACK`, {
      timeout: 180000,
      settleMs: 1200,
    });

    await expect(page.locator('h1.h3')).toContainText(/Tracking Management/i);
    await expect(page.locator('select[name="carrier"]')).toHaveValue('DHL');
    await expect(page.locator('select[name="status"]')).toHaveValue('pending');
    await expect(page.locator('input[name="tracking_number"]')).toHaveValue('E2E-TRACK');
  });

  test('TC-04: submits tracking form and shows validation error for missing order id', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'tracking');
    await gotoBackend(page, route, {
      timeout: 180000,
      settleMs: 1200,
    });

    await expect(page.locator('h1.h3')).toContainText(/Tracking Management/i);
    await expect(page.locator('input[name="order_id"]')).toHaveValue('0');

    await page.locator('input[name="tracking_number"]').fill(`E2E-${Date.now()}`);
    await page.locator('button[type="submit"]').filter({ hasText: /Save Tracking Event/i }).click();

    await expect(page).toHaveURL(new RegExp(`${route.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
    await expect(page.locator('body')).toContainText(/Order ID is required\./i);
    await expect(page.locator('h1.h3')).toContainText(/Tracking Management/i);
  });

  test('TC-05: redirects track detail without shipment id back to shipment list with error', async ({ page }) => {
    const trackRoute = buildModuleBackendRoute(MODULE_NAME, 'shipment', 'track');
    const shipmentRoute = buildModuleBackendRoute(MODULE_NAME, 'shipment');
    await gotoBackend(page, trackRoute, {
      timeout: 180000,
      settleMs: 1200,
    });

    await expect(page).toHaveURL(new RegExp(shipmentRoute.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    await expect(page.locator('body')).toContainText(/Shipment ID is required\./i);
    await expect(page.locator('h4.mb-sm-0')).toContainText(/Shipment Management/i);
  });

  test('TC-06: opens first tracking record from list and keeps edit form values in sync', async ({ page }) => {
    const route = buildModuleBackendRoute(MODULE_NAME, 'tracking');
    await gotoBackend(page, route, {
      timeout: 180000,
      settleMs: 1200,
    });

    await expect(page.locator('h1.h3')).toContainText(/Tracking Management/i);

    const firstRow = page.locator('tbody tr').first();
    const hasRow = (await firstRow.count()) > 0;
    test.skip(!hasRow, 'No tracking record available for edit flow.');

    const expectedOrderId = ((await firstRow.locator('td').nth(0).textContent()) || '').trim();
    const expectedTrackingNumber = ((await firstRow.locator('td').nth(1).textContent()) || '').trim();
    const expectedCarrier = ((await firstRow.locator('td').nth(2).textContent()) || '').trim();

    await firstRow.locator('a:has-text("Edit")').click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('h2.h5')).toContainText(/Edit Tracking Event/i);
    await expect(page.locator('input[name="order_id"]')).toHaveValue(expectedOrderId);
    await expect(page.locator('input[name="tracking_number"]')).toHaveValue(expectedTrackingNumber);
    await expect(page.locator('select[name="carrier"]')).toHaveValue(expectedCarrier);
  });
});
