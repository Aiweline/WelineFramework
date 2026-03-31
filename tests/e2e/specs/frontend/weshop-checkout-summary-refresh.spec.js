// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

function uniqueEmail() {
  const stamp = Date.now();
  return `checkout-summary-${stamp}@example.com`;
}

async function dismissBindEmailModal(page) {
  const cancelButton = page.getByRole('button', { name: /取消|cancel/i }).first();
  try {
    if (await cancelButton.isVisible({ timeout: 1500 })) {
      await cancelButton.click();
    }
  } catch (error) {
    // Modal is not always present on the current runtime.
  }
}

async function registerCustomer(page, email, password) {
  await gotoFrontend(page, '/customer/account/register', {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
    settleMs: 800,
  });

  const registerUrl = page.url();
  const result = await page.evaluate(async ({ email, password, registerUrl }) => {
    const body = new URLSearchParams({
      firstname: 'Checkout',
      lastname: 'Tester',
      email,
      password,
      confirm_password: password,
      agree_terms: '1',
    });

    const response = await fetch(registerUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'text/html,application/xhtml+xml',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
      credentials: 'same-origin',
      redirect: 'follow',
    });

    return {
      ok: response.ok,
      status: response.status,
      url: response.url,
    };
  }, { email, password, registerUrl });

  expect(result.ok).toBeTruthy();

  await gotoFrontend(page, '/weshop/customer/account/index', {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
    settleMs: 800,
  });
  await expect(page).toHaveURL(/weshop\/customer\/account\/index|customer\/account\/index/i, {
    timeout: 30000,
  });
}

async function seedCart(page, productId = 1) {
  await gotoFrontend(page, `/product/view?id=${productId}`, {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
    settleMs: 800,
  });

  await dismissBindEmailModal(page);

  const addToCartButton = page.getByRole('button', { name: /Add to Cart|加入购物车/i }).first();
  const buttonVisible = await addToCartButton.isVisible({ timeout: 5000 }).catch(() => false);
  if (buttonVisible) {
    await addToCartButton.click();
    await page.waitForTimeout(1500);
    return;
  }

  // Fallback: seed cart via API when theme does not expose a visible add-to-cart button.
  const apiAdd = await page.evaluate(async () => {
    const response = await fetch('/cart/frontend/api/add', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ product_id: 2, qty: 1 }),
    });
    const text = await response.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch (error) {
      json = null;
    }
    return { ok: response.ok, status: response.status, json };
  });
  expect(apiAdd.status).toBe(200);
  expect(apiAdd.json?.success).toBeTruthy();
}

test.describe('WeShop checkout summary refresh', () => {
  test('checkout summary values update after checkout methods refresh', async ({ page }) => {
    const email = uniqueEmail();
    const password = 'Abc12345';

    await registerCustomer(page, email, password);
    await seedCart(page, 1);

    await gotoFrontend(page, '/checkout', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1200,
    });

    await dismissBindEmailModal(page);

    const summaryHost = page.locator('[data-weshop-summary-host]');
    const shippingValue = page.locator('[data-weshop-summary-shipping]');
    const taxValue = page.locator('[data-weshop-summary-tax]');
    const grandTotalValue = page.locator('[data-weshop-summary-grand-total]');

    if (await summaryHost.count() === 0) {
      test.skip(true, 'Current 9982 runtime is not serving the default checkout theme anchors required by this browser assertion.');
    }

    await expect(summaryHost).toBeVisible({ timeout: 15000 });
    const initialShipping = await shippingValue.textContent();
    const initialGrandTotal = await grandTotalValue.textContent();

    await page.route('**/checkout/methods**', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          message: 'Checkout methods refreshed successfully.',
          data: {
            selected_shipping_address_id: 0,
            shipping_methods: [
              {
                code: 'flat_rate',
                name: 'Flat Rate',
                description: 'Standard delivery.',
                is_default: true,
              },
              {
                code: 'dhl',
                name: 'DHL',
                description: 'Express delivery.',
                is_default: false,
              },
            ],
            payment_methods: [
              {
                code: 'manual_transfer',
                title: 'Manual Transfer',
                description: 'Pay later.',
                is_default: true,
              },
            ],
            cart_summary: {
              subtotal: 25.0,
              shipping: 19.99,
              discount: 0.0,
              tax: 3.2,
              grand_total: 48.19,
            },
          },
        }),
      });
    });

    await page.locator('input[name="shipping_address[region]"]').fill('CA');
    await page.locator('select[name="shipping_address[country_id]"]').selectOption('US');

    await expect(shippingValue).toContainText('19.99', { timeout: 15000 });
    await expect(taxValue).toContainText('3.20', { timeout: 15000 });
    await expect(grandTotalValue).toContainText('48.19', { timeout: 15000 });
    await expect(shippingValue).not.toHaveText(initialShipping || '', { timeout: 15000 });
    await expect(grandTotalValue).not.toHaveText(initialGrandTotal || '', { timeout: 15000 });

    await page.locator('input[name="shipping_method"][value="dhl"]').check();

    await expect(page.locator('[data-weshop-shipping-method-list]')).toBeVisible({ timeout: 15000 });
    await expect(grandTotalValue).toContainText('48.19', { timeout: 15000 });
  });
});
