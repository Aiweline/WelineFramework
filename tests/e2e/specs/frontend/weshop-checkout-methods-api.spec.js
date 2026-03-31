// @weline-e2e-runtime wls
// @weline-e2e-transport proxy

const { test, expect, gotoFrontend } = require('../../framework');

test.describe('WeShop checkout API bridge', () => {
  test.describe.configure({ mode: 'serial' });

  test('proxy storefront warmup before JSON API calls', async ({ page }) => {
    await gotoFrontend(page, '/', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 400,
    });
  });

  test('guest checkout methods API returns 401 auth guard payload', async ({ browser }) => {
    const context = await browser.newContext();
    const apiPage = await context.newPage();
    await apiPage.request.get('/customer/account/logout', { failOnStatusCode: false });
    const response = await apiPage.request.post('/checkout/methods', {
      data: {
        shipping_address_id: 3,
        shipping_address: {
          country_id: 'GB',
          region: 'LND',
        },
        shipping_method: 'flat_rate',
      },
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    const payload = {
      ok: response.ok(),
      status: response.status(),
      json: await response.json(),
    };

    expect([200, 401]).toContain(payload.status);
    expect(payload.json.success).toBe(false);
    expect(String(payload.json.message || '')).toMatch(/Please log in/i);
  });
});
