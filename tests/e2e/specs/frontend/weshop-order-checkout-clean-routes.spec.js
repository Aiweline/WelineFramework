// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

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

    const placeOrderPayload = await page.evaluate(async () => {
      const response = await fetch('/checkout/place-order', {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      return {
        ok: response.ok,
        status: response.status,
        json: await response.json(),
      };
    });

    expect(placeOrderPayload.ok).toBeTruthy();
    expect(placeOrderPayload.status).toBe(200);
    expect(placeOrderPayload.json.success).toBeFalsy();
    expect(String(placeOrderPayload.json.message || '')).toMatch(/Please log in|璇峰厛鐧诲綍/i);

    const methodsPayload = await page.evaluate(async () => {
      const formData = new FormData();
      formData.append('shipping_address_id', '3');
      formData.append('shipping_address[country_id]', 'GB');

      const response = await fetch('/checkout/methods', {
        method: 'POST',
        body: formData,
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      return {
        ok: response.ok,
        status: response.status,
        json: await response.json(),
      };
    });

    expect(methodsPayload.ok).toBeTruthy();
    expect(methodsPayload.status).toBe(200);
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

    await expect(page).toHaveURL(/weshop\/customer\/account\/login/i, { timeout: 15000 });
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
});
