// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('WeShop Order backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  test('renders order management index without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Order', 'order', 'index');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('renders order detail page (id=1) without PHP errors', async ({ page }) => {
    const route = buildModuleBackendRoute('WeShop_Order', 'order', 'view');
    await gotoBackend(page, `${route}?id=1`, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('updates order status from backend list flow', async ({ page }) => {
    const indexRoute = buildModuleBackendRoute('WeShop_Order', 'order', 'index');
    await gotoBackend(page, indexRoute, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);

    const candidate = await page.evaluate(() => {
      const rows = Array.from(document.querySelectorAll('table tbody tr'));
      const transitionMap = {
        pending: 'processing',
        processing: 'paid',
      };

      for (const row of rows) {
        const trigger = row.querySelector('button[onclick*="WeShopOrder.openStatusUpdate"]');
        if (!trigger) {
          continue;
        }

        const onclick = String(trigger.getAttribute('onclick') || '');
        const match = onclick.match(/openStatusUpdate\((\d+),\s*"([^"]+)"\)/i);
        if (!match) {
          continue;
        }

        const orderId = Number(match[1] || 0);
        const currentStatus = String(match[2] || '').toLowerCase();
        const targetStatus = transitionMap[currentStatus] || '';
        if (orderId > 0 && targetStatus) {
          return {
            orderId,
            currentStatus,
            targetStatus,
            updateUrl: window.WeShopOrder?.statusUpdateUrl || '',
          };
        }
      }

      return null;
    });

    test.skip(!candidate, 'No pending/processing order was found for status update verification.');
    expect(candidate.updateUrl).toBeTruthy();

    const requestUrl = new URL(candidate.updateUrl, page.url()).toString();
    const response = await page.request.post(requestUrl, {
      form: {
        id: String(candidate.orderId),
        status: String(candidate.targetStatus),
        back_url: '/@backend/order/backend/order/index',
      },
      timeout: 30000,
    });
    const responseText = await response.text();

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);
    expect(/WLS Runtime Error|ParseError|syntax error|Fatal error/i.test(responseText)).toBeFalsy();

    const viewRoute = buildModuleBackendRoute('WeShop_Order', 'order', 'view');
    await gotoBackend(page, `${viewRoute}?id=${candidate.orderId}`, {
      timeout: 60000,
      settleMs: 1200,
    });

    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);

    const statusText = await page.locator('.order-status-badge').first().textContent();
    expect(String(statusText || '').toLowerCase()).toContain(candidate.targetStatus);
  });

  test('rejects invalid order id update without runtime errors', async ({ page }) => {
    const indexRoute = buildModuleBackendRoute('WeShop_Order', 'order', 'index');
    await gotoBackend(page, indexRoute, {
      timeout: 60000,
      settleMs: 1000,
    });

    const updateUrl = await page.evaluate(() => window.WeShopOrder?.statusUpdateUrl || '');
    expect(updateUrl).toBeTruthy();

    const response = await page.request.post(new URL(updateUrl, page.url()).toString(), {
      form: {
        id: '0',
        status: 'processing',
        back_url: 'https://evil.example/redirect',
      },
      timeout: 30000,
    });
    const responseText = await response.text();

    expect(response.status()).toBe(200);
    expect(/WLS Runtime Error|ParseError|syntax error|Fatal error/i.test(responseText)).toBeFalsy();
    expect(responseText).toMatch(/Order ID is required|订单/i);
  });

  test('rejects empty status update without runtime errors', async ({ page }) => {
    const indexRoute = buildModuleBackendRoute('WeShop_Order', 'order', 'index');
    await gotoBackend(page, indexRoute, {
      timeout: 60000,
      settleMs: 1000,
    });

    const updateUrl = await page.evaluate(() => window.WeShopOrder?.statusUpdateUrl || '');
    expect(updateUrl).toBeTruthy();

    const response = await page.request.post(new URL(updateUrl, page.url()).toString(), {
      form: {
        id: '1',
        status: '',
      },
      timeout: 30000,
    });
    const responseText = await response.text();

    expect(response.status()).toBe(200);
    expect(/WLS Runtime Error|ParseError|syntax error|Fatal error/i.test(responseText)).toBeFalsy();
    expect(responseText).toMatch(/Unsupported order status|状态/i);
  });
});
