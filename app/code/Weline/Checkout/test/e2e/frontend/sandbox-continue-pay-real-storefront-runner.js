/**
 * 场景 C · 真实通路：支付取消 → 继续支付（PayPal 沙箱 · 真浏览器 · 真 DB 核验）
 *
 * 覆盖两条用户可见的「继续支付」入口：
 *   - CPAY_MODE=guest   （默认）取消落地页上的 [data-continue-pay] CTA
 *   - CPAY_MODE=account        登录顾客在 /customer/account/index#orders 的账户订单 CTA
 *   - CPAY_MODE=both           先跑 guest 再跑 account
 *
 * 与旧版（会假通过）的区别：
 *   1. 全程只用 paypal，**禁止 fake_card** 制造「支付成功」；
 *   2. 取消是在 PayPal 审批页**真实点击**「Cancel and return to …」，不是调 API 伪造；
 *   3. 每一步关键状态都经 fixture 的 verify_order_state **直读数据库**核验，
 *      不做「自造 fixture → 断言自造数据」的循环通过；
 *   4. 任何一步不满足预期立即抛错并带上服务端提示，绝不静默产出「看起来通过」的结果。
 *
 * Usage:
 *   FORCE_PAYMENT_METHOD=paypal PLAYWRIGHT_HEADLESS=1 \
 *     node app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js
 */
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const ROOT = path.resolve(__dirname, '../../../../../../..');
const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.CHECKOUT_PDP_PATH
  || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
const CPAY_FIXTURE = path.resolve(__dirname, 'checkout-continue-pay-real-pathway-fixture.php');

const BUYER_EMAIL = process.env.PAYPAL_SANDBOX_BUYER_EMAIL || 'sb-4sxrp30216572@personal.example.com';
const BUYER_PASS = process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || 'weline18';
const CUSTOMER_EMAIL = process.env.CPAY_CUSTOMER_EMAIL || 'e2e.customer@weline.local';
const CUSTOMER_PASS = process.env.CPAY_CUSTOMER_PASSWORD || 'E2eTest!234';

const MODE = String(process.env.CPAY_MODE || 'guest').toLowerCase();
const PAYMENT_METHOD = String(process.env.FORCE_PAYMENT_METHOD || 'paypal').toLowerCase();

const ADDRESS = {
  name: 'Sandbox Buyer',
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
};

/** 读库核验（只读动作，不写任何状态） */
function verifyOrderState(orderUuid) {
  const stdout = execFileSync('php', [CPAY_FIXTURE], {
    cwd: ROOT,
    input: JSON.stringify({ action: 'verify_order_state', order_uuid: orderUuid }),
    encoding: 'utf8',
    env: { ...process.env, HTTPS_PROXY: '', https_proxy: '', HTTP_PROXY: '', http_proxy: '', ALL_PROXY: '', all_proxy: '' },
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const result = JSON.parse(lines[lines.length - 1] || '{}');
  if (!result.ok) {
    throw new Error(`verify_order_state 失败: ${JSON.stringify(result).slice(0, 600)}`);
  }
  return result.data;
}

/** 断言辅助：失败即抛错并带上完整现场，不静默 */
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
  for (let i = 0; i < 12; i += 1) {
    if (/test\.weline\.com|127\.0\.0\.1|checkout\/success|payment\/frontend\/callback/.test(paypal.url())) {
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

/** 只登录，不批准（用于随后真实点「取消」） */
async function paypalLoginOnly(paypal) {
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
    await paypal.waitForTimeout(3000);
  }
  // 等审批页真正就绪（出现「Complete Purchase」或取消链接），此时才允许点取消
  await paypal.locator(
    'button:has-text("Complete Purchase"), #payment-submit-btn, a:has-text("Cancel and return to")'
  ).first().waitFor({ state: 'visible', timeout: 60000 }).catch(() => {});
}

/**
 * 真实点击「取消」。登录前后形态不同：
 *  - 未登录：a#cancelLink（带 href，指向 callback?outcome=cancel…）
 *  - 已登录：同文案但 href="#"（class CancelLink_cancelLink_*）→ 必须按文案/类名点
 */
async function paypalClickCancel(paypal) {
  const candidates = [
    paypal.locator('a:has-text("Cancel and return to")').first(),
    paypal.locator('[class*="CancelLink_cancelLink"]').first(),
    paypal.locator('a#cancelLink').first(),
    paypal.getByRole('link', { name: /cancel and return/i }).first(),
  ];
  let clicked = false;
  let used = '';
  for (let i = 0; i < candidates.length; i += 1) {
    const loc = candidates[i];
    if (await loc.count() && await loc.isVisible().catch(() => false)) {
      await loc.click({ timeout: 10000 }).catch(() => {});
      clicked = true;
      used = `candidate#${i}`;
      break;
    }
  }
  assertStep(clicked, 'C2', 'PayPal 审批页未找到可见的「Cancel and return to …」取消入口');
  return used;
}

async function addToCart(page) {
  await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  const added = await page.evaluate(async () => {
    const buyNow = document.querySelector('[data-action="buy-now"][data-product-id]')
      || document.querySelector('[data-testid="product-buy-now"]')
      || document.querySelector('[data-action="add-to-cart"][data-product-id]');
    if (!buyNow) return { success: false, message: 'buy_now_missing' };
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
    const cartApi = await window.Weline.Api.resource('cart');
    if (!guestToken) {
      const issued = await cartApi.issueGuestToken({}, { silent: true });
      const payload = issued && issued.data && typeof issued.data === 'object' ? issued.data : issued;
      guestToken = String((payload && payload.guest_token) || (issued && issued.guest_token) || '').trim();
      if (guestToken) { try { sessionStorage.setItem('weline.cart.guest_token', guestToken); } catch (e2) {} }
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
  assertStep(added && added.success, 'C1.cart', added);
}

/**
 * 补全收货地址。
 * 注意：`country_code` 不是普通可见 input，而是**隐藏的级联字段**（由 Theme 地址级联托管）
 * —— 只填可见 input 会让 country_code 落回默认 `CN`，得到一个「US 省 + CN 国」的矛盾地址，
 * amend 会返回 200 但后续 resume 走不通。故必须：
 *   1) 用 window.WelineThemeAddress.applyValues() 驱动级联（照搬 HelpPay runner 已验证写法）；
 *   2) 再把隐藏字段显式写值并派发 change；
 *   3) 用部件自身的 resolveQuoteAddress/validateShippingFields 复核，不通过就显式抛错。
 */
async function ensureAddress(page) {
  const scope = '[data-shipping-checkout-address] ';
  const fillIfEmpty = async (selector, value) => {
    const loc = page.locator(selector).first();
    if (await loc.isVisible().catch(() => false)) {
      const cur = await loc.inputValue().catch(() => '');
      if (!String(cur || '').trim()) await loc.fill(value).catch(() => {});
    }
  };
  await fillIfEmpty(`${scope}input[name="name"]`, ADDRESS.name);
  await fillIfEmpty(`${scope}input[name="phone"]`, ADDRESS.phone);
  await fillIfEmpty(`${scope}input[name="address1"], ${scope}input[name="street"]`, ADDRESS.address1);
  await fillIfEmpty(`${scope}input[name="postal_code"]`, ADDRESS.postal_code);

  const applied = await page.evaluate(async (address) => {
    if (window.WelineThemeAddress && typeof window.WelineThemeAddress.applyValues === 'function') {
      await window.WelineThemeAddress.applyValues('checkout-shipping-address', {
        country_code: address.country_code,
        country: address.country_name,
        province: address.province_name,
        city: address.city,
        district: '',
        street: address.street,
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
    setHidden('country_code', address.country_code);
    setHidden('country', address.country_name);
    setHidden('province', address.province_name);
    setHidden('city', address.city);
    setHidden('street', address.street);
    const api = window.WelineShippingCheckoutAddress;
    const resolved = api && api.resolveQuoteAddress ? api.resolveQuoteAddress() : null;
    const valid = api && api.validateShippingFields ? await api.validateShippingFields(resolved || {}) : true;
    return { valid, resolved };
  }, ADDRESS);

  assertStep(applied && applied.valid !== false, 'C5.address_valid', applied);
  assertStep(
    applied.resolved && String(applied.resolved.country_code).toUpperCase() === ADDRESS.country_code,
    'C5.address_country',
    applied,
  );
  return applied;
}

/**
 * 续付视图显式选中**真实网关**支付方式（默认 paypal）。
 *
 * 背景（本轮实测）：续付视图的支付单选由报价后 JS 注入，默认选中项是列表首项；
 * 本机环境首项是本地测试通道 `fake_card`，它**立即返回 paid**（无网关跳转）。
 * 页面提交时取 `selectedValue('payment_method')` 优先于恢复契约里的 payment_method，
 * 因此若不显式切换，提交会落进本地通道并制造「假成功」——必须禁止。
 *
 * 这里模拟真实用户动作：选中 paypal，并复核当前选中值，不通过就显式失败。
 */
async function pickGatewayPaymentMethod(page, phase) {
  const wanted = PAYMENT_METHOD;
  const deadline = Date.now() + 60000;
  let options = [];
  while (Date.now() < deadline) {
    options = await page.evaluate(() => Array.from(
      document.querySelectorAll('input[name="payment_method"]'),
    ).map((el) => ({ value: String(el.value || ''), disabled: !!el.disabled }))).catch(() => []);
    if (options.length > 0) break;
    await page.waitForTimeout(1000);
  }
  assertStep(options.length > 0, `${phase}.C6.payment_methods_missing`, options);

  const hasWanted = options.some((o) => o.value === wanted && !o.disabled);
  assertStep(hasWanted, `${phase}.C6.gateway_method_absent`, { wanted, options });

  await page.evaluate((value) => {
    const el = document.querySelector(`input[name="payment_method"][value="${value}"]`);
    if (el) {
      el.checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, wanted);
  await page.waitForTimeout(1500);

  const selected = await page.evaluate(() => {
    const el = document.querySelector('input[name="payment_method"]:checked');
    return el ? String(el.value || '') : '';
  });
  assertStep(selected === wanted, `${phase}.C6.gateway_method_not_selected`, { selected, options });
  return { wanted, selected, options };
}

/** 提交失败时的现场快照（用于把「点了没反应」翻译成可定位的原因） */
async function submitDiagnostics(page) {
  return await page.evaluate(() => {
    const root = document.querySelector('.weline-checkout') || document;
    const msg = document.querySelector('[data-checkout-message]');
    const submit = document.querySelector('[data-submit]');
    const errEls = Array.from(document.querySelectorAll('[data-field-error], .weline-checkout__field-error'))
      .map((el) => String(el.textContent || '').trim()).filter(Boolean).slice(0, 6);
    return {
      url: location.href,
      view: root.getAttribute ? root.getAttribute('data-checkout-view') : '',
      address_incomplete: root.getAttribute ? root.getAttribute('data-address-incomplete') : '',
      payment_mode: root.getAttribute ? root.getAttribute('data-checkout-payment-mode') : '',
      message: String((msg && msg.textContent) || '').trim().slice(0, 300),
      submit_disabled: !!submit && submit.disabled,
      field_errors: errEls,
    };
  }).catch(() => ({}));
}

/** 登录顾客：必须经部件预期的 weline:form:prepare-submit 触发 reCAPTCHA，再等 token 落进表单 */
async function loginCustomer(page) {
  await page.goto(FRONTEND + '/customer/account/login', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  const formSel = 'form[action*="customer/account/login"]';
  const scope = page.locator(formSel).first();
  assertStep(await scope.count(), 'C0.login', '未找到登录表单');
  await scope.locator('input[name="username"]').fill(CUSTOMER_EMAIL);
  await scope.locator('input[name="password"]').fill(CUSTOMER_PASS);
  await page.evaluate((sel) => {
    const f = document.querySelector(sel);
    f.dispatchEvent(new CustomEvent('weline:form:prepare-submit', { bubbles: true, cancelable: true }));
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
  assertStep(tokenLen > 0, 'C0.captcha', '登录人机验证未产出 token（captcha_response 为空）');

  await page.waitForTimeout(3000);
  if (/customer\/account\/login/.test(page.url())) {
    await page.evaluate((sel) => {
      const f = document.querySelector(sel);
      if (f && f.dataset.welineCaptchaVerified === '1') {
        if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
      }
    }, formSel).catch(() => {});
    await page.waitForTimeout(6000);
  }
  const loggedIn = await page.evaluate(() => !!document.querySelector('a[href*="logout"]')).catch(() => false);
  assertStep(loggedIn, 'C0.login', `顾客登录未成功，当前 URL=${page.url()}`);
}

/** 结算：加购 → 选 paypal → freezeQuote + submitV2，拿到真实网关跳转 */
async function submitPaypalOrder(page, phase) {
  await page.goto(FRONTEND + '/checkout', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  // 支付单选是报价后由 JS 注入的；全量重启后首个请求要付冷启动成本，故等容器真带选项
  await page.waitForFunction(() => {
    const box = document.querySelector('[data-payment-methods]');
    return !!box && box.querySelectorAll('input[name="payment_method"]').length > 0;
  }, null, { timeout: 90000 }).catch(() => {});
  await page.waitForTimeout(1500);

  const started = await page.evaluate(async ({ address, wantedMethod }) => {
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
    let shippingMethod = codes.find((c) => /AMERICAS|INTL/i.test(c))
      || codes.find((c) => c && !/DOMESTIC/i.test(c))
      || codes[0]
      || 'SEED_LANE_AMERICAS';
    if (/DOMESTIC/i.test(shippingMethod) && codes.some((c) => !/DOMESTIC/i.test(c))) {
      shippingMethod = codes.find((c) => !/DOMESTIC/i.test(c)) || shippingMethod;
    }
    const paymentMethod = wantedMethod;
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
    const idempotencyKey = `cpay_real_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    const result = await api.submitV2({
      quote_token: quoteToken,
      payment_method: paymentMethod,
      idempotency_key: idempotencyKey,
      country_code: address.country_code,
      guest_token: guestToken,
    }, { silent: true, requestTimeoutMs: 120000 });
    const payload = (result && result.data && typeof result.data === 'object') ? result.data : result;
    const payment = (result && result.payment) || (payload && payload.payment) || {};
    const orderUuids = Array.isArray(payload && payload.order_uuids) ? payload.order_uuids : [];
    return {
      success: !(result && result.success === false),
      stage: 'submit',
      quote_token: quoteToken,
      idempotency_key: idempotencyKey,
      shipping_method: shippingMethod,
      payment_method: paymentMethod,
      order_uuid: String(orderUuids[0] || '').trim(),
      checkout_group_uuid: String((payload && payload.checkout_group_uuid) || '').trim(),
      redirect_url: String(payment.redirect_url || '').trim(),
      error_code: String(payment.error_code || ''),
      message: String(payment.message || ''),
      raw: result,
    };
  }, { address: ADDRESS, wantedMethod: PAYMENT_METHOD });

  assertStep(started && started.success, `${phase}.submit`, started);
  assertStep(started.redirect_url, `${phase}.redirect`, `未拿到网关跳转（error_code=${started.error_code} message=${started.message}）`);
  assertStep(!!started.order_uuid, `${phase}.order_uuid`, started);
  return started;
}

/** 打开 PayPal 审批页 → 登录买家 → 真实点取消 → 回到站点取消落地页 */
async function cancelAtPaypal(context, redirectUrl, evidence) {
  const paypal = await context.newPage();
  await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded' });
  evidence.paypal_approve_url = redirectUrl;
  evidence.steps.push('C1.paypal_opened');
  await paypalLoginOnly(paypal);
  evidence.steps.push('C2.paypal_logged_in');

  const clickedBy = await paypalClickCancel(paypal);
  evidence.cancel_clicked_by = clickedBy;
  evidence.steps.push('C2.cancel_clicked');

  // 离开 PayPal → 落站点取消页
  for (let i = 0; i < 40; i += 1) {
    if (/test\.weline\.com/.test(paypal.url())) break;
    await paypal.waitForTimeout(1000);
  }
  await paypal.waitForURL(/test\.weline\.com/, { timeout: 90000 }).catch(() => {});
  if (!/checkout\/(success|failure)/.test(paypal.url())) {
    await paypal.waitForURL(/checkout\/(success|failure)/, { timeout: 60000 }).catch(() => {});
  }
  await paypal.waitForLoadState('domcontentloaded').catch(() => {});
  await paypal.waitForTimeout(2500);

  evidence.cancel_landing_url = paypal.url();
  const landing = await paypal.evaluate(() => {
    const outcomeEl = document.querySelector('[data-payment-outcome]');
    const cta = document.querySelector('[data-continue-pay]');
    return {
      url: location.href,
      title: document.title,
      payment_outcome: outcomeEl ? String(outcomeEl.getAttribute('data-payment-outcome')) : '',
      cancel_state: outcomeEl ? String(outcomeEl.getAttribute('data-cancel-state')) : '',
      continue_pay_href: cta ? String(cta.getAttribute('href') || '') : '',
      body_head: String(document.body ? document.body.innerText : '').slice(0, 400),
    };
  }).catch(() => ({}));

  assertStep(/outcome=cancel/.test(evidence.cancel_landing_url), 'C3.landing', landing);
  assertStep(landing.payment_outcome === 'cancel', 'C3.outcome', landing);
  evidence.cancel_landing = landing;
  evidence.steps.push('C3.cancel_landing_ok');
  return { paypal, landing };
}

/** 未支付断言：订单未支付且没有任何成功交易（取消前 / 取消后都应成立） */
function assertUnpaid(state, phase) {
  assertStep(state.order_status === 'pending', `${phase}.unpaid.status`, state);
  assertStep(state.payment_status === 'pending', `${phase}.unpaid.payment_status`, state);
  assertStep(state.success_transaction_count === 0, `${phase}.unpaid.no_success_txn`, state);
}

/**
 * 取消后专属断言：恢复态必须真落「浏览器取消」。
 * 注意：取消**前** payment_outcome 是 pending（尚未回跳），故这组断言不可前移到 pre_cancel。
 */
function assertCancelRecovery(state, phase) {
  assertStep(state.payment_outcome === 'failed', `${phase}.cancel.outcome_failed`, state);
  assertStep(state.payment_recoverable === true, `${phase}.cancel.recoverable`, state);
  assertStep(state.payment_cancel_source === 'browser_cancel', `${phase}.cancel.source`, state);
}

async function runGuest(context, page, evidence) {
  // C0：游客从 PDP 真实加购（先有车，才可能结账；此前漏调导致空车直接卡在 freezeQuote）
  await addToCart(page);
  evidence.steps.push('C0.cart_added');

  const started = await submitPaypalOrder(page, 'guest');
  evidence.order_uuid = started.order_uuid;
  evidence.order_number = '';
  evidence.quote_token = started.quote_token;
  evidence.idempotency_key = started.idempotency_key;
  evidence.steps.push('C1.order_created');

  const before = verifyOrderState(started.order_uuid);
  assertUnpaid(before, 'guest.pre_cancel');
  evidence.order_number = before.order_number;
  evidence.pre_cancel_state = before;
  evidence.steps.push('C1.order_unpaid_verified');

  const { paypal } = await cancelAtPaypal(context, started.redirect_url, evidence);

  // C4：取消后订单仍为未支付，且没有成功交易，恢复态已落「浏览器取消」
  const afterCancel = verifyOrderState(started.order_uuid);
  assertUnpaid(afterCancel, 'guest.post_cancel');
  assertCancelRecovery(afterCancel, 'guest.post_cancel');
  assertStep(afterCancel.transaction_count >= 1, 'C4.cancel_txn', afterCancel);
  assertStep(
    afterCancel.transactions.every((t) => String(t.status).toLowerCase() !== 'success'),
    'C4.no_success',
    afterCancel,
  );
  evidence.after_cancel_state = afterCancel;
  evidence.steps.push('C4.cancel_state_verified');

  // C5：点取消落地页上真实的「继续支付」CTA
  const cta = paypal.locator('[data-continue-pay]').first();
  assertStep(await cta.count(), 'C5.cta_missing', evidence.cancel_landing);
  const ctaHref = await cta.getAttribute('href');
  assertStep(/#payment-recovery\?/.test(String(ctaHref || '')), 'C5.cta_contract', { ctaHref });
  await cta.click();
  await paypal.waitForURL(/\/checkout/, { timeout: 60000 }).catch(() => {});
  await paypal.waitForLoadState('domcontentloaded').catch(() => {});
  evidence.continue_pay_entry = 'guest_cancel_page_cta';
  evidence.continue_pay_href = String(ctaHref || '');
  evidence.steps.push('C5.continue_pay_clicked');

  await adoptAndResume(paypal, context, evidence, 'guest');
}

/** 登录顾客：走账户订单 CTA */
async function runAccount(context, page, evidence) {
  await loginCustomer(page);
  evidence.steps.push('C0.customer_logged_in');

  // 登录后再加购：让购物车归属该顾客（account 模式的订单必须挂 customer_id）
  await addToCart(page);
  evidence.steps.push('C0.cart_added');

  const started = await submitPaypalOrder(page, 'account');
  evidence.order_uuid = started.order_uuid;
  evidence.quote_token = started.quote_token;
  evidence.idempotency_key = started.idempotency_key;
  evidence.steps.push('C1.order_created');

  const before = verifyOrderState(started.order_uuid);
  assertUnpaid(before, 'account.pre_cancel');
  assertStep(before.customer_id > 0, 'account.order_has_customer', before);
  evidence.order_number = before.order_number;
  evidence.pre_cancel_state = before;
  evidence.steps.push('C1.order_unpaid_verified');

  const { paypal } = await cancelAtPaypal(context, started.redirect_url, evidence);

  const afterCancel = verifyOrderState(started.order_uuid);
  assertUnpaid(afterCancel, 'account.post_cancel');
  assertCancelRecovery(afterCancel, 'account.post_cancel');
  evidence.after_cancel_state = afterCancel;
  evidence.steps.push('C4.cancel_state_verified');

  // C5：去账户订单列表点「继续支付」
  await paypal.goto(FRONTEND + '/customer/account/index#orders', { waitUntil: 'domcontentloaded' });
  await paypal.waitForTimeout(6000);
  const accountCta = paypal.locator(
    `[data-testid="account-order-continue-pay"][data-order-uuid="${started.order_uuid}"]`
  ).first();
  if (!(await accountCta.count())) {
    await paypal.locator('[data-account-orders="true"], #orders').first().scrollIntoViewIfNeeded().catch(() => {});
    await paypal.waitForTimeout(3000);
  }
  assertStep(await accountCta.count(), 'C5.account_cta_missing', {
    order_uuid: started.order_uuid,
    url: paypal.url(),
    body: String(await paypal.evaluate(() => document.body.innerText).catch(() => '')).slice(0, 500),
  });
  const accountHref = await accountCta.getAttribute('href');
  assertStep(/#payment-recovery\?/.test(String(accountHref || '')), 'C5.account_cta_contract', { accountHref });
  await accountCta.click();
  await paypal.waitForURL(/\/checkout/, { timeout: 60000 }).catch(() => {});
  await paypal.waitForLoadState('domcontentloaded').catch(() => {});
  evidence.continue_pay_entry = 'account_orders_cta';
  evidence.continue_pay_href = String(accountHref || '');
  evidence.steps.push('C5.continue_pay_clicked');

  await adoptAndResume(paypal, context, evidence, 'account');
}

/** 续付：等 adopt 出续付 chrome + 完整表单 → 提交 → 真跳网关 → 批准 → 回跳 */
async function adoptAndResume(page, context, evidence, phase) {
  // adopt 后必须是完整结账结构，而不是空车 + 孤立 recovery 双卡
  await page.locator('[data-checkout-continue-chrome]').first().waitFor({ state: 'visible', timeout: 60000 }).catch(() => {});
  await page.locator('[data-checkout-form]').first().waitFor({ state: 'visible', timeout: 60000 }).catch(() => {});

  const adopted = await page.evaluate(() => {
    const chrome = document.querySelector('[data-checkout-continue-chrome]');
    const recovery = document.querySelector('[data-checkout-payment-recovery]');
    const empty = document.querySelector('[data-checkout-empty]');
    const form = document.querySelector('[data-checkout-form]');
    const submit = document.querySelector('[data-submit]');
    const hidden = (el) => !el || el.hasAttribute('hidden');
    return {
      chrome_visible: !!chrome && !chrome.hasAttribute('hidden'),
      form_visible: !!form && !form.hasAttribute('hidden'),
      recovery_hidden: hidden(recovery),
      empty_hidden: hidden(empty),
      submit_enabled: !!submit && !submit.disabled,
      notice: String((document.querySelector('[data-checkout-message]') || {}).textContent || '').slice(0, 200),
      view: String((document.querySelector('[data-checkout-view]') || {}).getAttribute
        ? document.querySelector('[data-checkout-view]').getAttribute('data-checkout-view') : ''),
    };
  });
  assertStep(adopted.chrome_visible, `${phase}.C5.chrome`, adopted);
  assertStep(adopted.form_visible, `${phase}.C5.form`, adopted);
  assertStep(adopted.recovery_hidden, `${phase}.C5.no_recovery_island`, adopted);
  assertStep(!/购物车为空|cart is empty|Shopping cart is empty/i.test(adopted.notice), `${phase}.C5.not_empty`, adopted);
  evidence.adopted_view = adopted;
  evidence.steps.push('C5.continue_pay_adopted');

  // 续付视图**不渲染**收货地址模块（checkout-shipping-address 槽为空）：
  // 于是 formAddress() 全空 → submitCheckoutPayment() 先跑 validateCheckoutShippingFields()
  // → 返回 false → 静默 `return {cancelled:true, reason:'shipping_fields'}`（不跳转、不报错）。
  // 这里显式等待并核验，把「点了没反应」变成可定位的失败，而不是让它超时。
  let addrModule = false;
  for (let i = 0; i < 20; i += 1) {
    addrModule = await page.evaluate(() => {
      const root = document.querySelector('[data-shipping-checkout-address]');
      const api = window.WelineShippingCheckoutAddress;
      return !!root && !!(api && api.rev);
    }).catch(() => false);
    if (addrModule) break;
    await page.waitForTimeout(2000);
  }
  evidence.address_module_present = addrModule;
  if (!addrModule) {
    const dom = await page.evaluate(() => ({
      view: (document.querySelector('.weline-checkout') || {}).getAttribute
        ? document.querySelector('.weline-checkout').getAttribute('data-checkout-view') : '',
      slot_present: !!document.querySelector('[data-shipping-checkout-address]'),
      has_theme_address: !!(window.WelineThemeAddress && window.WelineThemeAddress.applyValues),
      named_fields: Array.from(new Set(Array.from(document.querySelectorAll('input[name],select[name]'))
        .map((el) => el.name))),
    })).catch(() => ({}));
    evidence.address_module_dom = dom;
    throw new Error(`STEP_FAILED[${phase}.C5.address_module_missing]: 续付视图未渲染收货地址模块`
      + `（checkout-shipping-address 槽为空）→ 提交会被静默取消。dom=${JSON.stringify(dom).slice(0, 600)}`);
  }

  // 续付表单地址为空 → 必须先补全地址，否则 submit 同样会被静默取消
  const addr = await ensureAddress(page);
  // 地址落定后店面会重新报价（运费/税），给结算一个 settle 窗口
  await page.waitForTimeout(4000);
  evidence.address_filled = addr;
  evidence.steps.push('C5.address_filled');

  // 反「假通过」硬门禁：必须显式选中真实网关支付方式（paypal），
  // 否则提交会落进本地测试通道 fake_card 并立即 paid（无网关跳转）。
  evidence.payment_method_picked = await pickGatewayPaymentMethod(page, phase);
  evidence.steps.push('C6.gateway_method_selected');

  // 提交续付 → amendUnpaidCheckoutAddress + resumePaymentV2 → 同标签页跳到网关
  // 先挂网络探针：把「点了没反应」翻译成「哪个 operation 回了什么」
  const netLog = [];
  const onResponse = async (resp) => {
    try {
      const req = resp.request();
      const pd = req.postData() || '';
      if (!/resumePaymentV2|amendUnpaidCheckoutAddress|freezeQuote|submitV2/.test(pd)) return;
      const opMatch = pd.match(/"operation"\s*:\s*"([^"]+)"/);
      const body = await resp.text().catch(() => '');
      netLog.push({
        op: opMatch ? opMatch[1] : '(unknown)',
        status: resp.status(),
        body: String(body).slice(0, 900),
      });
    } catch (e) { /* 探针失败不影响主流程 */ }
  };
  page.on('response', onResponse);
  const pageErrors = [];
  page.on('pageerror', (err) => pageErrors.push(String(err && err.message || err).slice(0, 300)));

  const submit = page.locator('[data-submit]').first();
  assertStep(await submit.count(), `${phase}.C6.submit_missing`, adopted);
  await submit.click();

  // 判定「是否真的到了网关」必须看 URL 的 **host**。
  // 实测坑：续付停在 `outcome=pending` 恢复态时，整个 URL 会带上
  // `redirect_url=https%3A%2F%2Fwww.sandbox.paypal.com%2F...` —— 对整串做子串匹配
  // 会命中这个 **URL 编码后** 的地址，把「没跳转」误判成「已跳转网关」（假通过）。
  const isGatewayUrl = (u) => {
    try {
      return /(^|\.)sandbox\.paypal\.com$/.test(new URL(u).host);
    } catch (e) {
      return false;
    }
  };
  const resumeUrlsSeen = [];
  const collectUrl = (u) => {
    if (u && resumeUrlsSeen[resumeUrlsSeen.length - 1] !== u) {
      resumeUrlsSeen.push(u);
    }
  };

  let gatewayUrl = '';
  let gatewayDeadline = Date.now() + 90000;
  while (Date.now() < gatewayDeadline) {
    const u = page.url();
    collectUrl(u);
    if (isGatewayUrl(u)) {
      gatewayUrl = u;
      break;
    }
    await page.waitForTimeout(500);
  }

  // 停在 pending 恢复态 → 点「继续支付」CTA（真实用户动作）再跳网关
  if (!gatewayUrl) {
    evidence.resume_pending_state = await page.evaluate(() => {
      const retry = document.querySelector('[data-payment-retry]');
      return {
        retry_present: !!retry,
        retry_visible: !!retry && !retry.hasAttribute('hidden') && !retry.disabled,
        url: location.href.slice(0, 220),
      };
    }).catch(() => ({}));
    const retry = page.locator('[data-payment-retry]').first();
    if (await retry.count()) {
      await retry.click({ timeout: 10000 }).catch(() => {});
    }
    gatewayDeadline = Date.now() + 90000;
    while (Date.now() < gatewayDeadline) {
      const u = page.url();
      collectUrl(u);
      if (isGatewayUrl(u)) {
        gatewayUrl = u;
        break;
      }
      await page.waitForTimeout(500);
    }
  }

  const resumedUrl = page.url();
  evidence.resume_urls_seen = resumeUrlsSeen.map((u) => String(u).slice(0, 220));
  evidence.resume_redirect_url = gatewayUrl;
  page.off('response', onResponse);

  // 反「假通过」硬门禁：续付的执行通道不得是本地测试支付（fake_card 等）——
  // 它们不经网关直接返回 paid，会让「取消 → 续付」看起来成功，实则无真实支付。
  const localChannelLeak = netLog.filter((entry) => {
    const body = String(entry.body || '');
    return /method_code/.test(body) && /fake_card|local_test|test_card/i.test(body);
  });
  evidence.local_channel_leak = localChannelLeak.map((e) => ({ op: e.op, status: e.status }));
  assertStep(
    localChannelLeak.length === 0,
    `${phase}.C6.no_local_payment_channel`,
    evidence.local_channel_leak,
  );

  if (!gatewayUrl) {
    const diag = await submitDiagnostics(page);
    evidence.submit_diagnostics = diag;
    evidence.submit_net_log = netLog;
    evidence.submit_page_errors = pageErrors;
    throw new Error(`STEP_FAILED[${phase}.C6.resume_redirect]: 续付提交后未真正到达 PayPal 网关`
      + `（只看 URL host，避免 redirect_url 编码串造成的假通过）。${JSON.stringify({
        url: resumedUrl,
        urls_seen: evidence.resume_urls_seen,
        diag,
        net: netLog,
        page_errors: pageErrors,
      }).slice(0, 2000)}`);
  }
  evidence.steps.push('C6.resume_redirected_to_gateway');

  // 续付已建第二笔尝试：旧行仍为 failed，且此刻还没有成功交易
  const during = verifyOrderState(evidence.order_uuid);
  assertStep(during.success_transaction_count === 0, `${phase}.C6.no_success_yet`, during);
  assertStep(
    during.transaction_count >= 2,
    `${phase}.C6.second_attempt_created`,
    during,
  );
  const failedStill = during.transactions.filter((t) => String(t.status).toLowerCase() === 'failed');
  assertStep(failedStill.length >= 1, `${phase}.C6.history_preserved`, during);
  evidence.during_resume_state = during;
  evidence.steps.push('C6.second_attempt_verified');

  // 批准 → 回跳
  await paypalLoginAndApprove(page);
  evidence.steps.push('C6.paypal_approved');
  await page.waitForURL(/test\.weline\.com/, { timeout: 120000 }).catch(() => {});
  if (!/checkout\/success/.test(page.url())) {
    await page.waitForURL(/checkout\/success/, { timeout: 90000 }).catch(() => {});
  }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  evidence.success_url = page.url();
  assertStep(/checkout\/success/.test(evidence.success_url), `${phase}.C7.success_url`, evidence.success_url);
  evidence.steps.push('C7.returned_success');

  // C7：订单 paid + 恰好一条成功交易（无重复扣款 / 无第二条成功交易）
  const paid = verifyOrderState(evidence.order_uuid);
  assertStep(paid.order_status === 'paid', `${phase}.C7.order_paid`, paid);
  assertStep(paid.payment_status === 'paid', `${phase}.C7.payment_paid`, paid);
  assertStep(paid.success_transaction_count === 1, `${phase}.C7.single_success_txn`, paid);
  evidence.paid_state = paid;
  evidence.steps.push('C7.paid_verified');

  // 幂等：重复访问回跳入口，交易数不得变化
  const beforeReplay = paid.transaction_count;
  await page.goto(FRONTEND + '/checkout/success?order_uuid=' + evidence.order_uuid, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(2500);
  const afterReplay = verifyOrderState(evidence.order_uuid);
  assertStep(
    afterReplay.transaction_count === beforeReplay && afterReplay.success_transaction_count === 1,
    `${phase}.C7.idempotent`,
    { before: beforeReplay, after: afterReplay.transaction_count, success: afterReplay.success_transaction_count },
  );
  evidence.replay_state = afterReplay;
  evidence.steps.push('C7.idempotent_verified');
}

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
    ignoreDefaultArgs: ['--enable-automation'],
    args: [
      '--ignore-certificate-errors',
      '--disable-blink-features=AutomationControlled',
      '--disable-popup-blocking',
    ],
  });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1366, height: 950 },
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(150000);

  const out = { ok: false, mode: MODE, payment_method: PAYMENT_METHOD, phases: {}, error: '' };
  const modes = MODE === 'both' ? ['guest', 'account'] : [MODE];

  try {
    for (const m of modes) {
      const evidence = { phase: m, steps: [], error: '' };
      try {
        const freshPage = await context.newPage();
        freshPage.setDefaultTimeout(150000);
        if (m === 'account') {
          await runAccount(context, freshPage, evidence);
        } else {
          await runGuest(context, freshPage, evidence);
        }
        evidence.ok = true;
        await freshPage.close().catch(() => {});
      } catch (e) {
        evidence.ok = false;
        evidence.error = String(e && e.message || e);
      }
      out.phases[m] = evidence;
    }
    out.ok = modes.every((m) => out.phases[m] && out.phases[m].ok);
  } catch (e) {
    out.error = String(e && e.message || e);
  } finally {
    await browser.close().catch(() => {});
  }

  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(out.ok ? 0 : 1);
}

main();
