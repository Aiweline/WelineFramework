// @weline-e2e-runtime wls
// @weline-e2e-transport proxy

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop order and checkout clean routes', () => {
  test('checkout clean routes protect guests without runtime failures', async ({ page }) => {
    await gotoFrontend(page, '/checkout', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/customer\/account\/login/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    const placeOrderResponse = await page.request.post('/checkout/place-order', {
      failOnStatusCode: false,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    const placeOrderPayload = {
      ok: placeOrderResponse.ok(),
      status: placeOrderResponse.status(),
      json: await placeOrderResponse.json(),
    };

    expect(placeOrderPayload.ok).toBeFalsy();
    expect(placeOrderPayload.status).toBe(401);
    expect(placeOrderPayload.json.success).toBeFalsy();
    expect(String(placeOrderPayload.json.message || '')).toMatch(/Please log in|璇峰厛鐧诲綍/i);

    const methodsResponse = await page.request.post('/checkout/methods', {
      failOnStatusCode: false,
      form: {
        shipping_address_id: '3',
        'shipping_address[country_id]': 'GB',
      },
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    const methodsPayload = {
      ok: methodsResponse.ok(),
      status: methodsResponse.status(),
      json: await methodsResponse.json(),
    };

    expect(methodsPayload.ok).toBeFalsy();
    expect(methodsPayload.status).toBe(401);
    expect(methodsPayload.json.success).toBeFalsy();
    expect(String(methodsPayload.json.message || '')).toMatch(/Please log in|璇峰厛鐧诲綍/i);

    await gotoFrontend(page, '/checkout/success', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/cart|weshop\/customer\/account\/login/i, { timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/weshop/cart', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/customer\/account\/login|weshop\/order\/list/i, { timeout: 15000 });
    await expectNoRuntimeError(page);
  });

  test('order clean routes protect guests without runtime failures', async ({ page }) => {
    await gotoFrontend(page, '/weshop/order/list', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/customer\/account\/login/i, { timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/weshop/order/view?id=1', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/customer\/account\/login/i, { timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/weshop/order/retry-payment?order_id=1', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/customer\/account\/login/i, { timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/weshop/order/cancel', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/weshop\/customer\/account\/login/i, { timeout: 15000 });
    await expectNoRuntimeError(page);
  });

  test('retry-payment endpoint keeps canonical redirect behavior', async ({ page }) => {
    const missingOrderIdResponse = await page.request.get('/weshop/order/retry-payment', {
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect(missingOrderIdResponse.status()).toBeGreaterThanOrEqual(300);
    expect(missingOrderIdResponse.status()).toBeLessThan(400);
    expect(String(missingOrderIdResponse.headers().location || '')).toMatch(/weshop\/order\/list/i);

    const guestRetryResponse = await page.request.get('/weshop/order/retry-payment?order_id=1', {
      failOnStatusCode: false,
      maxRedirects: 0,
    });
    expect(guestRetryResponse.status()).toBeGreaterThanOrEqual(300);
    expect(guestRetryResponse.status()).toBeLessThan(400);
    expect(String(guestRetryResponse.headers().location || '')).toMatch(/weshop\/customer\/account\/login/i);
  });
});
