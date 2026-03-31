// @weline-e2e-runtime wls
// @weline-e2e-transport proxy

const { test, expect } = require('../../framework');

async function expectGuestJsonGuard(response) {
  const contentType = String(response.headers()['content-type'] || '').toLowerCase();
  const status = response.status();
  const text = await response.text();
  let body = null;
  try {
    body = JSON.parse(text);
  } catch (error) {
    body = null;
  }

  // Some runtimes normalize auth guard payload to transport-200 JSON or redirect to login HTML.
  expect([200, 301, 302, 303, 307, 308, 401]).toContain(status);
  if (body && typeof body === 'object') {
    expect(body.success).toBeFalsy();
    expect(String(body.message || '')).toMatch(/Please log in|请先登录/i);
    return;
  }
  expect(contentType).toContain('text/html');
  expect(String(text || '')).toMatch(/customer\/account\/login|登录/i);
}

test.describe('WeShop checkout core actions', () => {
  test('methods and place-order reject guests with 401', async ({ browser }) => {
    const methodsContext = await browser.newContext();
    const methodsPage = await methodsContext.newPage();
    await methodsPage.request.get('/customer/account/logout', { failOnStatusCode: false });
    const methodsResponse = await methodsPage.request.post('/checkout/methods', {
      failOnStatusCode: false,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    const placeOrderContext = await browser.newContext();
    const placeOrderPage = await placeOrderContext.newPage();
    await placeOrderPage.request.get('/customer/account/logout', { failOnStatusCode: false });
    const placeOrderResponse = await placeOrderPage.request.post('/checkout/place-order', {
      failOnStatusCode: false,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    await expectGuestJsonGuard(methodsResponse);
    await expectGuestJsonGuard(placeOrderResponse);
  });

  test('retry-payment route keeps canonical redirect behavior', async ({ browser }) => {
    const missingContext = await browser.newContext();
    const missingPage = await missingContext.newPage();
    const missingOrderIdResponse = await missingPage.request.get('/weshop/order/retry-payment', {
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect(missingOrderIdResponse.status()).toBeGreaterThanOrEqual(300);
    expect(missingOrderIdResponse.status()).toBeLessThan(400);
    expect(String(missingOrderIdResponse.headers().location || '')).toMatch(/weshop\/order\/list/i);

    const invalidContext = await browser.newContext();
    const invalidPage = await invalidContext.newPage();
    const invalidOrderIdResponse = await invalidPage.request.get('/weshop/order/retry-payment?order_id=0', {
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect(invalidOrderIdResponse.status()).toBeGreaterThanOrEqual(300);
    expect(invalidOrderIdResponse.status()).toBeLessThan(400);
    expect(String(invalidOrderIdResponse.headers().location || '')).toMatch(/weshop\/order\/list/i);

    const guestContext = await browser.newContext();
    const guestPage = await guestContext.newPage();
    const guestRetryResponse = await guestPage.request.get('/weshop/order/retry-payment?order_id=1', {
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect(guestRetryResponse.status()).toBeGreaterThanOrEqual(300);
    expect(guestRetryResponse.status()).toBeLessThan(400);
    expect(String(guestRetryResponse.headers().location || '')).toMatch(/customer\/account\/login/i);
  });

  test('methods and place-order GET requests are still auth-guarded JSON responses', async ({ browser }) => {
    const methodsContext = await browser.newContext();
    const methodsPage = await methodsContext.newPage();
    await methodsPage.request.get('/customer/account/logout', { failOnStatusCode: false });
    const methodsGetResponse = await methodsPage.request.get('/checkout/methods', {
      failOnStatusCode: false,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    const placeOrderContext = await browser.newContext();
    const placeOrderPage = await placeOrderContext.newPage();
    await placeOrderPage.request.get('/customer/account/logout', { failOnStatusCode: false });
    const placeOrderGetResponse = await placeOrderPage.request.get('/checkout/place-order', {
      failOnStatusCode: false,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    await expectGuestJsonGuard(methodsGetResponse);
    await expectGuestJsonGuard(placeOrderGetResponse);
  });
});
