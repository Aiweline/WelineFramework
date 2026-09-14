/**
 * One-shot sandbox express flow helper (local only).
 */
const path = require('path');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.EXPRESS_PDP_PATH
  || '/product/hua-chao-ji-yuan-chuang-han-fu-luo-chuan-tang-zhi-qi-xiong-68a0ecd7/?size=s&style_type=luo-chuan-da';
const BUYER_EMAIL = process.env.PAYPAL_SANDBOX_BUYER_EMAIL || 'sb-4sxrp30216572@personal.example.com';
const BUYER_PASS = process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || 'weline18';

async function loginPayPal(paypal) {
  await paypal.waitForLoadState('domcontentloaded');
  // Escape app push / MFA traps.
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

  // Keep approving / continuing until storefront return.
  for (let i = 0; i < 12; i += 1) {
    if (/test\.weline\.com|127\.0\.0\.1|express-review|payment\/frontend\/callback/.test(paypal.url())) {
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

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
    args: ['--disable-popup-blocking'],
  });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const result = { ok: false, steps: [], approve_url: '', review_url: '', transaction_no: '', error: '' };

  try {
    await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
    result.steps.push('pdp_loaded');
    await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
    await page.waitForSelector(
      '[data-testid="product-express-payment"][data-product-express] [data-product-express-pay], [data-testid="product-express-paypal"]',
      { timeout: 60000 },
    );
    result.steps.push('express_button_ready');

    // Drive the same JS path as the button, but return the redirect URL instead of relying on window.open.
    const started = await page.evaluate(async () => {
      const section = document.querySelector('[data-testid="product-express-payment"][data-product-express]');
      const button = section && section.querySelector('[data-product-express-pay],[data-express-pay]');
      if (!section || !button) {
        return { success: false, message: 'express_button_missing' };
      }
      const root = (section.closest && section.closest('.product-native-detail')) || document;
      const buyNow = root.querySelector('[data-action="buy-now"][data-product-id]')
        || root.querySelector('[data-testid="product-buy-now"]')
        || document.querySelector('[data-action="buy-now"]');
      const productId = Number((buyNow && buyNow.dataset.productId) || section.getAttribute('data-product-id') || 0) || 0;
      const offerUuid = String((buyNow && buyNow.dataset.globalOfferUuid) || section.getAttribute('data-global-offer-uuid') || '').trim();
      const selection = {};
      root.querySelectorAll('[data-variant-option].is-selected').forEach((option) => {
        const axisCode = String(option.dataset.variantAxis || '').trim();
        const axisValue = String(option.dataset.variantValue || '').trim();
        if (axisCode && axisValue) selection[axisCode] = axisValue;
      });
      const qtySelect = root.querySelector('[data-testid="product-qty"]');
      const qty = qtySelect ? Math.max(1, Number(qtySelect.value || 1) || 1) : 1;

      let guestToken = '';
      try {
        guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim();
      } catch (e) {}
      if (!guestToken && window.WelineCart && typeof window.WelineCart.getGuestSession === 'function') {
        const existing = window.WelineCart.getGuestSession();
        guestToken = String((existing && existing.token) || '').trim();
      }
      const cartApi = await window.Weline.Api.resource('cart');
      if (!guestToken) {
        const issued = await cartApi.issueGuestToken({}, { silent: true });
        const payload = issued && issued.data && typeof issued.data === 'object' ? issued.data : issued;
        guestToken = String((payload && payload.guest_token) || (issued && issued.guest_token) || '').trim();
        if (guestToken) {
          try { sessionStorage.setItem('weline.cart.guest_token', guestToken); } catch (e2) {}
        }
      }
      if (!guestToken) {
        return { success: false, message: 'guest_token_missing' };
      }

      const addResult = await cartApi.add({
        provider_code: (buyNow && buyNow.dataset.providerCode) || 'product',
        global_offer_uuid: offerUuid,
        legacy_product_id: productId,
        selection,
        guest_token: guestToken,
        qty,
        selling_mode: 'toc',
        cart_type: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      if (!addResult || addResult.success === false) {
        return addResult || { success: false, message: 'add_failed' };
      }
      const checkoutApi = await window.Weline.Api.resource('checkout');
      return checkoutApi.startExpressCheckout({
        payment_method: (button.getAttribute('data-method-code') || 'paypal'),
        guest_token: guestToken,
        cart_type: 'toc',
        selling_mode: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
    });
    result.steps.push('express_started');
    if (!started || started.success === false) {
      throw new Error('express_start_failed:' + JSON.stringify(started).slice(0, 800));
    }
    const data = started.data && typeof started.data === 'object' ? started.data : started;
    const redirectUrl = String(data.redirect_url || data.approve_url || (data.payment && data.payment.redirect_url) || '').trim();
    result.approve_url = redirectUrl;
    result.transaction_no = String(data.transaction_no || '').trim();
    if (!redirectUrl) {
      throw new Error('express_redirect_missing:' + JSON.stringify(started).slice(0, 800));
    }

    const paypal = await context.newPage();
    await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded' });
    result.steps.push('paypal_opened');
    await loginPayPal(paypal);
    result.steps.push('paypal_approved');

    await paypal.waitForURL(/test\.weline\.com|127\.0\.0\.1/, { timeout: 120000 });
    result.review_url = paypal.url();
    result.steps.push('returned_storefront');
    const m = result.review_url.match(/transaction_no=([^&]+)/);
    if (m) {
      result.transaction_no = decodeURIComponent(m[1]);
    }
    if (!/express-review/.test(result.review_url)) {
      await paypal.waitForURL(/express-review/, { timeout: 60000 });
      result.review_url = paypal.url();
      const m2 = result.review_url.match(/transaction_no=([^&]+)/);
      if (m2) result.transaction_no = decodeURIComponent(m2[1]);
    }

    await paypal.waitForSelector('[data-testid="checkout-express-review"]', { timeout: 60000 });
    const body = await paypal.content();
    if (!body.includes('尚未扣款')) {
      throw new Error('missing_not_charged_copy');
    }
    result.express_review_url = result.review_url;
    if (!body.includes('data-shipping-checkout-address') && !body.includes('checkout-shipping-address')) {
      throw new Error('missing_shipping_address_widget');
    }
    if (body.includes('支付商带回，只读')) {
      throw new Error('address_still_readonly_copy');
    }

    // Prefer Shipping widget phone/name fields; fall back to gap inputs.
    const shipPhone = paypal.locator('[data-shipping-checkout-address] input[name="phone"]').first();
    if (await shipPhone.isVisible().catch(() => false)) {
      const cur = await shipPhone.inputValue().catch(() => '');
      if (!String(cur || '').trim()) {
        await shipPhone.fill('13800138000');
      }
    }
    const shipName = paypal.locator('[data-shipping-checkout-address] input[name="name"]').first();
    if (await shipName.isVisible().catch(() => false)) {
      const cur = await shipName.inputValue().catch(() => '');
      if (!String(cur || '').trim()) {
        await shipName.fill('John Doe');
      }
    }
    const phone = paypal.locator('#express-phone');
    if (await phone.isVisible().catch(() => false)) {
      await phone.fill('13800138000');
    }
    const emailGap = paypal.locator('#express-email');
    if (await emailGap.isVisible().catch(() => false)) {
      await emailGap.fill(BUYER_EMAIL);
    }

    if (process.env.EXPRESS_STOP_AT_REVIEW === '1') {
      result.steps.push('stopped_at_review');
      result.ok = !!result.transaction_no && /express-review/.test(result.review_url);
      console.log(JSON.stringify(result, null, 2));
      await browser.close();
      process.exit(result.ok ? 0 : 1);
      return;
    }

    // PayPal US address may need phone gap + shipping re-select; wait for can_confirm.
    const shipPhone2 = paypal.locator('[data-shipping-checkout-address] input[name="phone"]').first();
    if (await shipPhone2.isVisible().catch(() => false)) {
      const cur = await shipPhone2.inputValue().catch(() => '');
      if (!String(cur || '').trim()) {
        await shipPhone2.fill('14085551234');
        await shipPhone2.blur().catch(() => {});
        await paypal.waitForTimeout(1500);
      }
    }
    const confirm = paypal.locator('[data-testid="express-confirm-pay"]');
    await confirm.waitFor({ state: 'visible', timeout: 30000 });
    await paypal.waitForFunction(() => {
      const btn = document.querySelector('[data-testid="express-confirm-pay"]');
      const status = (document.querySelector('[data-express-status]') || {}).innerText || '';
      return !!(btn && !btn.disabled) || /已支付/.test(status);
    }, null, { timeout: 90000 }).catch(() => {});
    if (await confirm.isEnabled()) {
      await Promise.all([
        paypal.waitForURL((url) => {
          const u = String(url);
          if (/\/cart(?:\?|$)/.test(u)) return true;
          if (/\/checkout\/success/.test(u)) return true;
          if (/\/payment\/.*handoff|\/checkout\/handoff/.test(u)) return true;
          return false;
        }, { timeout: 120000 }).catch(() => {}),
        confirm.click(),
      ]);
      for (let i = 0; i < 10; i += 1) {
        const u = paypal.url();
        if (/\/checkout\/success/.test(u) || /\/cart(?:\?|$)/.test(u)) {
          break;
        }
        if (/handoff/.test(u)) {
          await paypal.waitForTimeout(1200);
          continue;
        }
        await paypal.waitForTimeout(800);
      }
      result.steps.push('confirm_done:' + paypal.url());
      result.handoff_url = paypal.url();
      result.review_url = paypal.url();
      if (/\/cart(?:\?|$)/.test(paypal.url())) {
        throw new Error('final_url_is_cart:' + paypal.url());
      }
      const finalHtml = await paypal.content();
      if (!/\/checkout\/success/.test(paypal.url()) && !/订单已支付|感谢您的订购|已支付成功|结账成功/.test(finalHtml)) {
        throw new Error('missing_paid_success_copy:' + paypal.url());
      }
    } else {
      const status = await paypal.locator('[data-express-status]').innerText().catch(() => '');
      result.steps.push('confirm_disabled:' + status);
      // Still success if we reached real review with txn.
      result.error = status ? ('confirm_disabled:' + status) : 'confirm_disabled';
    }

    result.ok = !!result.transaction_no
      && /success/.test(result.review_url)
      && !/\/cart(?:\?|$)/.test(result.review_url);
  } catch (e) {
    result.error = String(e && e.message || e);
  }

  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  process.exit(result.ok ? 0 : 1);
}

main();
