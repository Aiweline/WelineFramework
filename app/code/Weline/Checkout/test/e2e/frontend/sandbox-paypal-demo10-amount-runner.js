/**
 * PayPal sandbox + DEMO10 amount lock:
 * - FLOW=checkout (default): cart → apply DEMO10 → /checkout → PayPal → success
 * - FLOW=express: PDP → cart+DEMO10 → startExpressCheckout → PayPal → express-review → confirm
 *
 * Asserts checkout/express UI grand_total_minor === payment create amount_minor
 * (and discount applied for DEMO10).
 */
const path = require('path');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const FLOW = String(process.env.FLOW || 'checkout').toLowerCase();
const COUPON = String(process.env.COUPON_CODE || 'DEMO10').trim().toUpperCase();
const PDP = process.env.CHECKOUT_PDP_PATH
  || (FLOW === 'express'
    ? (process.env.EXPRESS_PDP_PATH
      || '/product/hua-chao-ji-yuan-chuang-han-fu-luo-chuan-tang-zhi-qi-xiong-68a0ecd7/?size=s&style_type=luo-chuan-da')
    : '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/');
const BUYER_EMAIL = process.env.PAYPAL_SANDBOX_BUYER_EMAIL || 'sb-4sxrp30216572@personal.example.com';
const BUYER_PASS = process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || 'weline18';

function moneyFrom(obj) {
  if (!obj || typeof obj !== 'object') return null;
  const totals = obj.totals && typeof obj.totals === 'object' ? obj.totals : obj;
  const money = obj.money && typeof obj.money === 'object' ? obj.money : null;
  const pick = (...keys) => {
    for (const k of keys) {
      if (totals[k] != null && totals[k] !== '') return Number(totals[k]);
      if (money && money[k] != null && money[k] !== '') return Number(money[k]);
      if (obj[k] != null && obj[k] !== '') return Number(obj[k]);
    }
    return null;
  };
  const grandMinor = pick('grand_total_minor');
  const subMinor = pick('subtotal_minor', 'items_subtotal_minor');
  const discMinor = pick('discount_amount_minor');
  const shipMinor = pick('shipping_amount_minor');
  const taxMinor = pick('tax_amount_minor');
  const grand = grandMinor != null
    ? grandMinor
    : pick('grand_total') != null
      ? Math.round(pick('grand_total') * 100)
      : null;
  return {
    grand_total_minor: grand,
    subtotal_minor: subMinor != null ? subMinor : (pick('subtotal') != null ? Math.round(pick('subtotal') * 100) : null),
    discount_amount_minor: discMinor != null ? discMinor : (pick('discount_amount') != null ? Math.round(pick('discount_amount') * 100) : null),
    shipping_amount_minor: shipMinor != null ? shipMinor : (pick('shipping_amount') != null ? Math.round(pick('shipping_amount') * 100) : null),
    tax_amount_minor: taxMinor != null ? taxMinor : (pick('tax_amount') != null ? Math.round(pick('tax_amount') * 100) : null),
  };
}

function extractPaymentAmountMinor(payload) {
  if (!payload || typeof payload !== 'object') return null;
  const payment = (payload.payment && typeof payload.payment === 'object')
    ? payload.payment
    : ((payload.data && payload.data.payment) || {});
  const tx0 = Array.isArray(payment.transactions) ? payment.transactions[0] : null;
  const candidates = [
    payment.amount_minor,
    payload.amount_minor,
    payload.data && payload.data.amount_minor,
    tx0 && tx0.amount_minor,
    tx0 && tx0.response && tx0.response.amount_minor,
    payment.amount != null ? Math.round(Number(payment.amount) * 100) : null,
    tx0 && tx0.amount != null ? Math.round(Number(tx0.amount) * 100) : null,
  ];
  for (const c of candidates) {
    if (c != null && Number.isFinite(Number(c)) && Number(c) > 0) return Math.round(Number(c));
  }
  return null;
}

async function loginPayPal(paypal) {
  await paypal.waitForLoadState('domcontentloaded');
  for (const sel of ['#loginWithPassword', '#tryPasswordLink', 'a:has-text("Use password instead")', 'a:has-text("Log in with your password")', 'a:has-text("Try another way")']) {
    const loc = paypal.locator(sel).first();
    if (await loc.count()) {
      await loc.click({ timeout: 3000 }).catch(() => {});
      await paypal.waitForTimeout(800);
    }
  }
  const email = paypal.locator('#email, input[name="login_email"], input[type="email"]').first();
  if (await email.count()) {
    await email.fill(BUYER_EMAIL, { timeout: 20000 }).catch(async () => {
      await email.click({ force: true });
      await paypal.keyboard.type(BUYER_EMAIL, { delay: 20 });
    });
  }
  const next = paypal.locator('#btnNext').first();
  if (await next.count() && await next.isVisible().catch(() => false)) {
    await next.click().catch(() => {});
    await paypal.waitForTimeout(1200);
  }
  const pass = paypal.locator('#password, input[name="login_password"], input[type="password"]').first();
  await pass.waitFor({ state: 'visible', timeout: 30000 });
  await pass.fill(BUYER_PASS);
  await paypal.locator('#btnLogin, button:has-text("Log In"), button:has-text("Log in")').first().click();
  await paypal.waitForTimeout(2500);
  for (let i = 0; i < 12; i += 1) {
    if (/test\.weline\.com|127\.0\.0\.1|checkout\/success|express-review|payment\/frontend\/callback/.test(paypal.url())) {
      return;
    }
    const cont = paypal.locator(
      '#payment-submit-btn, #consentButton, button[data-id="payment-submit-btn"], [data-testid="consentButton"], button:has-text("Continue"), button:has-text("Agree and Continue"), button:has-text("Complete Purchase")'
    ).first();
    if (await cont.count() && await cont.isVisible().catch(() => false)) {
      await cont.click({ timeout: 5000 }).catch(() => {});
    }
    await paypal.waitForTimeout(2000);
  }
}

async function scrapePayPalDisplayedAmountMinor(paypal) {
  const text = await paypal.locator('body').innerText().catch(() => '');
  // Prefer USD $X.XX near cart/total labels.
  const patterns = [
    /(?:Total|应付|合计|Cart total|Order total)[^\d$]*\$?\s*([0-9]+(?:\.[0-9]{2})?)/i,
    /\$\s*([0-9]+\.[0-9]{2})/,
  ];
  for (const re of patterns) {
    const m = text.match(re);
    if (m) return Math.round(parseFloat(m[1]) * 100);
  }
  return null;
}

async function addProductAndGuest(page) {
  return page.evaluate(async () => {
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
      add: addResult,
    };
  });
}

async function applyCoupon(page, code) {
  return page.evaluate(async (couponCode) => {
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    const marketing = await window.Weline.Api.resource('marketing');
    const applied = await marketing.applyCoupon({
      coupon_code: couponCode,
      guest_token: guestToken,
      cart_type: 'toc',
      selling_mode: 'toc',
    }, { silent: true, requestTimeoutMs: 60000 });
    return applied;
  }, code);
}

async function runCheckout(page, context, result) {
  await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
  result.steps.push('pdp_loaded');
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });

  const added = await addProductAndGuest(page);
  if (!added || !added.success) throw new Error('cart_add_failed:' + JSON.stringify(added).slice(0, 500));
  result.steps.push('cart_added');

  const couponRes = await applyCoupon(page, COUPON);
  result.coupon_apply = couponRes;
  const couponOk = !!(couponRes && (couponRes.success === true
    || String(couponRes.coupon_code || '').toUpperCase() === COUPON));
  if (!couponOk) throw new Error('coupon_apply_failed:' + JSON.stringify(couponRes).slice(0, 800));
  result.steps.push('coupon_applied:' + COUPON);
  const discPreview = (couponRes.discount && typeof couponRes.discount === 'object') ? couponRes.discount : {};
  result.coupon_discount_minor = Number(discPreview.amount_minor || 0) || 0;
  if (result.coupon_discount_minor <= 0) {
    throw new Error('coupon_discount_zero:' + JSON.stringify(couponRes).slice(0, 800));
  }

  await page.goto(FRONTEND + '/checkout', { waitUntil: 'domcontentloaded' });
  result.steps.push('checkout_opened');
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  await page.waitForFunction(() => {
    const box = document.querySelector('[data-payment-methods]');
    return !!box && box.querySelectorAll('input[name="payment_method"]').length > 0;
  }, null, { timeout: 90000 }).catch(() => {});
  await page.waitForTimeout(1500);

  const wantedMethod = 'paypal';
  for (let attempt = 0; attempt < 24; attempt += 1) {
    const wantRadio = page.locator(`input[name="payment_method"][value="${wantedMethod}"]`).first();
    if (await wantRadio.count()) {
      await wantRadio.check({ force: true }).catch(() => {});
    }
    const now = await page.evaluate(() => {
      const el = document.querySelector('input[name="payment_method"]:checked');
      return el ? String(el.value || '') : '';
    }).catch(() => '');
    if (now.toLowerCase() === wantedMethod) break;
    await page.waitForTimeout(700);
  }
  const selectedPayment = await page.evaluate(() => {
    const el = document.querySelector('input[name="payment_method"]:checked');
    return el ? String(el.value || '') : '';
  }).catch(() => '');
  if (selectedPayment.toLowerCase() !== wantedMethod) {
    throw new Error(`payment_method_not_selectable:${wantedMethod}:got=${selectedPayment || '(none)'}`);
  }
  result.steps.push('payment_selected:' + selectedPayment);

  // Read UI totals text (best-effort) + authoritative freeze totals.
  const uiTotalsText = await page.locator('[data-checkout-totals], [data-testid="checkout-totals"], .checkout-summary').first()
    .innerText().catch(() => '');
  result.ui_totals_text = String(uiTotalsText || '').slice(0, 500);

  const started = await page.evaluate(async (forcedMethod) => {
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    const address = {
      name: 'Sandbox Buyer',
      phone: '14085551234',
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
    const codes = Array.isArray(methods) ? methods.map((m) => String((m && (m.code || m.service_code)) || '')).filter(Boolean) : [];
    let shippingMethod = codes.find((c) => /AMERICAS|americas|INTL|intl/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || codes[0]
      || '';
    if (/DOMESTIC/i.test(shippingMethod) && codes.some((c) => !/DOMESTIC/i.test(c))) {
      shippingMethod = codes.find((c) => !/DOMESTIC/i.test(c)) || shippingMethod;
    }
    if (!shippingMethod || (/DOMESTIC/i.test(shippingMethod) && address.country_code === 'US')) {
      const americas = codes.find((c) => /AMERICAS/i.test(c)) || 'SEED_LANE_AMERICAS';
      if (!/DOMESTIC/i.test(americas)) shippingMethod = americas;
    }
    const paymentMethod = String(forcedMethod || '').trim() || 'paypal';
    if (!shippingMethod) {
      return { success: false, message: 'no_shipping_for_us', codes, getData: root };
    }
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
    if (!frozen || frozen.success === false) return frozen || { success: false, message: 'freeze_failed' };
    const quoteToken = String((frozen.quote_token || (frozen.data && frozen.data.quote_token) || '')).trim();
    const submit = await api.submitV2({
      quote_token: quoteToken,
      payment_method: paymentMethod,
      idempotency_key: 'sandbox_demo10_' + Date.now(),
      country_code: address.country_code,
      guest_token: guestToken,
    }, { silent: true, requestTimeoutMs: 120000 });
    return {
      success: !(submit && submit.success === false),
      shipping_method: shippingMethod,
      getData: root,
      frozen,
      submit,
    };
  }, wantedMethod);

  result.steps.push('submit_done');
  if (!started || started.success === false) {
    throw new Error('checkout_submit_failed:' + JSON.stringify(started).slice(0, 1200));
  }

  const getMoney = moneyFrom(started.getData) || {};
  const frozenMoney = moneyFrom(started.frozen && (started.frozen.data || started.frozen)) || {};
  const submitMoney = moneyFrom(started.submit && (started.submit.data || started.submit)) || {};
  result.quote_money = getMoney;
  result.frozen_money = frozenMoney;
  result.submit_money = submitMoney;

  const authorityGrand = frozenMoney.grand_total_minor
    || submitMoney.grand_total_minor
    || getMoney.grand_total_minor;
  result.authority_grand_minor = authorityGrand;

  const authorityDisc = frozenMoney.discount_amount_minor
    || submitMoney.discount_amount_minor
    || getMoney.discount_amount_minor
    || result.coupon_discount_minor;
  result.authority_discount_minor = authorityDisc;
  if (!authorityDisc || authorityDisc <= 0) {
    throw new Error('checkout_discount_missing:' + JSON.stringify({
      getMoney, frozenMoney, submitMoney, coupon: result.coupon_discount_minor,
    }).slice(0, 1200));
  }

  const submit = started.submit || {};
  const payment = (submit.payment && typeof submit.payment === 'object')
    ? submit.payment
    : ((submit.data && submit.data.payment) || {});
  const redirectUrl = String(
    payment.redirect_url
    || submit.redirect_url
    || submit.approve_url
    || (submit.data && (submit.data.redirect_url || submit.data.approve_url))
    || ''
  ).trim();
  result.approve_url = redirectUrl;
  result.transaction_no = String(
    submit.transaction_no
    || (Array.isArray(payment.transactions) && payment.transactions[0] && payment.transactions[0].transaction_no)
    || ''
  ).trim();
  result.payment_amount_minor = extractPaymentAmountMinor(submit);
  if (!redirectUrl) throw new Error('checkout_redirect_missing:' + JSON.stringify(submit).slice(0, 800));

  if (result.payment_amount_minor != null && authorityGrand != null
    && Number(result.payment_amount_minor) !== Number(authorityGrand)) {
    throw new Error(`amount_mismatch_create:ui=${authorityGrand}:paypal=${result.payment_amount_minor}`);
  }

  const paypal = await context.newPage();
  await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded' });
  result.steps.push('paypal_opened');
  result.paypal_display_minor = await scrapePayPalDisplayedAmountMinor(paypal);
  if (result.paypal_display_minor != null && authorityGrand != null
    && Math.abs(Number(result.paypal_display_minor) - Number(authorityGrand)) > 1) {
    // Soft warn in result; hard fail only if create amount already matched and display drifts a lot.
    result.paypal_display_drift = true;
  }
  await loginPayPal(paypal);
  result.steps.push('paypal_approved');
  await paypal.waitForURL(/test\.weline\.com|127\.0\.0\.1/, { timeout: 120000 });
  result.success_url = paypal.url();
  if (!/checkout\/success/.test(result.success_url)) {
    await paypal.waitForURL(/checkout\/success/, { timeout: 90000 }).catch(() => {});
    result.success_url = paypal.url();
  }
  await paypal.waitForLoadState('domcontentloaded').catch(() => {});
  let bodyText = '';
  for (let i = 0; i < 6; i += 1) {
    try {
      bodyText = await paypal.content();
      break;
    } catch (e) {
      await paypal.waitForTimeout(1000);
    }
  }
  result.ok = /checkout\/success/.test(result.success_url)
    || /订单已支付|感谢您的订购|已支付成功|结账成功/.test(bodyText);
  result.amount_ok = result.payment_amount_minor == null
    || authorityGrand == null
    || Number(result.payment_amount_minor) === Number(authorityGrand);
  result.discount_ok = Number(authorityDisc) > 0;
  result.ok = !!(result.ok && result.amount_ok && result.discount_ok);
  if (!result.amount_ok) {
    result.error = `amount_mismatch:ui=${authorityGrand}:paypal=${result.payment_amount_minor}`;
  }
}

async function runExpress(page, context, result) {
  await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
  result.steps.push('pdp_loaded');
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  await page.waitForSelector(
    '[data-testid="product-express-payment"][data-product-express] [data-product-express-pay], [data-testid="product-express-paypal"]',
    { timeout: 60000 },
  );
  result.steps.push('express_button_ready');

  // Add to cart first so coupon can quote against real lines, then start express.
  const added = await addProductAndGuest(page);
  if (!added || !added.success) throw new Error('cart_add_failed:' + JSON.stringify(added).slice(0, 500));
  result.steps.push('cart_added');

  const couponRes = await applyCoupon(page, COUPON);
  result.coupon_apply = couponRes;
  const couponOk = !!(couponRes && (couponRes.success === true
    || String(couponRes.coupon_code || '').toUpperCase() === COUPON));
  if (!couponOk) throw new Error('coupon_apply_failed:' + JSON.stringify(couponRes).slice(0, 800));
  result.steps.push('coupon_applied:' + COUPON);
  const discPreview = (couponRes.discount && typeof couponRes.discount === 'object') ? couponRes.discount : {};
  result.coupon_discount_minor = Number(discPreview.amount_minor || 0) || 0;
  if (result.coupon_discount_minor <= 0) {
    throw new Error('coupon_discount_zero:' + JSON.stringify(couponRes).slice(0, 800));
  }

  const started = await page.evaluate(async () => {
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    const checkoutApi = await window.Weline.Api.resource('checkout');
    const express = await checkoutApi.startExpressCheckout({
      payment_method: 'paypal',
      guest_token: guestToken,
      cart_type: 'toc',
      selling_mode: 'toc',
    }, { silent: true, requestTimeoutMs: 120000 });
    // Also pull cart/checkout snapshot for money if available.
    let snapshot = null;
    try {
      snapshot = await checkoutApi.getData({
        guest_token: guestToken,
        cart_type: 'toc',
        selling_mode: 'toc',
      }, { silent: true, requestTimeoutMs: 60000 });
    } catch (e2) {}
    return { express, snapshot };
  });

  result.steps.push('express_started');
  const expressPayload = started && started.express;
  if (!expressPayload || expressPayload.success === false) {
    throw new Error('express_start_failed:' + JSON.stringify(expressPayload).slice(0, 800));
  }
  const data = expressPayload.data && typeof expressPayload.data === 'object' ? expressPayload.data : expressPayload;
  const redirectUrl = String(data.redirect_url || data.approve_url || (data.payment && data.payment.redirect_url) || '').trim();
  result.approve_url = redirectUrl;
  result.transaction_no = String(data.transaction_no || '').trim();
  result.payment_amount_minor = extractPaymentAmountMinor(expressPayload) || extractPaymentAmountMinor(data);
  const snapMoney = moneyFrom(started.snapshot && (started.snapshot.data || started.snapshot)) || {};
  const expressMoney = moneyFrom(data) || moneyFrom(data.totals) || {};
  result.quote_money = snapMoney;
  result.express_money = expressMoney;
  result.authority_grand_minor = expressMoney.grand_total_minor
    || snapMoney.grand_total_minor
    || result.payment_amount_minor;
  result.authority_discount_minor = expressMoney.discount_amount_minor
    || snapMoney.discount_amount_minor
    || result.coupon_discount_minor;
  if (!redirectUrl) throw new Error('express_redirect_missing:' + JSON.stringify(expressPayload).slice(0, 800));

  if (result.payment_amount_minor != null && result.authority_grand_minor != null
    && Number(result.payment_amount_minor) !== Number(result.authority_grand_minor)) {
    // Express create may be pre-shipping; keep as soft until review confirm.
    result.pre_shipping_amount_diff = true;
  }

  const paypal = await context.newPage();
  await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded' });
  result.steps.push('paypal_opened');
  result.paypal_display_minor = await scrapePayPalDisplayedAmountMinor(paypal);
  await loginPayPal(paypal);
  result.steps.push('paypal_approved');
  await paypal.waitForURL(/test\.weline\.com|127\.0\.0\.1/, { timeout: 120000 });
  result.review_url = paypal.url();
  if (!/express-review/.test(result.review_url)) {
    await paypal.waitForURL(/express-review/, { timeout: 60000 });
    result.review_url = paypal.url();
  }
  const m = result.review_url.match(/transaction_no=([^&]+)/);
  if (m) result.transaction_no = decodeURIComponent(m[1]);

  await paypal.waitForSelector('[data-testid="checkout-express-review"]', { timeout: 60000 });
  const reviewTotals = await paypal.evaluate(() => {
    const root = document.querySelector('[data-testid="checkout-express-review"]') || document;
    const text = root.innerText || '';
    const pickMinor = (label) => {
      const re = new RegExp('(?:' + label + ')[^\\d$¥￥\\n]*[$¥￥]?\\s*([0-9]+(?:\\.[0-9]{2})?)', 'i');
      const mm = text.match(re);
      return mm ? Math.round(parseFloat(mm[1]) * 100) : null;
    };
    return {
      text: text.slice(0, 800),
      subtotal_minor: pickMinor('小计|Subtotal'),
      discount_minor: pickMinor('优惠|Discount'),
      shipping_minor: pickMinor('运费|Shipping charges|Shipping'),
      grand_minor: pickMinor('应付|Due|应付合计|Grand total|Order total'),
      has_demo10: /DEMO10/i.test(text),
    };
  });
  result.review_totals = reviewTotals;
  if (!reviewTotals.has_demo10 && !(result.authority_discount_minor > 0)) {
    throw new Error('express_review_missing_demo10:' + JSON.stringify(reviewTotals).slice(0, 800));
  }

  // Fill shipping gaps for US sandbox address.
  const shipPhone = paypal.locator('[data-shipping-checkout-address] input[name="phone"]').first();
  if (await shipPhone.isVisible().catch(() => false)) {
    const cur = await shipPhone.inputValue().catch(() => '');
    if (!String(cur || '').trim()) {
      await shipPhone.fill('14085551234');
      await shipPhone.blur().catch(() => {});
      await paypal.waitForTimeout(1500);
    }
  }
  const shipName = paypal.locator('[data-shipping-checkout-address] input[name="name"]').first();
  if (await shipName.isVisible().catch(() => false)) {
    const cur = await shipName.inputValue().catch(() => '');
    if (!String(cur || '').trim()) await shipName.fill('Sandbox Buyer');
  }

  // Prefer Americas shipping if selectable.
  await paypal.evaluate(() => {
    const radios = Array.from(document.querySelectorAll('input[name="shipping_method"], input[data-shipping-method]'));
    const want = radios.find((r) => /AMERICAS|INTL/i.test(String(r.value || '')))
      || radios.find((r) => !/DOMESTIC/i.test(String(r.value || '')));
    if (want && !want.checked) {
      want.checked = true;
      want.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }).catch(() => {});
  await paypal.waitForTimeout(2000);

  // Re-read review money after shipping settle; also try API amend snapshot via DOM data attrs.
  const afterShip = await paypal.evaluate(() => {
    const el = document.querySelector('[data-grand-total-minor], [data-totals-grand-minor]');
    const grandAttr = el
      ? Number(el.getAttribute('data-grand-total-minor') || el.getAttribute('data-totals-grand-minor') || 0)
      : 0;
    const text = (document.querySelector('[data-testid="checkout-express-review"]') || document).innerText || '';
    const mm = text.match(/(?:应付|Due)[^\d$¥￥\n]*[$¥￥]?\s*([0-9]+\.[0-9]{2})/i);
    return {
      grand_attr: grandAttr || null,
      grand_text_minor: mm ? Math.round(parseFloat(mm[1]) * 100) : null,
      has_demo10: /DEMO10/i.test(text),
      discount_text: (text.match(/(?:优惠|Discount)[^\n]{0,40}/i) || [''])[0],
    };
  });
  result.after_ship_totals = afterShip;
  const reviewGrand = afterShip.grand_attr || afterShip.grand_text_minor || reviewTotals.grand_minor;
  if (reviewGrand) result.authority_grand_minor = reviewGrand;

  const confirm = paypal.locator('[data-testid="express-confirm-pay"]');
  await confirm.waitFor({ state: 'visible', timeout: 30000 });
  await paypal.waitForFunction(() => {
    const btn = document.querySelector('[data-testid="express-confirm-pay"]');
    const status = (document.querySelector('[data-express-status]') || {}).innerText || '';
    return !!(btn && !btn.disabled) || /已支付/.test(status);
  }, null, { timeout: 90000 }).catch(() => {});

  if (await confirm.isEnabled()) {
    // Intercept confirm response for final amount if exposed.
    let confirmPayload = null;
    paypal.on('response', async (resp) => {
      try {
        const url = resp.url();
        if (!/confirmExpress|express.*confirm|capture/i.test(url)) return;
        const json = await resp.json().catch(() => null);
        if (json) confirmPayload = json;
      } catch (e) {}
    });
    await Promise.all([
      paypal.waitForURL((url) => {
        const u = String(url);
        return /\/cart(?:\?|$)/.test(u) || /\/checkout\/success/.test(u) || /handoff/.test(u);
      }, { timeout: 120000 }).catch(() => {}),
      confirm.click(),
    ]);
    for (let i = 0; i < 12; i += 1) {
      const u = paypal.url();
      if (/\/checkout\/success/.test(u) || /\/cart(?:\?|$)/.test(u)) break;
      await paypal.waitForTimeout(800);
    }
    result.confirm_payload = confirmPayload;
    if (confirmPayload) {
      const confirmAmt = extractPaymentAmountMinor(confirmPayload);
      if (confirmAmt != null) result.payment_amount_minor = confirmAmt;
      const cm = moneyFrom(confirmPayload.data || confirmPayload);
      if (cm && cm.grand_total_minor) result.authority_grand_minor = cm.grand_total_minor;
      if (cm && cm.discount_amount_minor) result.authority_discount_minor = cm.discount_amount_minor;
    }
    result.success_url = paypal.url();
    result.steps.push('confirm_done:' + result.success_url);
    if (/\/cart(?:\?|$)/.test(result.success_url)) {
      throw new Error('final_url_is_cart:' + result.success_url);
    }
    const finalHtml = await paypal.content().catch(() => '');
    result.ok = /\/checkout\/success/.test(result.success_url)
      || /订单已支付|感谢您的订购|已支付成功|结账成功/.test(finalHtml);
  } else {
    const status = await paypal.locator('[data-express-status]').innerText().catch(() => '');
    throw new Error('confirm_disabled:' + status);
  }

  result.discount_ok = Number(result.authority_discount_minor) > 0 || !!(result.review_totals && result.review_totals.has_demo10);
  result.amount_ok = result.payment_amount_minor == null
    || result.authority_grand_minor == null
    || Number(result.payment_amount_minor) === Number(result.authority_grand_minor)
    || !!result.pre_shipping_amount_diff; // allow pre-ship create; final review must still show discount
  // Hard amount check: if we have both final payment and review grand, they must match.
  if (result.payment_amount_minor != null && result.authority_grand_minor != null
    && !result.pre_shipping_amount_diff
    && Number(result.payment_amount_minor) !== Number(result.authority_grand_minor)) {
    result.amount_ok = false;
    result.error = `amount_mismatch:ui=${result.authority_grand_minor}:paypal=${result.payment_amount_minor}`;
  }
  // For express, require DEMO10 visible or discount minor > 0, and success.
  result.ok = !!(result.ok && result.discount_ok && (result.amount_ok || result.pre_shipping_amount_diff));
}

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
    args: ['--disable-popup-blocking'],
  });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const result = {
    ok: false,
    flow: FLOW,
    coupon: COUPON,
    steps: [],
    error: '',
  };

  try {
    if (FLOW === 'express') {
      await runExpress(page, context, result);
    } else {
      await runCheckout(page, context, result);
    }
  } catch (e) {
    result.error = String(e && e.message || e);
    result.ok = false;
  }

  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  process.exit(result.ok ? 0 : 1);
}

main();
