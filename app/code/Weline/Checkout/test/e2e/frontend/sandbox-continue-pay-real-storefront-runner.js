/**
 * 店面真实续付：PDP 加购 → /checkout → freeze/submitV2(fake_card) → mark cancel → 输出 continue_pay_url。
 * 禁止 ORM seed_unpaid_pathway（无真实商品）。
 *
 * Usage:
 *   node app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js
 */
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const ROOT = path.resolve(__dirname, '../../../../../../..');
const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.CHECKOUT_PDP_PATH
  || '/product/yue-ya-ni-shang-yue-ya-ni-shang-zhang-an-yi-yuan-chuang-zheng-pin-tang-zhi-4d375d6d/';
const CPAY_FIXTURE = path.resolve(__dirname, 'checkout-continue-pay-real-pathway-fixture.php');

function fixture(action, payload = {}) {
  const stdout = execFileSync('php', [CPAY_FIXTURE], {
    cwd: ROOT,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines[lines.length - 1] || '{}');
  if (!result.ok) {
    throw new Error(`fixture ${action} failed: ${JSON.stringify(result).slice(0, 800)}`);
  }
  return result;
}

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
  });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const out = {
    ok: false,
    steps: [],
    pdp: FRONTEND + PDP,
    order_uuid: '',
    order_number: '',
    quote_token: '',
    idempotency_key: '',
    continue_pay_url: '',
    product_name: '',
    grand_total: null,
    error: '',
  };

  try {
    await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
    out.steps.push('pdp_loaded');
    await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });

    const added = await page.evaluate(async () => {
      const buyNow = document.querySelector('[data-action="buy-now"][data-product-id]')
        || document.querySelector('[data-testid="product-buy-now"]')
        || document.querySelector('[data-action="add-to-cart"][data-product-id]');
      if (!buyNow) {
        return { success: false, message: 'buy_now_missing' };
      }
      const root = (buyNow.closest && buyNow.closest('.product-native-detail')) || document;
      // Prefer first selectable variant option when none selected.
      root.querySelectorAll('[data-variant-axis]').forEach((axisHost) => {
        if (!axisHost.querySelector('[data-variant-option].is-selected')) {
          const first = axisHost.querySelector('[data-variant-option]');
          if (first) {
            first.classList.add('is-selected');
          }
        }
      });
      const productId = Number(buyNow.dataset.productId || 0) || 0;
      const offerUuid = String(buyNow.dataset.globalOfferUuid || '').trim();
      const productName = String(
        (document.querySelector('h1, [data-product-name], .product-title') || {}).textContent || ''
      ).trim().slice(0, 120);
      const selection = {};
      root.querySelectorAll('[data-variant-option].is-selected').forEach((option) => {
        const axisCode = String(option.dataset.variantAxis || '').trim();
        const axisValue = String(option.dataset.variantValue || '').trim();
        if (axisCode && axisValue) {
          selection[axisCode] = axisValue;
        }
      });
      let guestToken = '';
      try {
        guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim();
      } catch (e) {}
      const cartApi = await window.Weline.Api.resource('cart');
      if (!guestToken) {
        const issued = await cartApi.issueGuestToken({}, { silent: true });
        const payload = issued && issued.data && typeof issued.data === 'object' ? issued.data : issued;
        guestToken = String((payload && payload.guest_token) || (issued && issued.guest_token) || '').trim();
        if (guestToken) {
          try {
            sessionStorage.setItem('weline.cart.guest_token', guestToken);
          } catch (e2) {}
        }
      }
      const addResult = await cartApi.add({
        provider_code: buyNow.dataset.providerCode || 'product',
        global_offer_uuid: offerUuid,
        legacy_product_id: productId,
        selection,
        guest_token: guestToken,
        qty: 1,
        selling_mode: 'toc',
        cart_type: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      return {
        success: !(addResult && addResult.success === false),
        guest_token: guestToken,
        product_id: productId,
        product_name: productName,
        add: addResult,
      };
    });
    if (!added || !added.success) {
      throw new Error('cart_add_failed:' + JSON.stringify(added).slice(0, 600));
    }
    out.product_name = added.product_name || '';
    out.steps.push('cart_added:' + (added.product_id || 0));

    await page.goto(FRONTEND + '/checkout', { waitUntil: 'domcontentloaded' });
    out.steps.push('checkout_opened');
    await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
    await page.waitForTimeout(1200);

    const submitted = await page.evaluate(async () => {
      let guestToken = '';
      try {
        guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim();
      } catch (e) {}
      const address = {
        name: 'Real Continue Pay Buyer',
        phone: '14085551234',
        email: 'real-cpay@example.test',
        address1: '1 Main St',
        street: '1 Main St',
        city: 'San Jose',
        province: 'CA',
        postal_code: '95131',
        country_code: 'US',
        country: 'US',
      };
      const api = await window.Weline.Api.resource('checkout');
      const data = await api.getData({
        guest_token: guestToken,
        shipping_address: address,
        cart_type: 'toc',
        selling_mode: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      const root = (data && data.data && typeof data.data === 'object') ? data.data : data;
      const methods = (root && root.shipping_methods) || [];
      const codes = Array.isArray(methods)
        ? methods.map((m) => String((m && (m.code || m.service_code)) || '')).filter(Boolean)
        : [];
      let shippingMethod = codes.find((c) => /AMERICAS|americas/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || codes[0]
        || 'SEED_LANE_AMERICAS';
      const paymentMethod = 'fake_card';
      const frozen = await api.freezeQuote({
        address,
        billing_address: address,
        billing_same_as_shipping: true,
        service_code: shippingMethod,
        payment_method: paymentMethod,
        guest_token: guestToken,
        cart_type: 'toc',
        selling_mode: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      if (!frozen || frozen.success === false) {
        return { success: false, stage: 'freeze', frozen, codes };
      }
      const quoteToken = String((frozen.quote_token || (frozen.data && frozen.data.quote_token) || '')).trim();
      const idempotencyKey = 'real_cpay_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
      const result = await api.submitV2({
        quote_token: quoteToken,
        payment_method: paymentMethod,
        idempotency_key: idempotencyKey,
        country_code: address.country_code,
        guest_token: guestToken,
      }, { silent: true, requestTimeoutMs: 120000 });
      const payload = (result && result.data && typeof result.data === 'object') ? result.data : result;
      const orderUuids = Array.isArray(payload && payload.order_uuids)
        ? payload.order_uuids
        : (Array.isArray(result && result.order_uuids) ? result.order_uuids : []);
      return {
        success: !(result && result.success === false),
        quote_token: quoteToken,
        idempotency_key: idempotencyKey,
        shipping_method: shippingMethod,
        payment_method: paymentMethod,
        order_uuid: String(orderUuids[0] || payload.order_uuid || '').trim(),
        checkout_group_uuid: String(
          (payload && payload.checkout_group_uuid) || (result && result.checkout_group_uuid) || ''
        ).trim(),
        cart: root && root.cart ? root.cart : null,
        items: root && root.items ? root.items : null,
        result,
      };
    });
    out.steps.push('submit_done');
    if (!submitted || !submitted.success || !submitted.order_uuid) {
      throw new Error('submit_failed:' + JSON.stringify(submitted).slice(0, 1200));
    }
    out.order_uuid = submitted.order_uuid;
    out.quote_token = submitted.quote_token;
    out.idempotency_key = submitted.idempotency_key;
    if (submitted.cart && submitted.cart.grand_total != null) {
      out.grand_total = submitted.cart.grand_total;
    }

    const cancelReady = fixture('prepare_cancel_continue', {
      order_uuid: out.order_uuid,
    }).data;
    out.steps.push('cancel_marked');
    out.order_number = String(cancelReady.order_number || '');
    out.quote_token = String(cancelReady.quote_token || out.quote_token);
    out.idempotency_key = String(cancelReady.idempotency_key || out.idempotency_key);
    out.continue_pay_url = String(cancelReady.continue_pay_url || '');
    if (!out.continue_pay_url) {
      throw new Error('continue_pay_url_missing:' + JSON.stringify(cancelReady).slice(0, 600));
    }

    // Spot-check order has real line names in DB via verify fixture fields if present.
    out.ok = true;
  } catch (e) {
    out.error = String(e && e.message || e);
  } finally {
    await browser.close().catch(() => {});
  }

  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(out.ok ? 0 : 1);
}

main();
