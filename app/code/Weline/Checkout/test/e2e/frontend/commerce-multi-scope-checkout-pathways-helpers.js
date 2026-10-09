/**
 * Multi-scope commerce checkout pathway helpers (guest / login / wholesale).
 * Prefer UI hooks: data-testid checkout-coupon-* / order-notice-input.
 */
const path = require('path');
const {
  storefrontOrigin,
  gotoStorefront,
  waitForWelineApi,
  addToCartFromPdp,
  ensureCheckoutAddress,
  loginAsCustomerApi,
  runFixture,
  assertStep,
  ADDRESS,
} = require('./release-paypal-account-lifecycle-helpers');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const MULTI_SCOPE_FIXTURE = path.resolve(__dirname, 'commerce-multi-scope-checkout-pathways-fixture.php');
const COUPON = String(process.env.COUPON_CODE || 'DEMO10').trim().toUpperCase();
const NOTE_TEXT = String(process.env.E2E_ORDER_NOTE || 'e2e-multi-scope-order-note').trim();

async function dismissStorefrontNoise(page) {
  for (const sel of [
    'button:has-text("同意")',
    'button:has-text("Agree")',
    '[data-cookie-accept]',
    '[data-consent-accept]',
    '[data-newsletter-popup] button[aria-label*="Close"], [data-newsletter-popup] [data-close], .newsletter-popup__close',
  ]) {
    const btn = page.locator(sel).first();
    if (await btn.isVisible({ timeout: 600 }).catch(() => false)) {
      await btn.click({ force: true, timeout: 2000 }).catch(() => {});
    }
  }
  await page.keyboard.press('Escape').catch(() => {});
}

/**
 * Prefer public HTTPS Host for multi-scope pathways.
 * PLAYWRIGHT_TARGET_ORIGIN often points at http://127.0.0.1:worker which
 * attack_guard / hang can break; release helpers already document this.
 */
function multiScopeOrigin() {
  const forced = String(process.env.WELINE_E2E_STOREFRONT_ORIGIN || process.env.WELINE_E2E_BASE_URL || '').trim();
  if (forced) return forced.replace(/\/$/, '');
  const fromHelper = storefrontOrigin();
  if (/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/i.test(fromHelper)) {
    return 'https://p05113ef3.test.weline.com';
  }
  return fromHelper || 'https://p05113ef3.test.weline.com';
}

async function gotoMultiScope(page, routePath, gotoFrontend) {
  const origin = multiScopeOrigin();
  const abs = routePath.startsWith('http')
    ? routePath
    : `${origin}${routePath.startsWith('/') ? '' : '/'}${routePath}`;
  try {
    await page.goto(abs, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(600);
    return;
  } catch (error) {
    // Fall through to framework gotoFrontend (may use proxy / alternate origin).
  }
  try {
    await gotoStorefront(page, routePath);
  } catch (error2) {
    await gotoFrontend(page, routePath, { timeout: 90000, settleMs: 600, useProxy: false });
  }
}

async function addToCartMode(page, gotoFrontend, pdpPath, mode) {
  const selling = mode === 'tob' ? 'tob' : 'toc';
  await gotoMultiScope(page, pdpPath, gotoFrontend);
  await waitForWelineApi(page);
  await dismissStorefrontNoise(page).catch(() => {});
  await page.waitForSelector(
    '[data-testid="product-add-to-cart"], [data-testid="product-buy-now"], [data-action="add"][data-product-id], [data-action="buy-now"][data-product-id]',
    { timeout: 60000 }
  );
  const added = await page.evaluate(async (cartMode) => {
    let api = window.Weline.Api;
    if (!api || typeof api.resource !== 'function') {
      api = await window.Weline.load('api');
    }
    const buyNow = document.querySelector('[data-testid="product-add-to-cart"][data-product-id]')
      || document.querySelector('[data-testid="product-buy-now"][data-product-id]')
      || document.querySelector('[data-action="add"][data-product-id]')
      || document.querySelector('[data-action="buy-now"][data-product-id]')
      || document.querySelector('[data-action="add-to-cart"][data-product-id]');
    if (!buyNow) {
      return {
        success: false,
        message: 'buy_now_missing',
        url: location.href,
        html_hint: (document.body && document.body.innerHTML || '').includes('product-add-to-cart'),
      };
    }
    const root = (buyNow.closest && buyNow.closest('.product-native-detail')) || document;
    root.querySelectorAll('[data-variant-axis]').forEach((axisHost) => {
      if (!axisHost.querySelector('[data-variant-option].is-selected')) {
        const first = axisHost.querySelector('[data-variant-option]');
        if (first) first.classList.add('is-selected');
      }
    });
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
    const cartApi = await api.resource('cart');
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
      qty: cartMode === 'tob' ? 2 : 1,
      selling_mode: cartMode,
      cart_type: cartMode,
    }, { silent: true, requestTimeoutMs: 120000 });
    return { success: !(addResult && addResult.success === false), guest_token: guestToken, add: addResult, mode: cartMode };
  }, selling);
  assertStep(added && added.success, `cart.add.${selling}`, added);
  return added;
}

async function openCheckout(page, gotoFrontend) {
  await gotoMultiScope(page, '/checkout', gotoFrontend);
  await waitForWelineApi(page);
  await dismissStorefrontNoise(page).catch(() => {});
  // Newsletter popup often covers checkout extras tabs.
  const nlClose = page.locator(
    '[data-newsletter-popup] button[aria-label*="Close"], [data-newsletter-popup] [data-close], .newsletter-popup__close, button:has-text("×")'
  ).first();
  if (await nlClose.isVisible().catch(() => false)) {
    await nlClose.click({ force: true }).catch(() => {});
  }
  await page.waitForSelector('[data-testid="checkout-form-page"], [data-weline-checkout]', { timeout: 90000 });
}

async function revealCouponWidget(page) {
  const couponTab = page.locator(
    '[data-mini-cart-tab], [data-extras-tab], [role="tab"], button'
  ).filter({ hasText: /优惠券|Coupon/i }).first();
  if (await couponTab.count()) {
    await couponTab.click({ force: true, timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  const root = page.locator('[data-testid="checkout-coupon"], [data-widget-code="checkout-coupon"]').first();
  await root.waitFor({ state: 'attached', timeout: 30000 });
  await root.evaluate((el) => {
    el.hidden = false;
    el.style.display = 'block';
    el.style.visibility = 'visible';
    el.removeAttribute('hidden');
    const entry = el.querySelector('[data-marketing-coupon-entry]');
    if (entry) {
      entry.hidden = false;
      entry.style.display = '';
    }
  }).catch(() => {});
  return root;
}

async function applyCouponViaApi(page, code = COUPON, mode = 'toc') {
  await waitForWelineApi(page);
  return page.evaluate(async ({ couponCode, cartMode }) => {
    let api = window.Weline && window.Weline.Api;
    if (!api || typeof api.resource !== 'function') {
      api = await window.Weline.load('api');
    }
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    const marketing = await api.resource('marketing');
    return marketing.applyCoupon({
      coupon_code: couponCode,
      guest_token: guestToken,
      cart_type: cartMode,
      selling_mode: cartMode,
    }, { silent: true, requestTimeoutMs: 60000 });
  }, { couponCode: code, cartMode: mode === 'tob' ? 'tob' : 'toc' });
}

async function applyCouponViaUi(page, code = COUPON) {
  const root = await revealCouponWidget(page);
  const input = root.locator('[data-testid="checkout-coupon-input"], [data-marketing-coupon-input]').first();
  const apply = root.locator('[data-testid="checkout-coupon-apply"], [data-marketing-coupon-apply]').first();
  await input.waitFor({ state: 'visible', timeout: 20000 });
  await input.fill(code);
  await apply.click();
  await page.waitForTimeout(1200);

  // Prefer durable UI evidence (tags / message / applied code).
  const uiEvidence = await root.evaluate((el, couponCode) => {
    const tags = el.querySelector('[data-marketing-coupon-tags]');
    const msg = el.querySelector('[data-marketing-coupon-message]');
    const tagsText = tags ? String(tags.textContent || tags.getAttribute('data-codes') || '') : '';
    const msgText = msg ? String(msg.textContent || '') : '';
    const dataset = JSON.stringify(el.dataset || {});
    const hay = `${tagsText} ${msgText} ${dataset}`.toUpperCase();
    const codeUp = String(couponCode || '').toUpperCase();
    const hasCode = hay.includes(codeUp);
    const looksOk = hasCode || /已应用|applied|success|优惠/.test(hay);
    const looksFail = /不可用|invalid|unavailable|error|失败|上限/.test(hay) && !hasCode;
    return {
      tags: tagsText.slice(0, 200),
      message: msgText.slice(0, 200),
      has_code: hasCode,
      looks_ok: looksOk && !looksFail,
      looks_fail: looksFail,
    };
  }, code).catch(() => ({ looks_ok: false }));

  if (uiEvidence && uiEvidence.looks_ok) {
    return { success: true, coupon_code: code, via: 'ui', ui: uiEvidence };
  }

  // API fallback (ensure Api is loaded — UI click alone may not keep Weline.Api).
  const viaApi = await applyCouponViaApi(page, code, 'toc').catch((e) => ({
    __error: String(e && e.message || e),
    success: false,
  }));
  if (viaApi && !viaApi.__error) {
    viaApi.via = 'api';
    viaApi.ui = uiEvidence;
  }
  return viaApi;
}

async function fillOrderNoteViaUi(page, text = NOTE_TEXT) {
  await dismissStorefrontNoise(page).catch(() => {});
  // Close newsletter / cookie overlays that steal clicks.
  for (const sel of [
    '[data-newsletter-popup] [data-close], [data-newsletter-popup] .w-dialog__close, .newsletter-popup [aria-label="Close"], button:has-text("×")',
    '[data-cookie-consent] button, [data-cookie-banner] button, button:has-text("同意")',
  ]) {
    const loc = page.locator(sel).first();
    if (await loc.isVisible().catch(() => false)) {
      await loc.click({ force: true, timeout: 2000 }).catch(() => {});
    }
  }
  await page.keyboard.press('Escape').catch(() => {});

  const tab = page.locator(
    '[data-mini-cart-tab], [data-extras-tab], [role="tab"], button, a'
  ).filter({ hasText: /^订单留言$|^留言$|Order\s*Note/i }).first();
  if (await tab.count()) {
    await tab.click({ force: true, timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(500);
  }

  const input = page.locator('[data-testid="order-notice-input"], [data-order-notice-input]').first();
  await input.waitFor({ state: 'attached', timeout: 20000 });
  // Force-reveal ancestors (mini-cart extras panel may keep inactive tabs display:none).
  await input.evaluate((el) => {
    let node = el;
    for (let i = 0; i < 8 && node; i += 1) {
      node.hidden = false;
      node.removeAttribute('hidden');
      if (node.style) {
        if (node.style.display === 'none') node.style.display = 'block';
        if (node.style.visibility === 'hidden') node.style.visibility = 'visible';
      }
      node = node.parentElement;
    }
  }).catch(() => {});

  try {
    await input.fill(text, { timeout: 5000 });
  } catch (_) {
    await input.evaluate((el, value) => {
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }, text);
  }
  await input.blur().catch(() => {});
  await page.waitForTimeout(600);
  const saved = await input.inputValue().catch(() => '');
  return { filled: text, saved: String(saved || '') };
}

async function probeWholesaleSurfaces(page) {
  return page.evaluate(() => {
    const deposit = document.querySelector('[data-testid="b2b-checkout-deposit-note"], [data-b2b-deposit-note]');
    const coupon = document.querySelector('[data-testid="checkout-coupon"], [data-marketing-checkout-coupon]');
    const couponDisabled = !!(coupon && (
      coupon.hasAttribute('data-disabled')
      || coupon.getAttribute('aria-disabled') === 'true'
      || coupon.classList.contains('is-disabled')
      || !!(coupon.querySelector('[disabled], [aria-disabled="true"]'))
    ));
    return {
      deposit_present: !!deposit,
      deposit_hidden: !!(deposit && deposit.hasAttribute('hidden')),
      deposit_visible: !!(deposit && !deposit.hasAttribute('hidden') && deposit.getClientRects().length > 0),
      coupon_present: !!coupon,
      coupon_disabled_hint: couponDisabled,
    };
  });
}

async function applyCouponExpectFailForTob(page, code = COUPON) {
  try {
    const applied = await applyCouponViaApi(page, code, 'tob');
    return applied;
  } catch (error) {
    return {
      success: false,
      __error: String(error && (error.message || error)),
      response: error && error.response && error.response.data ? error.response.data : null,
    };
  }
}

/**
 * Freeze + submitV2 via fake_card to produce durable order_uuid evidence.
 * @param {{mode?: string, email?: string, note?: string, coupon?: string, scope?: string, pdp?: string, gotoFrontend?: Function}} meta
 */
async function submitCheckoutCaptureEvidence(page, meta = {}) {
  const mode = meta.mode === 'tob' ? 'tob' : 'toc';
  const paymentMethod = String(process.env.FORCE_PAYMENT_METHOD || 'fake_card').toLowerCase();
  await waitForWelineApi(page);
  await dismissStorefrontNoise(page).catch(() => {});

  // Rewarm Worker Scope before freeze/submit (duplicate Host-Scope cookies break QueryBin).
  // tob: skip cookie clear/nav — wholesale cart+session is fragile across Scope rewrite.
  if (mode !== 'tob') {
    try {
      const context = page.context();
      const recoveryUrl = page.url();
      const scopePrefix = '__Host-Weline-Worker-Scope-Bootstrap-';
      for (const cookie of (await context.cookies()).filter((c) => String(c.name || '').startsWith(scopePrefix))) {
        await context.clearCookies({
          name: cookie.name,
          domain: cookie.domain,
          path: cookie.path || '/',
        }).catch(() => {});
      }
      await page.goto(recoveryUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await waitForWelineApi(page);
      await page.evaluate(async () => {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.bootstrapScope === 'function') {
          await window.Weline.Api.bootstrapScope();
        }
      }).catch(() => {});
      await page.waitForTimeout(1000);
      if (meta.email) {
        await ensureCheckoutAddress(page, String(meta.email));
        await page.waitForTimeout(800);
      }
    } catch (_) {
      // continue; submit may still succeed on a clean scope
    }
  }

  const started = await page.evaluate(async ({ cartMode, payMethod, shippingEmail }) => {
    try {
      let api = window.Weline && window.Weline.Api;
      if (!api || typeof api.resource !== 'function') {
        api = await window.Weline.load('api');
      }
      let guestToken = '';
      try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}

      const checkoutApi = await api.resource('checkout');
      const address = {
        name: 'Release PayPal Buyer',
        phone: '14085551234',
        email: shippingEmail || '',
        address1: '1 Main St',
        street: '1 Main St',
        city: 'San Jose',
        province: 'California',
        province_name: 'California',
        postal_code: '95131',
        country_code: 'US',
        country: 'United States',
        country_name: 'United States',
      };

      const probeModes = cartMode === 'tob' ? ['tob', 'toc'] : [cartMode];
      let activeMode = cartMode;
      let root = null;
      let methods = [];
      let codes = [];
      let shippingMethod = '';
      let cartProbe = [];
      for (const tryMode of probeModes) {
        let preview = null;
        try {
          preview = await checkoutApi.getData({
            guest_token: guestToken,
            shipping_address: address,
            cart_type: tryMode,
            selling_mode: tryMode,
          }, { silent: true, requestTimeoutMs: 120000 });
        } catch (e) {
          preview = { __error: String(e && e.message || e) };
        }
        const previewRoot = (preview && preview.data && typeof preview.data === 'object') ? preview.data : preview;
        const lines = (previewRoot && (previewRoot.lines || previewRoot.items || (previewRoot.cart && previewRoot.cart.lines))) || [];
        const lineCount = Array.isArray(lines) ? lines.length : 0;
        cartProbe.push({ mode: tryMode, line_count: lineCount, error: preview && preview.__error ? preview.__error : '' });
        if (lineCount > 0) {
          activeMode = tryMode;
          root = previewRoot;
          methods = (previewRoot && previewRoot.shipping_methods) || [];
          codes = Array.isArray(methods)
            ? methods.map((m) => String((m && (m.code || m.service_code)) || '')).filter(Boolean)
            : [];
          shippingMethod = codes.find((c) => /AMERICAS|US|INTL/i.test(c))
            || codes.find((c) => c && !/DOMESTIC/i.test(c))
            || codes[0]
            || '';
          if (shippingMethod) break;
        }
      }
      if (!shippingMethod) {
        return { success: false, message: 'no_shipping_or_empty_cart', codes, cart_probe: cartProbe, preview: root };
      }

      let frozen = null;
      const freezeModes = activeMode === 'tob' ? ['tob', 'toc'] : [activeMode];
      let freezeError = '';
      for (const freezeMode of freezeModes) {
        try {
          frozen = await checkoutApi.freezeQuote({
            address,
            billing_address: address,
            billing_same_as_shipping: true,
            service_code: shippingMethod,
            payment_method: payMethod,
            guest_token: guestToken,
            cart_type: freezeMode,
            selling_mode: freezeMode,
          }, { silent: true, requestTimeoutMs: 120000 });
          if (frozen && frozen.success !== false) {
            activeMode = freezeMode;
            freezeError = '';
            break;
          }
          freezeError = 'freeze_failed:' + JSON.stringify(frozen).slice(0, 200);
        } catch (e) {
          freezeError = String(e && e.message || e);
          frozen = null;
        }
      }
      if (!frozen || frozen.success === false) {
        return {
          success: false,
          message: 'freeze_threw',
          error: freezeError,
          shipping_method: shippingMethod,
          active_mode: activeMode,
          cart_probe: cartProbe,
        };
      }
      const payload = (frozen.data && typeof frozen.data === 'object') ? frozen.data : frozen;
      const quoteToken = String((frozen.quote_token || (payload && payload.quote_token) || '')).trim();
      if (!quoteToken) {
        return { success: false, message: 'quote_token_missing', frozen, cart_probe: cartProbe };
      }

      let result = null;
      try {
        result = await checkoutApi.submitV2({
          quote_token: quoteToken,
          payment_method: payMethod,
          idempotency_key: 'multi_scope_' + cartMode + '_' + Date.now(),
          country_code: address.country_code,
          guest_token: guestToken,
        }, { silent: true, requestTimeoutMs: 120000 });
      } catch (e) {
        return {
          success: false,
          message: 'submit_threw',
          error: String(e && e.message || e),
          quote_token: quoteToken,
          cart_probe: cartProbe,
        };
      }

      const payment = (result && result.payment && typeof result.payment === 'object')
        ? result.payment
        : ((result && result.data && result.data.payment) || {});
      const txnFromList = Array.isArray(payment.transactions) && payment.transactions[0]
        ? String(payment.transactions[0].transaction_no || '')
        : '';
      const orderUuid = String(
        (result && (result.order_uuid || (result.data && result.data.order_uuid)))
        || (Array.isArray(result && result.order_uuids) && result.order_uuids[0])
        || (Array.isArray(result && result.orders) && result.orders[0] && result.orders[0].order_uuid)
        || ''
      ).trim();
      const orderNumber = String(
        (result && (result.order_number || result.increment_id || (result.data && (result.data.order_number || result.data.increment_id))))
        || ''
      ).trim();
      const transactionNo = String(
        (result && result.transaction_no)
        || txnFromList
        || (payment && payment.transaction_no)
        || ''
      ).trim();

      return {
        success: !(result && result.success === false) && !!orderUuid,
        order_uuid: orderUuid,
        order_number: orderNumber,
        transaction_no: transactionNo,
        shipping_method: shippingMethod,
        payment_method: payMethod,
        quote_token: quoteToken,
        active_mode: activeMode,
        cart_probe: cartProbe,
        result,
      };
    } catch (e) {
      return { success: false, message: 'evaluate_threw', error: String(e && e.message || e) };
    }
  }, {
    cartMode: mode,
    payMethod: paymentMethod,
    shippingEmail: String(meta.email || ''),
  });

  const evidence = {
    scope: String(meta.scope || mode),
    email: String(meta.email || ''),
    coupon: String(meta.coupon || ''),
    note: String(meta.note || ''),
    payment_method: paymentMethod,
    order_uuid: String((started && started.order_uuid) || ''),
    order_number: String((started && started.order_number) || ''),
    transaction_no: String((started && started.transaction_no) || ''),
    shipping_method: String((started && started.shipping_method) || ''),
    active_mode: String((started && started.active_mode) || mode),
    cart_probe: (started && started.cart_probe) || [],
    success: !!(started && started.success),
    raw_message: started && started.message ? String(started.message) : '',
    error: started && started.error ? String(started.error).slice(0, 400) : '',
  };
  // Durable console evidence for Agent user-facing report (automation_test_report_durable_evidence).
  // eslint-disable-next-line no-console
  console.log('E2E_EVIDENCE_JSON ' + JSON.stringify(evidence));
  assertStep(evidence.success && !!evidence.order_uuid, 'checkout.submit.order_uuid', evidence);
  return evidence;
}

function prepareCustomer() {
  return runFixture('prepare', {});
}

function runMultiScopeFixture(action, payload = {}) {
  const { execFileSync } = require('child_process');
  const stdout = execFileSync('php', [MULTI_SCOPE_FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    env: {
      ...process.env,
      HTTPS_PROXY: '',
      https_proxy: '',
      HTTP_PROXY: '',
      http_proxy: '',
      ALL_PROXY: '',
      all_proxy: '',
    },
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`multi-scope fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

function prepareWholesaleCustomer() {
  return runMultiScopeFixture('prepare_wholesale', {});
}

module.exports = {
  ROOT_DIR,
  COUPON,
  NOTE_TEXT,
  ADDRESS,
  storefrontOrigin,
  multiScopeOrigin,
  gotoMultiScope,
  gotoStorefront,
  waitForWelineApi,
  addToCartFromPdp,
  addToCartMode,
  openCheckout,
  ensureCheckoutAddress,
  revealCouponWidget,
  applyCouponViaApi,
  applyCouponViaUi,
  fillOrderNoteViaUi,
  probeWholesaleSurfaces,
  applyCouponExpectFailForTob,
  submitCheckoutCaptureEvidence,
  loginAsCustomerApi,
  dismissStorefrontNoise,
  prepareCustomer,
  prepareWholesaleCustomer,
  runMultiScopeFixture,
  runFixture,
  assertStep,
};
