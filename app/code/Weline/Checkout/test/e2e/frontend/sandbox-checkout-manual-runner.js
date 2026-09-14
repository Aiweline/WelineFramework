/**
 * PayPal sandbox — universal checkout (cart → /checkout → freeze/submitV2 → PayPal → success).
 */
const path = require('path');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.CHECKOUT_PDP_PATH
  || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
const BUYER_EMAIL = process.env.PAYPAL_SANDBOX_BUYER_EMAIL || 'sb-4sxrp30216572@personal.example.com';
const BUYER_PASS = process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || 'weline18';

async function loginPayPal(paypal) {
  await paypal.waitForLoadState('domcontentloaded');
  for (const sel of ['#loginWithPassword', '#tryPasswordLink', 'a:has-text("Use password instead")', 'a:has-text("Log in with your password")']) {
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
    if (/test\.weline\.com|127\.0\.0\.1|checkout\/success|payment\/frontend\/callback/.test(paypal.url())) {
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

async function ensureAddress(page) {
  const phone = page.locator('[data-shipping-checkout-address] input[name="phone"]').first();
  if (await phone.isVisible().catch(() => false)) {
    const cur = await phone.inputValue().catch(() => '');
    if (!String(cur || '').trim()) await phone.fill('14085551234');
  }
  const name = page.locator('[data-shipping-checkout-address] input[name="name"]').first();
  if (await name.isVisible().catch(() => false)) {
    const cur = await name.inputValue().catch(() => '');
    if (!String(cur || '').trim()) await name.fill('Sandbox Buyer');
  }
  const line1 = page.locator('[data-shipping-checkout-address] input[name="address1"], [data-shipping-checkout-address] input[name="street"]').first();
  if (await line1.isVisible().catch(() => false)) {
    const cur = await line1.inputValue().catch(() => '');
    if (!String(cur || '').trim()) await line1.fill('1 Main St');
  }
  const city = page.locator('[data-shipping-checkout-address] input[name="city"]').first();
  if (await city.isVisible().catch(() => false)) {
    const cur = await city.inputValue().catch(() => '');
    if (!String(cur || '').trim()) await city.fill('San Jose');
  }
  const postal = page.locator('[data-shipping-checkout-address] input[name="postal_code"]').first();
  if (await postal.isVisible().catch(() => false)) {
    const cur = await postal.inputValue().catch(() => '');
    if (!String(cur || '').trim()) await postal.fill('95131');
  }
  const country = page.locator('[data-shipping-checkout-address] select[name="country_code"], [data-shipping-checkout-address] [name="country_code"]').first();
  if (await country.count()) {
    await country.selectOption('US').catch(async () => {
      await country.fill('US').catch(() => {});
    });
  }
}

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
    args: ['--disable-popup-blocking'],
  });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const result = { ok: false, flow: 'checkout', steps: [], approve_url: '', success_url: '', transaction_no: '', error: '' };

  try {
    await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
    result.steps.push('pdp_loaded');
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
    if (!added || !added.success) throw new Error('cart_add_failed:' + JSON.stringify(added).slice(0, 500));
    result.steps.push('cart_added');

    await page.goto(FRONTEND + '/checkout', { waitUntil: 'domcontentloaded' });
    result.steps.push('checkout_opened');
    await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
    await page.waitForTimeout(1500);
    await ensureAddress(page);
    // Shipping/payment radios may lag or stay empty while quote API already has lanes;
    // freezeQuote path below refreshes US quote and picks SEED_LANE_AMERICAS.
    const payRadio = page.locator('input[name="payment_method"][value="paypal"], input[name="payment_method"][value*="paypal"]').first();
    if (await payRadio.count()) {
      await payRadio.check({ force: true }).catch(() => {});
    }
    result.steps.push('ui_ready');

    const started = await page.evaluate(async () => {
      const getVal = (name) => {
        const el = document.querySelector(`[name="${name}"]:checked`) || document.querySelector(`[name="${name}"]`);
        return el ? String(el.value || '').trim() : '';
      };
      let guestToken = '';
      try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
      // Force US destination so Americas lanes are reachable (avoid CN domestic sticky session).
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
      // Refresh quote/shipping for US address (do NOT pass unknown `address` — worker rejects it on getData).
      const data = await api.getData({
        guest_token: guestToken,
        shipping_address: address,
        cart_type: 'toc',
        selling_mode: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      const root = (data && data.data && typeof data.data === 'object') ? data.data : data;
      const methods = (root && root.shipping_methods) || [];
      const codes = Array.isArray(methods) ? methods.map((m) => String((m && (m.code || m.service_code)) || '')).filter(Boolean) : [];
      const uiCode = getVal('shipping_method');
      let shippingMethod = codes.find((c) => /AMERICAS|americas|INTL|intl/i.test(c))
        || codes.find((c) => c && !/DOMESTIC/i.test(c))
        || (uiCode && !/DOMESTIC/i.test(uiCode) ? uiCode : '')
        || codes[0]
        || '';
      // US destination must not freeze on domestic-only lane (unreachable family).
      if (/DOMESTIC/i.test(shippingMethod) && codes.some((c) => !/DOMESTIC/i.test(c))) {
        shippingMethod = codes.find((c) => !/DOMESTIC/i.test(c)) || shippingMethod;
      }
      if (!shippingMethod || (/DOMESTIC/i.test(shippingMethod) && address.country_code === 'US')) {
        // Prefer known Americas seed when UI still sticky on CN domestic.
        const americas = codes.find((c) => /AMERICAS/i.test(c)) || 'SEED_LANE_AMERICAS';
        if (!/DOMESTIC/i.test(americas)) shippingMethod = americas;
      }
      const paymentMethod = getVal('payment_method') || 'paypal';
      if (!shippingMethod) {
        return {
          success: false,
          message: 'no_shipping_for_us',
          codes,
          uiCode,
          diagnostics: (root && root.quote_diagnostics) || null,
          empty: (root && root.shipping_empty_message) || '',
        };
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
      const result = await api.submitV2({
        quote_token: quoteToken,
        payment_method: paymentMethod,
        idempotency_key: 'sandbox_checkout_' + Date.now(),
        country_code: address.country_code,
        guest_token: guestToken,
      }, { silent: true, requestTimeoutMs: 120000 });
      if (result && typeof result === 'object') {
        result._shipping_method = shippingMethod;
      }
      return result;
    });
    result.steps.push('submit_done');
    if (!started || started.success === false) {
      throw new Error('checkout_submit_failed:' + JSON.stringify(started).slice(0, 800));
    }
    const payment = (started.payment && typeof started.payment === 'object')
      ? started.payment
      : ((started.data && started.data.payment) || {});
    const redirectUrl = String(
      payment.redirect_url
      || started.redirect_url
      || started.approve_url
      || (started.data && (started.data.redirect_url || started.data.approve_url))
      || ''
    ).trim();
    result.approve_url = redirectUrl;
    const txnFromList = Array.isArray(payment.transactions) && payment.transactions[0]
      ? String(payment.transactions[0].transaction_no || '')
      : '';
    result.transaction_no = String(started.transaction_no || txnFromList || '').trim();
    if (!redirectUrl) throw new Error('checkout_redirect_missing:' + JSON.stringify(started).slice(0, 800));

    const paypal = await context.newPage();
    await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded' });
    result.steps.push('paypal_opened');
    await loginPayPal(paypal);
    result.steps.push('paypal_approved');
    await paypal.waitForURL(/test\.weline\.com|127\.0\.0\.1/, { timeout: 120000 });
    result.success_url = paypal.url();
    result.steps.push('returned:' + result.success_url);
    if (!/checkout\/success|payment\/.*callback|handoff/.test(result.success_url)) {
      await paypal.waitForURL(/checkout\/success/, { timeout: 60000 }).catch(() => {});
      result.success_url = paypal.url();
    }
    result.ok = /checkout\/success/.test(result.success_url) || /订单已支付|感谢您的订购|已支付成功|结账成功/.test(await paypal.content());
  } catch (e) {
    result.error = String(e && e.message || e);
  }

  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  process.exit(result.ok ? 0 : 1);
}

main();
