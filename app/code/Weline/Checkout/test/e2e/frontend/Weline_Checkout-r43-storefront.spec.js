/** @weline-e2e-spec { module: Weline_Checkout, type: flow, layer: frontend } */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  openBackendMenuBySource,
  waitForBackendShellReady,
  BACKEND_FATAL_PATTERN,
} = require('../../../../../../../tests/e2e/framework');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'commerce-r43-storefront-fixture.php');

function runFixture(payload) {
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify(payload),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const result = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).pop() || '{}');
  if (!result.ok) {
    throw new Error(result.error || output);
  }
  return result;
}

function apiSucceeded(result) {
  const payload = result && typeof result === 'object' && result.data && typeof result.data === 'object'
    ? result.data
    : result;
  return !!(result && result.success === true || payload && payload.success === true);
}

async function clearGuestCartViaBrowser(page) {
  const cookies = await page.context().cookies();
  if (!cookies.some(cookie => cookie.name === 'weline_cart_guest_token')) {
    return { skipped: true, reason: 'guest_token_cookie_missing' };
  }
  await gotoFrontend(page, '/weline_product/frontend/catalog/index', {
    timeout: 60000,
    settleMs: 300,
  });
  const result = await page.evaluate(async () => {
    let apiClient = window.Weline && window.Weline.Api;
    if ((!apiClient || typeof apiClient.resource !== 'function')
      && window.Weline && typeof window.Weline.load === 'function') {
      apiClient = await window.Weline.load('api');
    }
    if (!apiClient || typeof apiClient.resource !== 'function') {
      return { success: false, error_code: 'weline_api_unavailable' };
    }
    const cart = await apiClient.resource('cart');
    if (!cart || typeof cart.clearV2 !== 'function') {
      return { success: false, error_code: 'cart_clear_v2_unavailable' };
    }
    try {
      return await cart.clearV2({}, { useProxy: false });
    } catch (error) {
      return { success: false, error_code: String(error && (error.message || error)) };
    }
  });
  if (!apiSucceeded(result)) {
    throw new Error(`browser_cart_clear_failed:${JSON.stringify(result)}`);
  }
  return result;
}

const MODULE = 'Weline_Checkout';

moduleDescribe(test, MODULE, 'R4.3 真实商城纵切', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-STORE-101' },
    '持久化商品经真实 WebUI 加购、结账并从后台菜单回查订单',
    async ({ page }) => {
      test.setTimeout(180000);
      const prepared = runFixture({ action: 'prepare' });
      const fixture = prepared.fixture;
      const guards = installBackendBrowserGuards(page);
      let checkoutGroupUuid = '';
      let orderUuid = '';
      let browserCartCleared = false;

      try {
        await gotoFrontend(page, '/weline_product/frontend/catalog/index', {
          timeout: 60000,
          settleMs: 500,
        });
        const catalog = page.locator('[data-testid="storefront-product-catalog"]');
        await expect(catalog).toBeVisible();
        const card = catalog.locator(
          `[data-testid="storefront-product-card"][data-global-offer-uuid="${fixture.offer_uuid}"]`,
        );
        await expect(card).toHaveCount(1);
        await expect(card).toContainText(fixture.name);
        await expect(card).toContainText('CNY 129.00');

        await card.locator('[data-action="add-v2"]').click();
        const viewCart = catalog.locator('[data-testid="view-cart"]');
        await expect(viewCart).toBeVisible({ timeout: 20000 });
        await expect(catalog.locator('[data-testid="catalog-message"]')).toContainText('已加入');

        await viewCart.click();
        await expect(page).toHaveURL(/\/cart(?:[/?#]|$)/, { timeout: 30000 });
        await expect(page.locator('[data-cart-content="1"]')).toBeVisible();
        await expect(page.locator('[data-cart-content="1"]')).toContainText(fixture.name);

        await page.locator('.weline-cart-shell__checkout').click();
        await expect(page).toHaveURL(/\/checkout(?:[/?#]|$)/, { timeout: 30000 });
        const checkout = page.locator('[data-testid="checkout-form-page"]');
        await expect(checkout).toBeVisible();
        await checkout.locator('input[name="country_code"]').fill(fixture.country_code);
        await checkout.locator('input[name="name"]').fill('R43 Browser Buyer');
        await checkout.locator('input[name="phone"]').fill('13800138000');
        await checkout.locator('input[name="email"]').fill(`${fixture.run}@example.test`);
        await checkout.locator('input[name="province"]').fill('R43 Province');
        await checkout.locator('input[name="city"]').fill('R43 City');
        await checkout.locator('input[name="address1"]').fill('R43 Browser Street 101');
        await checkout.locator('input[name="postal_code"]').fill('100000');

        const shipping = checkout.locator(`input[name="shipping_method"][value="${fixture.service_code}"]`);
        await expect(shipping).toBeVisible({ timeout: 30000 });
        await shipping.check();
        const payment = checkout.locator(`input[name="payment_method"][value="${fixture.payment_method}"]`);
        await expect(payment).toBeVisible({ timeout: 30000 });
        await payment.check();
        await expect(checkout.locator('[data-submit]')).toBeEnabled();
        await checkout.locator('[data-submit]').click();

        await expect(page).toHaveURL(/\/checkout\/success-page\?/, { timeout: 60000 });
        const success = page.locator('[data-testid="checkout-success"]');
        await expect(success).toBeVisible();
        orderUuid = (await success.getAttribute('data-order-uuid')) || '';
        checkoutGroupUuid = (await success.getAttribute('data-checkout-group-uuid')) || '';
        expect(orderUuid).toMatch(/^[a-f0-9-]{36}$/i);
        expect(checkoutGroupUuid).toMatch(/^[a-f0-9-]{36}$/i);
        await expect(success).toContainText('CNY 138.90');

        const inspected = runFixture({
          action: 'inspect',
          fixture,
          order_uuid: orderUuid,
          checkout_group_uuid: checkoutGroupUuid,
        }).data;
        expect(inspected).toMatchObject({
          product_count: 1,
          product_status: 'published',
          offer_count: 1,
          offer_status: 'published',
          checkout_group_count: 1,
          order_count: 1,
          order_item_count: 1,
          order_status: 'pending',
          shipping_method: fixture.service_code,
          session_found: true,
          session_state: 'submitted',
        });
        expect(Number(inspected.order_grand_total)).toBe(138.9);
        expect(inspected.order_number).toBeTruthy();

        await clearGuestCartViaBrowser(page);
        browserCartCleared = true;

        await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
        await openBackendMenuBySource(page, 'Weline_Order::order_list', {
          title: '订单列表',
          parentSources: ['Weline_Backend::order_group'],
          urlIncludes: '/weline_order/backend/order/index',
        });
        await waitForBackendShellReady(page);
        const management = page.locator('[data-testid="order-management"]');
        await expect(management).toBeVisible();
        const search = page.locator('input[name="keyword"]');
        await search.fill(inspected.order_number);
        await Promise.all([
          page.waitForURL(url => decodeURIComponent(url.toString()).includes(inspected.order_number)),
          search.press('Enter'),
        ]);
        await waitForBackendShellReady(page);
        await expect(page.getByText(inspected.order_number, { exact: true })).toBeVisible();
        await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
        guards.assertClean();
      } finally {
        const cleanupFailures = [];
        if (!browserCartCleared) {
          try {
            if (/\/admin(?:[/?#]|$)/i.test(new URL(page.url()).pathname)) {
              await gotoFrontend(page, '/', { timeout: 60000, settleMs: 300 });
            }
            await clearGuestCartViaBrowser(page);
          } catch (error) {
            cleanupFailures.push(`guest_cart:${error && (error.stack || error.message || error)}`);
          }
        }
        try {
          const cleanup = runFixture({
            action: 'cleanup',
            fixture,
            order_uuid: orderUuid,
            checkout_group_uuid: checkoutGroupUuid,
          }).data;
          expect(cleanup.remaining).toEqual(Object.fromEntries(
            Object.keys(cleanup.remaining).map(key => [key, 0]),
          ));
          expect(cleanup.payment_preimage).toMatchObject({
            system_config_business_exact: true,
            payment_method_exact: true,
            version_ids_monotonic: true,
            hash_exact: true,
          });
        } catch (error) {
          cleanupFailures.push(`fixture:${error && (error.stack || error.message || error)}`);
        }
        expect(cleanupFailures, `R4.3 cleanup failures:\n${cleanupFailures.join('\n')}`).toEqual([]);
      }
    },
  );
});
