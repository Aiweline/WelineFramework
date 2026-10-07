/**
 * WB-OP storefront matrix: CN/DE/US/JP (+ optional US collect / exempt).
 * Reads getData tax_estimate + painted money-summary lanes.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
// Prefer TAX_WBOP_PDP; ignore leftover CHECKOUT_PDP_PATH unless explicitly allowed
// (shell pollution previously pointed this matrix at the wrong product).
const DEFAULT_PDP = '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
const PDP = process.env.TAX_WBOP_PDP
  || (process.env.TAX_WBOP_ALLOW_CHECKOUT_PDP === '1' ? process.env.CHECKOUT_PDP_PATH : '')
  || DEFAULT_PDP;
const OUT = process.env.MATRIX_OUT
  || path.resolve(__dirname, '../../../../../../../var/log/tax-lanes-country-wbop.json');

// Generic seed: collect list empty → no destination sales tax; DDU duty/import by policy.
const CASES = JSON.parse(process.env.TAX_WBOP_CASES_JSON || 'null') || [
  {
    id: 'T1_CN',
    address: {
      name: '国内买家', phone: '13800138000', address1: '长安街1号', street: '长安街1号',
      city: '北京', province: '北京', postal_code: '100000', country_code: 'CN', country: 'CN', region_code: 'BJ',
    },
    expect: { sales: 0, duty: 0, import: 0 },
  },
  {
    id: 'T2_DE',
    address: {
      name: 'DE Buyer', phone: '4915123456789', address1: 'Unter den Linden 1', street: 'Unter den Linden 1',
      city: 'Berlin', province: 'BE', postal_code: '10117', country_code: 'DE', country: 'DE', region_code: 'BE',
    },
    expect: { sales: 0, duty_gt: 0, import_gt: 0 },
  },
  {
    id: 'T3_US',
    address: {
      name: 'US Buyer', phone: '14085551234', address1: '1 Main St', street: '1 Main St',
      city: 'San Jose', province: 'CA', postal_code: '95131', country_code: 'US', country: 'US', region_code: 'CA',
    },
    expect: { sales: 0, duty_gt: 0, import: 0 },
  },
  {
    id: 'T5_JP',
    address: {
      name: 'JP Buyer', phone: '819012345678', address1: '1-1 Chiyoda', street: '1-1 Chiyoda',
      city: 'Tokyo', province: 'Tokyo', postal_code: '100-0001', country_code: 'JP', country: 'JP', region_code: '13',
    },
    expect: { sales: 0, duty_gt: 0, import_gt: 0 },
  },
];

function pickShipping(codes, country) {
  if (country === 'CN') return codes.find((c) => /DOMESTIC/i.test(c)) || codes[0] || '';
  if (country === 'US') {
    return codes.find((c) => /AMERICAS/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || 'SEED_LANE_AMERICAS';
  }
  if (country === 'DE') {
    return codes.find((c) => /EU|EUROPE|DE|INTL/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || codes[0]
      || '';
  }
  return codes.find((c) => /ASIA|JP|INTL/i.test(c))
    || codes.find((c) => c && !/DOMESTIC/i.test(c))
    || codes[0]
    || '';
}

function judge(expect, got) {
  const fails = [];
  const sales = Number(got.sales_tax_amount_minor || 0);
  const duty = Number(got.duty_amount_minor || 0);
  const imp = Number(got.import_tax_amount_minor || 0);
  if (expect.sales !== undefined && sales !== expect.sales) fails.push(`sales=${sales} want ${expect.sales}`);
  if (expect.duty !== undefined && duty !== expect.duty) fails.push(`duty=${duty} want ${expect.duty}`);
  if (expect.import !== undefined && imp !== expect.import) fails.push(`import=${imp} want ${expect.import}`);
  if (expect.duty_gt !== undefined && !(duty > expect.duty_gt)) fails.push(`duty=${duty} want >${expect.duty_gt}`);
  if (expect.import_gt !== undefined && !(imp > expect.import_gt)) fails.push(`import=${imp} want >${expect.import_gt}`);
  if (expect.sales_gt !== undefined && !(sales > expect.sales_gt)) fails.push(`sales=${sales} want >${expect.sales_gt}`);
  return fails;
}

async function runCase(page, caseDef) {
  const row = {
    id: caseDef.id,
    ok: false,
    shipping_method: '',
    tax_estimate: null,
    paint: null,
    expect: caseDef.expect || {},
    fails: [],
    error: '',
  };
  await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  const added = await page.evaluate(async () => {
    // HARD: only the main PDP purchase CTA — never related/upsell buy-now nodes.
    const detail = document.querySelector('[data-testid="storefront-product-detail"]')
      || document.querySelector('.product-native-detail')
      || document.querySelector('main .product-detail, main [data-product-id]');
    const buyNow = (detail && (
      detail.querySelector('[data-action="buy-now"][data-product-id]')
      || detail.querySelector('[data-testid="product-buy-now"]')
    ))
      || document.querySelector('[data-testid="storefront-product-detail"] [data-action="buy-now"]');
    if (!buyNow) return { success: false, message: 'buy_now_missing' };
    const root = detail || (buyNow.closest && buyNow.closest('.product-native-detail')) || document;
    const productId = Number(buyNow.dataset.productId || 0) || 0;
    const offerUuid = String(buyNow.dataset.globalOfferUuid || '').trim();
    const selection = {};
    root.querySelectorAll('[data-variant-option].is-selected').forEach((option) => {
      const axisCode = String(option.dataset.variantAxis || '').trim();
      const axisValue = String(option.dataset.variantValue || '').trim();
      if (axisCode && axisValue) selection[axisCode] = axisValue;
    });
    // Always mint a fresh guest cart — never reuse a prior token/snapshot.
    const cartApi = await window.Weline.Api.resource('cart');
    const issued = await cartApi.issueGuestToken({}, { silent: true });
    const payload = issued && issued.data && typeof issued.data === 'object' ? issued.data : issued;
    const guestToken = String((payload && payload.guest_token) || (issued && issued.guest_token) || '').trim();
    if (guestToken) {
      try { sessionStorage.setItem('weline.cart.guest_token', guestToken); } catch (e2) {}
    }
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
    const topItems = (addResult && Array.isArray(addResult.items)) ? addResult.items : [];
    const dataItems = (addResult && addResult.data && Array.isArray(addResult.data.items)) ? addResult.data.items : [];
    const addItems = topItems.length ? topItems : dataItems;
    return {
      success: !(addResult && addResult.success === false) && guestToken !== '',
      guest_token: guestToken,
      page_href: location.href,
      page_title: document.title,
      product_id: productId,
      offer_uuid: offerUuid,
      selection,
      top_tax: topItems.map((i) => String((i && i.tax_class_code) || '')),
      data_tax: dataItems.map((i) => String((i && i.tax_class_code) || '')),
      add_tax_classes: addItems.map((i) => String((i && i.tax_class_code) || '')),
      scope_key: String((addResult && addResult.scope_key) || (addResult && addResult.data && addResult.data.scope_key) || ''),
    };
  });
  if (!added || !added.success) {
    row.error = 'cart_add_failed:' + JSON.stringify(added).slice(0, 300);
    return row;
  }
  row.add_tax_classes = added.add_tax_classes || [];
  row.add_meta = {
    page_href: added.page_href || '',
    page_title: added.page_title || '',
    product_id: added.product_id || 0,
    offer_uuid: added.offer_uuid || '',
    selection: added.selection || {},
    top_tax: added.top_tax || [],
    data_tax: added.data_tax || [],
    scope_key: added.scope_key || '',
  };

  await page.goto(FRONTEND + '/checkout', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  await page.waitForTimeout(1000);

  const probed = await page.evaluate(async ({ address }) => {
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    const api = await window.Weline.Api.resource('checkout');
    const country = String(address.country_code || address.country || '').toUpperCase();
    // Align delivery-context country with the case address (anonymous default is often CN).
    // Frontend worker schema only accepts country_code (see widget-header-checkout-delivery-context).
    let setDeliveryErr = '';
    try {
      if (country) {
        await api.setDeliveryCountry({ country_code: country }, { silent: true, requestTimeoutMs: 60000 });
      }
    } catch (eSet) {
      setDeliveryErr = String((eSet && eSet.message) || eSet || '');
    }
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
    const te = root && root.tax_estimate && typeof root.tax_estimate === 'object' ? root.tax_estimate : {};
    const items = Array.isArray(root.items) ? root.items
      : (root.cart && Array.isArray(root.cart.items) ? root.cart.items : []);
    const diag = {
      tax_mode: String(te.mode || root.tax_mode || ''),
      tax_note: String(te.note || root.tax_note || ''),
      destination_tax_profile: String(te.destination_tax_profile || root.destination_tax_profile || ''),
      destination_policy: te.destination_policy || root.destination_policy || null,
      collect_hint: te.collect_sales_tax_countries || null,
      sales_tax_amount_minor: Number(te.sales_tax_amount_minor ?? root.sales_tax_amount_minor ?? 0),
      cart_sales: root.cart && typeof root.cart === 'object'
        ? Number(root.cart.sales_tax_amount_minor ?? root.cart.tax_amount_minor ?? 0)
        : null,
      item_tax_classes: items.map((i) => String((i && i.tax_class_code) || '')),
      set_delivery_err: setDeliveryErr,
      delivery: root.delivery || null,
      identity: root.identity || null,
      quote_token_present: !!(root.quote_token),
      root_keys: root && typeof root === 'object' ? Object.keys(root).slice(0, 40) : [],
    };
    // Prefer selected method duty fields when present.
    let selected = null;
    let shippingMethod = '';
    if (country === 'CN') shippingMethod = codes.find((c) => /DOMESTIC/i.test(c)) || codes[0] || '';
    else if (country === 'US') {
      shippingMethod = codes.find((c) => /AMERICAS/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || 'SEED_LANE_AMERICAS';
    } else if (country === 'DE') {
      shippingMethod = codes.find((c) => /EU|EUROPE|DE|INTL/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || codes[0] || '';
    } else {
      shippingMethod = codes.find((c) => /ASIA|JP|INTL/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || codes[0] || '';
    }
    selected = methods.find((m) => String((m && (m.code || m.service_code)) || '') === shippingMethod) || null;

    // Trigger UI refresh: select shipping radio if present.
    const radio = document.querySelector(`input[name="shipping_method"][value="${shippingMethod}"]`);
    if (radio) {
      radio.checked = true;
      radio.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Paint helpers if exposed.
    try {
      if (window.WelineCheckout && typeof window.WelineCheckout.refreshMoneySummary === 'function') {
        await window.WelineCheckout.refreshMoneySummary();
      }
    } catch (e4) {}

    const summary = document.querySelector('[data-widget="storefront-money-summary"], .storefront-money-summary, [data-storefront-money-summary]');
    const paint = {
      sales_text: '',
      duty_text: '',
      import_text: '',
      tax_text: '',
      sales_minor: null,
      duty_minor: null,
      import_minor: null,
    };
    if (summary) {
      const salesEl = summary.querySelector('[data-money-sales-tax], [data-lane="sales_tax"], .storefront-money-summary__sales-tax');
      const dutyEl = summary.querySelector('[data-money-customs-duty], [data-lane="customs_duty"], .storefront-money-summary__customs-duty');
      const importEl = summary.querySelector('[data-money-import-tax], [data-lane="import_tax"], .storefront-money-summary__import-tax');
      const taxEl = summary.querySelector('[data-money-tax], [data-lane="tax"], .storefront-money-summary__tax');
      paint.sales_text = salesEl ? String(salesEl.textContent || '').trim() : '';
      paint.duty_text = dutyEl ? String(dutyEl.textContent || '').trim() : '';
      paint.import_text = importEl ? String(importEl.textContent || '').trim() : '';
      paint.tax_text = taxEl ? String(taxEl.textContent || '').trim() : '';
      paint.sales_minor = salesEl && salesEl.dataset ? Number(salesEl.dataset.minor || salesEl.dataset.amountMinor || NaN) : null;
      paint.duty_minor = dutyEl && dutyEl.dataset ? Number(dutyEl.dataset.minor || dutyEl.dataset.amountMinor || NaN) : null;
      paint.import_minor = importEl && importEl.dataset ? Number(importEl.dataset.minor || importEl.dataset.amountMinor || NaN) : null;
    }

    return {
      shippingMethod,
      codes,
      diag,
      tax_estimate: {
        sales_tax_amount_minor: Number(te.sales_tax_amount_minor ?? diag.sales_tax_amount_minor ?? 0),
        duty_amount_minor: Number(
          te.duty_amount_minor
          ?? (selected && selected.duty_amount_minor)
          ?? 0,
        ),
        import_tax_amount_minor: Number(
          te.import_tax_amount_minor
          ?? (selected && selected.import_tax_amount_minor)
          ?? 0,
        ),
        tax_amount_minor: Number(te.tax_amount_minor ?? 0),
        reason: String(te.reason || te.duty_estimate_reason || ''),
        duty_notice: String(te.duty_notice || (selected && selected.duty_notice) || ''),
      },
      selected_method: selected ? {
        code: String(selected.code || selected.service_code || ''),
        duty_amount_minor: Number(selected.duty_amount_minor || 0),
        import_tax_amount_minor: Number(selected.import_tax_amount_minor || 0),
        tax_amount_minor: Number(selected.tax_amount_minor || 0),
        duty_notice: String(selected.duty_notice || ''),
      } : null,
      paint,
    };
  }, { address: caseDef.address });

  row.shipping_method = String(probed.shippingMethod || '');
  // Prefer method-enriched lanes when tax_estimate aggregate is coarse.
  const te = probed.tax_estimate || {};
  const sel = probed.selected_method || {};
  row.diag = probed.diag || null;
  row.tax_estimate = {
    sales_tax_amount_minor: Number(te.sales_tax_amount_minor || 0),
    duty_amount_minor: Number(te.duty_amount_minor || sel.duty_amount_minor || 0),
    import_tax_amount_minor: Number(te.import_tax_amount_minor || sel.import_tax_amount_minor || 0),
    tax_amount_minor: Number(te.tax_amount_minor || sel.tax_amount_minor || 0),
    reason: String(te.reason || ''),
    duty_notice: String(te.duty_notice || sel.duty_notice || ''),
  };
  row.paint = probed.paint;
  row.fails = judge(caseDef.expect || {}, row.tax_estimate);
  row.ok = row.fails.length === 0 && row.shipping_method !== '';
  if (!row.shipping_method) row.error = 'no_shipping';
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
  const matrix = {
    ok: false,
    host: FRONTEND,
    at: new Date().toISOString(),
    label: process.env.TAX_WBOP_LABEL || 'default',
    cases: [],
  };
  try {
    for (const caseDef of CASES) {
      await context.clearCookies();
      await page.goto(FRONTEND + '/', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.evaluate(() => {
        try { sessionStorage.clear(); localStorage.clear(); } catch (e) {}
      }).catch(() => {});
      const row = await runCase(page, caseDef);
      matrix.cases.push(row);
      console.log(JSON.stringify({
        id: row.id,
        ok: row.ok,
        ship: row.shipping_method,
        tax: row.tax_estimate,
        add_tax_classes: row.add_tax_classes || null,
        add_meta: row.add_meta || null,
        diag: row.diag || null,
        fails: row.fails,
        err: row.error,
      }));
    }
    matrix.ok = matrix.cases.length > 0 && matrix.cases.every((c) => c.ok);
  } catch (e) {
    matrix.error = String(e && e.message ? e.message : e);
  } finally {
    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    fs.writeFileSync(OUT, JSON.stringify(matrix, null, 2));
    await browser.close();
  }
  console.log('WROTE ' + OUT + ' ok=' + matrix.ok);
  if (!matrix.ok) process.exitCode = 2;
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
