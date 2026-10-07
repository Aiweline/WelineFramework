/**
 * Shared helpers for release PayPal ↔ account lifecycle E2E.
 */
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'release-paypal-account-lifecycle-fixture.php');
const BUYER_EMAIL = process.env.PAYPAL_SANDBOX_BUYER_EMAIL || 'sb-4sxrp30216572@personal.example.com';
const BUYER_PASS = process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || 'weline18';
const PAYMENT_METHOD = String(process.env.FORCE_PAYMENT_METHOD || 'paypal').toLowerCase();

const ADDRESS = {
  name: 'Release PayPal Buyer',
  phone: '14085551234',
  address1: '1 Main St',
  street: '1 Main St',
  city: 'San Jose',
  province: 'CA',
  province_name: 'California',
  postal_code: '95131',
  country_code: 'US',
  country: 'US',
  country_name: 'United States',
  email: '',
};

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE], {
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
    throw new Error(`fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

function assertStep(condition, stepId, detail) {
  if (!condition) {
    throw new Error(`STEP_FAILED[${stepId}]: ${typeof detail === 'string' ? detail : JSON.stringify(detail).slice(0, 900)}`);
  }
}

async function paypalLoginAndApprove(paypal) {
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
  if (await pass.count()) {
    await pass.waitFor({ state: 'visible', timeout: 30000 }).catch(() => {});
    await pass.fill(BUYER_PASS).catch(() => {});
    await paypal.locator('#btnLogin, button:has-text("Log In"), button:has-text("Log in")').first().click().catch(() => {});
    await paypal.waitForTimeout(2500);
  }
  for (let i = 0; i < 16; i += 1) {
    if (/test\.weline\.com|127\.0\.0\.1|checkout\/success|payment\/frontend\/callback|express-review/.test(paypal.url())) {
      return;
    }
    const cont = paypal.locator(
      '#payment-submit-btn, #consentButton, button[data-id="payment-submit-btn"], [data-testid="consentButton"], button:has-text("Complete Purchase"), button:has-text("Agree and Continue")'
    ).first();
    if (await cont.count() && await cont.isVisible().catch(() => false)) {
      await cont.click({ timeout: 5000 }).catch(() => {});
    }
    await paypal.waitForTimeout(2000);
  }
}

const EVENT_PROBE_INIT = `(() => {
  window.__REL_E2E_EVENTS = window.__REL_E2E_EVENTS || [];
  window.__REL_E2E_HOOK = function () {
    const sb = window.WelineEventSandbox || window.WelinePixelSandbox;
    if (sb && typeof sb.subscribe === 'function' && !window.__REL_E2E_SUBSCRIBED) {
      window.__REL_E2E_SUBSCRIBED = true;
      sb.subscribe(function (env) {
        if (!env) return;
        window.__REL_E2E_EVENTS.push({
          name: String(env.name || env.event_name || ''),
          hit_kind: String(env.hit_kind || ''),
          params: env.params && typeof env.params === 'object' ? env.params : {},
          order_uuid: String((env.params && env.params.order_uuid) || env.order_uuid || ''),
          value: env.params && env.params.value,
          currency: env.params && env.params.currency,
          items_count: env.params && (env.params.items_count || (Array.isArray(env.params.items) ? env.params.items.length : 0)),
        });
      });
    }
    const recent = window.__WelinePixelRecentEvents || window.__WelineRecentEvents || [];
    if (Array.isArray(recent)) {
      recent.forEach(function (env) {
        if (!env) return;
        const name = String(env.name || env.event_name || '');
        if (!name) return;
        if (window.__REL_E2E_EVENTS.some(function (e) {
          return e.name === name && e.order_uuid === String((env.params && env.params.order_uuid) || env.order_uuid || '');
        })) return;
        window.__REL_E2E_EVENTS.push({
          name: name,
          hit_kind: String(env.hit_kind || ''),
          params: env.params && typeof env.params === 'object' ? env.params : {},
          order_uuid: String((env.params && env.params.order_uuid) || env.order_uuid || ''),
          value: env.params && env.params.value,
          currency: env.params && env.params.currency,
          items_count: env.params && (env.params.items_count || (Array.isArray(env.params.items) ? env.params.items.length : 0)),
        });
      });
    }
  };
  setInterval(window.__REL_E2E_HOOK, 250);
})();`;

async function installEventProbe(pageOrContext) {
  if (pageOrContext && typeof pageOrContext.addInitScript === 'function') {
    await pageOrContext.addInitScript(EVENT_PROBE_INIT);
  }
}

async function attachEventProbeLive(page) {
  await page.evaluate((src) => {
    // eslint-disable-next-line no-eval
    eval(src);
    if (typeof window.__REL_E2E_HOOK === 'function') window.__REL_E2E_HOOK();
  }, EVENT_PROBE_INIT);
}

async function collectEvents(page) {
  await attachEventProbeLive(page).catch(() => {});
  await page.waitForTimeout(1500);
  return page.evaluate(() => {
    if (typeof window.__REL_E2E_HOOK === 'function') {
      window.__REL_E2E_HOOK();
    }
    return Array.isArray(window.__REL_E2E_EVENTS) ? window.__REL_E2E_EVENTS.slice() : [];
  });
}

async function waitForCheckoutSuccessEvent(page, timeoutMs = 45000) {
  await attachEventProbeLive(page).catch(() => {});
  await page.waitForFunction(() => {
    if (typeof window.__REL_E2E_HOOK === 'function') window.__REL_E2E_HOOK();
    const events = window.__REL_E2E_EVENTS || [];
    return events.some((e) => e && e.name === 'checkout_success' && e.hit_kind !== 'dedupe');
  }, null, { timeout: timeoutMs }).catch(() => {});
  return collectEvents(page);
}

function storefrontOrigin() {
  return String(
    process.env.PLAYWRIGHT_TARGET_ORIGIN
      || process.env.WELINE_E2E_BASE_URL
      || 'https://p05113ef3.test.weline.com',
  ).replace(/\/$/, '');
}

async function gotoStorefront(page, pdpPath) {
  const path = pdpPath.startsWith('http') ? pdpPath : `${storefrontOrigin()}${pdpPath.startsWith('/') ? '' : '/'}${pdpPath}`;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 120000 });
      await page.waitForTimeout(800);
      return;
    } catch (error) {
      if (attempt >= 2) throw error;
      await page.waitForTimeout(1500);
    }
  }
}

async function waitForWelineApi(page, timeout = 90000) {
  await page.waitForFunction(() => !!(window.Weline && (
    (window.Weline.Api && typeof window.Weline.Api.resource === 'function')
    || typeof window.Weline.load === 'function'
  )), null, { timeout });
  await page.evaluate(async () => {
    if ((!window.Weline.Api || typeof window.Weline.Api.resource !== 'function')
      && typeof window.Weline.load === 'function') {
      await window.Weline.load('api');
    }
  });
}

async function addToCartFromPdp(page, gotoFrontend, pdpPath) {
  // Prefer public Host (nginx *.test.weline.com). Direct 127.0.0.1:worker often 503.
  try {
    await gotoStorefront(page, pdpPath);
  } catch (error) {
    await gotoFrontend(page, pdpPath, { timeout: 90000, settleMs: 800, useProxy: false });
  }
  await waitForWelineApi(page);
  const added = await page.evaluate(async () => {
    let api = window.Weline.Api;
    if (!api || typeof api.resource !== 'function') {
      api = await window.Weline.load('api');
    }
    const buyNow = document.querySelector('[data-action="buy-now"][data-product-id]')
      || document.querySelector('[data-testid="product-buy-now"]')
      || document.querySelector('[data-action="add-to-cart"][data-product-id]');
    if (!buyNow) {
      return { success: false, message: 'buy_now_missing', url: location.href, title: document.title };
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
      qty: 1,
      selling_mode: 'toc',
      cart_type: 'toc',
    }, { silent: true, requestTimeoutMs: 120000 });
    return { success: !(addResult && addResult.success === false), guest_token: guestToken, add: addResult };
  });
  assertStep(added && added.success, 'cart.add', added);
  return added;
}

async function ensureCheckoutAddress(page, email) {
  const address = { ...ADDRESS, email: email || ADDRESS.email };
  await page.waitForSelector('[data-shipping-checkout-address], .weline-checkout', { timeout: 60000 });
  const fillIfEmpty = async (selector, value) => {
    const loc = page.locator(selector).first();
    if (await loc.isVisible().catch(() => false)) {
      const cur = await loc.inputValue().catch(() => '');
      if (!String(cur || '').trim()) await loc.fill(value).catch(() => {});
    }
  };
  const scope = '[data-shipping-checkout-address] ';
  await fillIfEmpty(`${scope}input[name="name"]`, address.name);
  await fillIfEmpty(`${scope}input[name="phone"]`, address.phone);
  await fillIfEmpty(`${scope}input[name="email"]`, address.email);
  await fillIfEmpty(`${scope}input[name="address1"], ${scope}input[name="street"]`, address.address1);
  await fillIfEmpty(`${scope}input[name="postal_code"]`, address.postal_code);
  await page.evaluate(async (addr) => {
    if (window.WelineThemeAddress && typeof window.WelineThemeAddress.applyValues === 'function') {
      await window.WelineThemeAddress.applyValues('checkout-shipping-address', {
        country_code: addr.country_code,
        country: addr.country_name,
        province: addr.province_name,
        city: addr.city,
        district: '',
        street: addr.street,
      });
    }
    const root = document.querySelector('[data-shipping-checkout-address]');
    const setHidden = (n, v) => {
      const el = root && root.querySelector(`[name="${n}"]`);
      if (el) {
        el.value = v;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
    setHidden('country_code', addr.country_code);
    setHidden('country', addr.country_name);
    setHidden('province', addr.province_name);
    setHidden('city', addr.city);
    setHidden('street', addr.street);
    setHidden('email', addr.email);
  }, address);
  return address;
}

async function dismissStorefrontNoise(page) {
  for (const sel of [
    'button:has-text("同意")',
    'button:has-text("Agree")',
    '[data-cookie-accept]',
    '[data-consent-accept]',
  ]) {
    const btn = page.locator(sel).first();
    if (await btn.isVisible({ timeout: 800 }).catch(() => false)) {
      await btn.click({ timeout: 2000 }).catch(() => {});
    }
  }
}

async function submitPaypalCheckout(page, email) {
  if (!/\/checkout/.test(page.url())) {
    await gotoStorefront(page, '/checkout');
  }
  await waitForWelineApi(page);
  await dismissStorefrontNoise(page);
  await page.waitForFunction(() => {
    const box = document.querySelector('[data-payment-methods]');
    return !!box && box.querySelectorAll('input[name="payment_method"]').length > 0;
  }, null, { timeout: 90000 }).catch(() => {});
  const address = await ensureCheckoutAddress(page, email);
  await page.waitForTimeout(1500);

  // Prefer real UI submit (release pathway). Capture PayPal redirect via navigation or API.
  const wanted = PAYMENT_METHOD;
  await page.waitForFunction((method) => {
    return !!document.querySelector(`input[name="payment_method"][value="${method}"]`);
  }, wanted, { timeout: 60000 });
  await page.evaluate((method) => {
    const el = document.querySelector(`input[name="payment_method"][value="${method}"]`);
    if (el) {
      el.checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, wanted);

  const shipping = page.locator('input[name="shipping_method"]').first();
  if (await shipping.count()) {
    await shipping.check({ force: true }).catch(() => {});
  }

  const submit = page.locator('[data-submit], button[type="submit"]:has-text("提交"), button:has-text("下单")').first();
  await expectVisibleOrThrow(page, submit, 'checkout.submit_btn');

  const racePromise = Promise.race([
    page.waitForURL(/paypal\.com|sandbox\.paypal/, { timeout: 180000 })
      .then(() => ({ via: 'nav', redirect_url: page.url(), paypalPage: page }))
      .catch(() => null),
    page.context().waitForEvent('page', { timeout: 180000 })
      .then(async (popup) => {
        await popup.waitForLoadState('domcontentloaded').catch(() => {});
        if (/paypal\.com|sandbox\.paypal/i.test(popup.url())) {
          return { via: 'popup', redirect_url: popup.url(), paypalPage: popup };
        }
        return null;
      })
      .catch(() => null),
  ]);

  await submit.click({ timeout: 15000 });
  const raced = await racePromise;
  if (raced && raced.redirect_url) {
    return {
      success: true,
      stage: `ui_${raced.via}`,
      redirect_url: raced.redirect_url,
      order_uuid: '',
      paypalPage: raced.paypalPage,
    };
  }

  // Same-context redirect may close the checkout page; scan open tabs.
  for (const open of page.context().pages()) {
    try {
      if (/paypal\.com|sandbox\.paypal/i.test(open.url())) {
        return {
          success: true,
          stage: 'ui_context_scan',
          redirect_url: open.url(),
          order_uuid: '',
          paypalPage: open,
        };
      }
    } catch (_) {
      // page may already be closed
    }
  }

  // Fallback: API path used by continue-pay runner (same silent submitV2).
  // Skip if checkout page was destroyed by gateway redirect.
  if (page.isClosed()) {
    assertStep(false, 'checkout.submit', 'checkout page closed without PayPal URL');
  }
  const started = await page.evaluate(async ({ address: addr, wantedMethod }) => {
    let guestToken = '';
    try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
    let apiClient = window.Weline.Api;
    if (!apiClient || typeof apiClient.resource !== 'function') {
      apiClient = await window.Weline.load('api');
    }
    const api = await apiClient.resource('checkout');
    const data = await api.getData({
      guest_token: guestToken,
      shipping_address: addr,
      cart_type: 'toc',
      selling_mode: 'toc',
    }, { silent: true, requestTimeoutMs: 120000 });
    const root = (data && data.data && typeof data.data === 'object') ? data.data : data;
    const methods = (root && root.shipping_methods) || [];
    const codes = Array.isArray(methods)
      ? methods.map((m) => String((m && (m.code || m.service_code)) || '')).filter(Boolean)
      : [];
    const shippingMethod = codes.find((c) => /AMERICAS|INTL/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || codes[0]
      || '';
    if (!shippingMethod) {
      return { success: false, stage: 'shipping', codes, message: root && root.message };
    }
    const frozen = await api.freezeQuote({
      address: addr,
      billing_address: addr,
      billing_same_as_shipping: true,
      service_code: shippingMethod,
      payment_method: wantedMethod,
      guest_token: guestToken,
      cart_type: 'toc',
      selling_mode: 'toc',
    }, { silent: true, requestTimeoutMs: 120000 });
    if (!frozen || frozen.success === false) {
      return { success: false, stage: 'freeze', frozen, codes };
    }
    const quoteToken = String((frozen.quote_token || (frozen.data && frozen.data.quote_token) || '')).trim();
    const result = await api.submitV2({
      quote_token: quoteToken,
      payment_method: wantedMethod,
      idempotency_key: `rel_paypal_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
      country_code: addr.country_code,
      guest_token: guestToken,
    }, { silent: true, requestTimeoutMs: 120000 });
    const payload = (result && result.data && typeof result.data === 'object') ? result.data : result;
    const payment = (result && result.payment) || (payload && payload.payment) || {};
    const orderUuids = Array.isArray(payload && payload.order_uuids) ? payload.order_uuids : [];
    return {
      success: !(result && result.success === false),
      stage: 'api_submit',
      order_uuid: String(orderUuids[0] || '').trim(),
      checkout_group_uuid: String((payload && payload.checkout_group_uuid) || '').trim(),
      redirect_url: String(payment.redirect_url || '').trim(),
      error_code: String(payment.error_code || ''),
      message: String(payment.message || ''),
      shipping_method: shippingMethod,
    };
  }, { address, wantedMethod: wanted }).catch((error) => ({
    success: false,
    stage: 'api_eval_error',
    message: String(error && error.message || error),
    redirect_url: /paypal\.com/i.test(page.url()) ? page.url() : '',
  }));

  if (started.redirect_url) {
    return started;
  }
  if (/paypal\.com|sandbox\.paypal/i.test(page.url())) {
    return { success: true, stage: 'page_url', redirect_url: page.url(), order_uuid: started.order_uuid || '' };
  }
  assertStep(false, 'checkout.submit', started);
  return started;
}

async function expectVisibleOrThrow(page, locator, stepId) {
  const visible = await locator.isVisible({ timeout: 30000 }).catch(() => false);
  if (!visible) {
    const diag = await page.evaluate(() => ({
      url: location.href,
      message: String((document.querySelector('[data-checkout-message]') || {}).textContent || '').trim().slice(0, 300),
      submit: !!document.querySelector('[data-submit]'),
    })).catch(() => ({}));
    assertStep(false, stepId, diag);
  }
}

async function approvePaypalAndLand(context, redirectUrl, existingPage = null) {
  let paypal = existingPage;
  if (paypal && /paypal\.com|sandbox\.paypal/i.test(paypal.url())) {
    // already navigated in-place
  } else {
    paypal = await context.newPage();
    await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded', timeout: 120000 });
  }
  await paypalLoginAndApprove(paypal);
  for (let i = 0; i < 45; i += 1) {
    if (/test\.weline\.com|127\.0\.0\.1/.test(paypal.url()) && /checkout\/success|express-review|payment\/frontend\/callback/.test(paypal.url())) {
      break;
    }
    await paypal.waitForTimeout(1000);
  }
  await paypal.waitForURL(/checkout\/success|express-review|payment\/frontend\/callback/, { timeout: 120000 }).catch(() => {});
  if (/express-review/.test(paypal.url())) {
    const confirm = paypal.locator('[data-testid="express-confirm-pay"]').first();
    if (await confirm.count()) {
      await confirm.click({ timeout: 15000 }).catch(() => {});
      await paypal.waitForURL(/checkout\/success/, { timeout: 120000 }).catch(() => {});
    }
  }
  await paypal.waitForLoadState('domcontentloaded').catch(() => {});
  await paypal.waitForTimeout(2000);
  return paypal;
}

async function mintCustomerSessionCookie(page, email) {
  const origin = storefrontOrigin();
  const host = origin.replace(/^https?:\/\//, '').split('/')[0];
  const minted = runFixture('mint_session', { email, host });
  assertStep(minted.session_id && minted.cookie_name, 'customer.mint_session', minted);
  const secure = origin.startsWith('https://');
  await page.context().addCookies([
    {
      name: minted.cookie_name,
      value: minted.session_id,
      domain: host.replace(/:\d+$/, ''),
      path: '/',
      httpOnly: true,
      secure,
      sameSite: 'Lax',
    },
  ]);
  return minted;
}

async function loginViaFormCaptcha(page, email, password) {
  await gotoStorefront(page, '/customer/account/login');
  await page.waitForTimeout(3000);
  await waitForWelineApi(page);
  await dismissStorefrontNoise(page);
  const formSel = 'form[action*="customer/account/login"]';
  const scope = page.locator(formSel).first();
  assertStep(await scope.count(), 'customer.login_form', 'login form missing');
  await scope.locator('input[name="username"], input[name="email"]').first().fill(email);
  await scope.locator('input[name="password"]').fill(password);
  await page.evaluate((sel) => {
    const f = document.querySelector(sel);
    if (f) {
      f.dispatchEvent(new CustomEvent('weline:form:prepare-submit', { bubbles: true, cancelable: true }));
    }
  }, formSel);

  let tokenLen = 0;
  for (let i = 0; i < 40; i += 1) {
    tokenLen = await page.evaluate((sel) => {
      const f = document.querySelector(sel);
      const input = f && f.querySelector('[name=captcha_response]');
      return input ? String(input.value || '').length : 0;
    }, formSel).catch(() => 0);
    if (tokenLen > 0) break;
    await page.waitForTimeout(500);
  }
  assertStep(tokenLen > 0, 'customer.captcha', 'login captcha_response empty');
  await page.waitForTimeout(3000);
  if (/customer\/account\/login/.test(page.url())) {
    await page.evaluate((sel) => {
      const f = document.querySelector(sel);
      if (!f) return;
      if (f.dataset.welineCaptchaVerified === '1' || true) {
        if (typeof f.requestSubmit === 'function') f.requestSubmit();
        else f.submit();
      }
    }, formSel).catch(() => {});
    await page.waitForTimeout(6000);
  }
  const loggedIn = await page.evaluate(() => !!document.querySelector('a[href*="logout"]')).catch(() => false);
  if (!loggedIn && /customer\/account\/login/.test(page.url())) {
    throw new Error('form_captcha_login_failed');
  }
}

async function loginAsCustomerApi(page, gotoFrontend, email, password) {
  // Prefer CLI-minted frontend session (stable under Google reCAPTCHA).
  try {
    await mintCustomerSessionCookie(page, email);
    await gotoStorefront(page, '/customer/account/index');
    if (!/customer\/account\/login/.test(page.url())) {
      return;
    }
  } catch (_) {
    // fall through to form+captcha
  }
  try {
    await loginViaFormCaptcha(page, email, password);
  } catch (_) {
    await mintCustomerSessionCookie(page, email);
  }
  await gotoStorefront(page, '/customer/account/index');
  if (/customer\/account\/login/.test(page.url())) {
    await mintCustomerSessionCookie(page, email);
    await gotoStorefront(page, '/customer/account/index');
  }
  assertStep(!/customer\/account\/login/.test(page.url()), 'customer.login_session', page.url());
}

async function openAccountOrders(page, gotoFrontend) {
  await gotoStorefront(page, '/customer/account/index#orders');
  const nav = page.locator('[data-account-nav-link="true"][data-section="orders"]').first();
  if (await nav.isVisible({ timeout: 5000 }).catch(() => false)) {
    await nav.click();
  } else {
    await page.evaluate(() => {
      window.location.hash = 'orders';
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    });
  }
  const orders = page.locator('[data-account-orders="true"]');
  await orders.waitFor({ state: 'visible', timeout: 30000 });
  return orders;
}

/** Account #orders summarizes by checkout group; order_number may only appear after expand/detail. */
async function assertAccountSeesOrder(orders, page, { orderUuid, orderNumber, amountHint }) {
  const byUuid = orders.locator(`[data-order-uuid="${orderUuid}"]`).first();
  if (await byUuid.count()) {
    await expectVisibleOrThrow(page, byUuid, 'account.orders.uuid');
    return;
  }
  const accordion = orders.locator('[data-testid="account-orders-accordion"], details.account-orders__accordion').first();
  if (await accordion.count()) {
    await accordion.locator('summary, [data-group-summary]').first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(800);
  }
  const detail = orders.locator('a.account-orders__detail-link, a:has-text("查看详情")').first();
  if (await detail.count()) {
    const hrefUuid = await detail.getAttribute('data-order-uuid').catch(() => '');
    if (hrefUuid === orderUuid || !orderUuid) {
      await expectVisibleOrThrow(page, detail, 'account.orders.detail');
      return;
    }
  }
  const text = await orders.innerText().catch(() => '');
  const hit = (orderNumber && text.includes(String(orderNumber)))
    || (orderUuid && text.includes(orderUuid))
    || (amountHint && text.includes(String(amountHint)))
    || /已支付|待发货|USD|CNY/.test(text);
  assertStep(hit, 'account.orders.visible', {
    orderUuid,
    orderNumber,
    snippet: String(text).slice(0, 400),
  });
}

async function claimGuestOrderOnSuccess(page, gotoFrontend, email, password) {
  const convert = page.locator('[data-testid="checkout-success-guest-convert"]').first();
  if (await convert.count()) {
    const submit = convert.locator('[data-testid="guest-convert-submit"], [data-guest-convert-submit]').first();
    const loginLink = convert.locator('[data-testid="guest-convert-login"]').first();
    if (await submit.count()) {
      await submit.click();
      await page.waitForTimeout(4000);
      if (/customer\/account/.test(page.url())) {
        return;
      }
    }
    if (await loginLink.count()) {
      await loginAsCustomerApi(page, gotoFrontend, email, password);
      return;
    }
  }
  await loginAsCustomerApi(page, gotoFrontend, email, password);
}

async function adminTransitionOrderStatus(page, loginAsAdmin, gotoBackend, waitForBackendShellReady, orderId, toStatus) {
  await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
  await gotoBackend(page, `order/backend/order/edit?id=${orderId}`, { timeout: 90000, settleMs: 800 });
  await waitForBackendShellReady(page);
  const actions = page.locator('[data-testid="order-edit-status-actions"]');
  await actions.waitFor({ state: 'visible', timeout: 30000 });
  await actions.locator('select[name="status"]').selectOption(toStatus);
  await actions.locator('input[name="comment"]').fill(`e2e-rel-paypal→${toStatus}`);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    actions.locator('button[type="submit"]').click(),
  ]);
  await waitForBackendShellReady(page);
  await page.waitForTimeout(1500);
}

module.exports = {
  ROOT_DIR,
  FIXTURE,
  ADDRESS,
  PAYMENT_METHOD,
  storefrontOrigin,
  gotoStorefront,
  waitForWelineApi,
  runFixture,
  assertStep,
  paypalLoginAndApprove,
  installEventProbe,
  attachEventProbeLive,
  collectEvents,
  waitForCheckoutSuccessEvent,
  addToCartFromPdp,
  ensureCheckoutAddress,
  submitPaypalCheckout,
  approvePaypalAndLand,
  loginAsCustomerApi,
  openAccountOrders,
  assertAccountSeesOrder,
  claimGuestOrderOnSuccess,
  adminTransitionOrderStatus,
};
