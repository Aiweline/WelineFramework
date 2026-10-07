/**
 * Multi-country order tax matrix: buy-now → getData preview → freeze → submitV2(fake_card).
 * Compares tax_estimate vs freeze tax vs (caller DB inspect of) created orders.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.CHECKOUT_PDP_PATH
  || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
const OUT = process.env.MATRIX_OUT
  || path.resolve(__dirname, '../../../../../../../var/log/order-tax-country-matrix.json');

const CASES = [
  {
    id: 'CN',
    address: {
      name: '国内买家',
      phone: '13800138000',
      address1: '长安街1号',
      street: '长安街1号',
      city: '北京',
      province: '北京',
      postal_code: '100000',
      country_code: 'CN',
      country: 'CN',
      region_code: 'BJ',
    },
    pickShipping: (codes) => codes.find((c) => /DOMESTIC/i.test(c)) || codes[0] || '',
  },
  {
    id: 'DE',
    address: {
      name: 'DE Buyer',
      phone: '4915123456789',
      address1: 'Unter den Linden 1',
      street: 'Unter den Linden 1',
      city: 'Berlin',
      province: 'BE',
      postal_code: '10117',
      country_code: 'DE',
      country: 'DE',
      region_code: 'BE',
    },
    pickShipping: (codes) => codes.find((c) => /EU|EUROPE|INTL/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || codes[0]
      || '',
  },
  {
    id: 'US',
    address: {
      name: 'US Buyer',
      phone: '14085551234',
      address1: '1 Main St',
      street: '1 Main St',
      city: 'San Jose',
      province: 'CA',
      postal_code: '95131',
      country_code: 'US',
      country: 'US',
      region_code: 'CA',
    },
    pickShipping: (codes) => codes.find((c) => /AMERICAS/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || 'SEED_LANE_AMERICAS',
  },
  {
    id: 'JP',
    address: {
      name: 'JP Buyer',
      phone: '819012345678',
      address1: '1-1 Chiyoda',
      street: '1-1 Chiyoda',
      city: 'Tokyo',
      province: 'Tokyo',
      postal_code: '100-0001',
      country_code: 'JP',
      country: 'JP',
      region_code: '13',
    },
    pickShipping: (codes) => codes.find((c) => /ASIA|JP|INTL/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || codes[0]
      || '',
  },
];

function summarizeTax(obj) {
  if (!obj || typeof obj !== 'object') return null;
  return {
    tax_amount_minor: Number(obj.tax_amount_minor ?? 0),
    sales_tax_amount_minor: Number(obj.sales_tax_amount_minor ?? 0),
    duty_amount_minor: Number(obj.duty_amount_minor ?? 0),
    import_tax_amount_minor: Number(obj.import_tax_amount_minor ?? 0),
    duty_charged_minor: Number(obj.duty_charged_minor ?? obj.charged_minor ?? 0),
    mode: String(obj.mode || ''),
    note: String(obj.note || ''),
    duty_notice: String(obj.duty_notice || ''),
    duty_estimate_reason: String(obj.duty_estimate_reason || obj.reason || ''),
    destination_tax_profile: String(obj.destination_tax_profile || (obj.destination_policy && obj.destination_policy.profile) || ''),
  };
}

async function placeOne(page, caseDef) {
  const row = {
    id: caseDef.id,
    ok: false,
    shipping_method: '',
    preview_tax: null,
    freeze_tax: null,
    freeze_totals_tax_minor: null,
    transaction_no: '',
    order_uuid: '',
    redirect_url: '',
    error: '',
  };

  await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });

  const added = await page.evaluate(async () => {
    const buyNow = document.querySelector('[data-action="buy-now"][data-product-id]')
      || document.querySelector('[data-testid="product-buy-now"]');
    if (!buyNow) return { success: false, message: 'buy_now_missing' };
    const root = (buyNow.closest && buyNow.closest('.product-native-detail')) || document;
    const productId = Number(buyNow.dataset.productId || 0) || 0;
    const offerUuid = String(buyNow.dataset.globalOfferUuid || '').trim();
    const selection = {};
    root.querySelectorAll('[data-variant-option].is-selected').forEach((option) => {
      const axisCode = String(option.dataset.variantAxis || '').trim();
      const axisValue = String(option.dataset.variantValue || '').trim();
      if (axisCode && axisValue) selection[axisCode] = axisValue;
    });
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    const cartApi = await window.Weline.Api.resource('cart');
    if (!guestToken) {
      const issued = await cartApi.issueGuestToken({}, { silent: true });
      const payload = issued && issued.data && typeof issued.data === 'object' ? issued.data : issued;
      guestToken = String((payload && payload.guest_token) || (issued && issued.guest_token) || '').trim();
      if (guestToken) {
        try { sessionStorage.setItem('weline.cart.guest_token', guestToken); } catch (e2) {}
      }
    }
    // Fresh cart per country: clear then add.
    try {
      await cartApi.clear({ guest_token: guestToken, cart_type: 'toc', selling_mode: 'toc' }, { silent: true });
    } catch (e3) {}
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
    return { success: !(addResult && addResult.success === false), guest_token: guestToken, add: addResult };
  });
  if (!added || !added.success) {
    row.error = 'cart_add_failed:' + JSON.stringify(added).slice(0, 400);
    return row;
  }

  await page.goto(FRONTEND + '/checkout', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  await page.waitForTimeout(1200);

  const started = await page.evaluate(async ({ address, pickHint }) => {
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
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
    let shippingMethod = '';
    if (pickHint === 'CN') {
      shippingMethod = codes.find((c) => /DOMESTIC/i.test(c)) || codes[0] || '';
    } else if (pickHint === 'US') {
      shippingMethod = codes.find((c) => /AMERICAS/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || 'SEED_LANE_AMERICAS';
    } else if (pickHint === 'DE') {
      shippingMethod = codes.find((c) => /EU|EUROPE|INTL/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || codes[0]
        || '';
    } else {
      shippingMethod = codes.find((c) => /ASIA|JP|INTL/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || codes[0]
        || '';
    }
    if (!shippingMethod) {
      return { success: false, message: 'no_shipping', codes, preview: root && root.tax_estimate };
    }
    const previewTax = root && (root.tax_estimate || root.tax) ? (root.tax_estimate || root.tax) : null;
    const selectedMethod = methods.find((m) => String((m && (m.code || m.service_code)) || '') === shippingMethod) || null;
    const frozen = await api.freezeQuote({
      address,
      billing_address: address,
      billing_same_as_shipping: true,
      service_code: shippingMethod,
      payment_method: 'fake_card',
      guest_token: guestToken,
      cart_type: 'toc',
      selling_mode: 'toc',
    }, { silent: true, requestTimeoutMs: 120000 });
    if (!frozen || frozen.success === false) {
      return {
        success: false,
        message: 'freeze_failed',
        frozen,
        codes,
        shippingMethod,
        preview_tax: previewTax,
        selected_shipping: selectedMethod,
      };
    }
    const payload = (frozen.data && typeof frozen.data === 'object') ? frozen.data : frozen;
    const quoteToken = String((frozen.quote_token || (payload && payload.quote_token) || '')).trim();
    const freezeTax = payload && payload.tax ? payload.tax : null;
    const freezeTotalsTax = payload && payload.totals ? Number(payload.totals.tax_amount_minor || 0) : null;
    const result = await api.submitV2({
      quote_token: quoteToken,
      payment_method: 'fake_card',
      idempotency_key: 'tax_matrix_' + pickHint + '_' + Date.now(),
      country_code: address.country_code,
      guest_token: guestToken,
    }, { silent: true, requestTimeoutMs: 120000 });
    return {
      success: !(result && result.success === false),
      result,
      shippingMethod,
      codes,
      preview_tax: previewTax,
      freeze_tax: freezeTax,
      freeze_totals_tax_minor: freezeTotalsTax,
      selected_shipping: selectedMethod,
      quote_token: quoteToken,
    };
  }, { address: caseDef.address, pickHint: caseDef.id });

  if (!started || started.success === false) {
    row.error = 'submit_failed:' + JSON.stringify(started).slice(0, 900);
    row.preview_tax = summarizeTax(started && started.preview_tax);
    row.freeze_tax = summarizeTax(started && started.freeze_tax);
    row.shipping_method = String((started && started.shippingMethod) || '');
    return row;
  }

  row.preview_tax = summarizeTax(started.preview_tax);
  row.freeze_tax = summarizeTax(started.freeze_tax);
  row.freeze_totals_tax_minor = started.freeze_totals_tax_minor;
  row.shipping_method = String(started.shippingMethod || '');

  const result = started.result || {};
  const payment = (result.payment && typeof result.payment === 'object')
    ? result.payment
    : ((result.data && result.data.payment) || {});
  const txnFromList = Array.isArray(payment.transactions) && payment.transactions[0]
    ? String(payment.transactions[0].transaction_no || '')
    : '';
  row.transaction_no = String(result.transaction_no || txnFromList || '').trim();
  row.order_uuid = String(
    result.order_uuid
    || (result.data && result.data.order_uuid)
    || (Array.isArray(result.orders) && result.orders[0] && result.orders[0].order_uuid)
    || ''
  ).trim();
  row.redirect_url = String(
    payment.redirect_url || result.redirect_url || (result.data && result.data.redirect_url) || ''
  ).trim();
  row.ok = true;
  row.raw_keys = Object.keys(result || {});
  return row;
}

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
    args: ['--disable-popup-blocking'],
  });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const matrix = { ok: false, host: FRONTEND, at: new Date().toISOString(), cases: [] };

  try {
    for (const caseDef of CASES) {
      // Fresh context storage per country to avoid sticky CN address/cart.
      await context.clearCookies();
      await page.goto(FRONTEND + '/', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.evaluate(() => {
        try { sessionStorage.clear(); localStorage.clear(); } catch (e) {}
      }).catch(() => {});
      const row = await placeOne(page, caseDef);
      matrix.cases.push(row);
      console.log(JSON.stringify({ id: row.id, ok: row.ok, shipping: row.shipping_method, preview: row.preview_tax, freeze: row.freeze_tax, txn: row.transaction_no, err: row.error.slice(0, 200) }));
    }
    matrix.ok = matrix.cases.every((c) => c.ok);
  } catch (e) {
    matrix.error = String(e && e.message ? e.message : e);
  } finally {
    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    fs.writeFileSync(OUT, JSON.stringify(matrix, null, 2));
    await browser.close();
  }
  console.log('WROTE ' + OUT);
  if (!matrix.ok) process.exitCode = 2;
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
