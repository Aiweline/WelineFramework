/**
 * HelpPay pathway helpers — quick buy (/q/) + friend help-pay (/h/).
 * Uses fake_card for durable local transaction_no (+ order_uuid when Payment L1 exposes it).
 */
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const CHECKOUT_FIXTURE = path.resolve(
  ROOT_DIR,
  'app/code/Weline/Checkout/test/e2e/frontend/commerce-multi-scope-checkout-pathways-fixture.php'
);
const PAYMENT_METHOD = String(process.env.FORCE_PAYMENT_METHOD || 'fake_card').toLowerCase();
const US_ADDRESS = {
  name: 'HelpPay Pathway Buyer',
  phone: '14085551234',
  line1: '1 Main St',
  address1: '1 Main St',
  street: '1 Main St',
  city: 'San Jose',
  province: 'California',
  postal_code: '95131',
  country: 'US',
  country_code: 'US',
};

function assertStep(condition, stepId, detail) {
  if (!condition) {
    throw new Error(`STEP_FAILED[${stepId}]: ${typeof detail === 'string' ? detail : JSON.stringify(detail).slice(0, 900)}`);
  }
}

function storefrontOrigin() {
  const forced = String(process.env.WELINE_E2E_STOREFRONT_ORIGIN || process.env.WELINE_E2E_BASE_URL || '').trim();
  if (forced) return forced.replace(/\/$/, '');
  return 'https://p05113ef3.test.weline.com';
}

function prepareCustomer() {
  const stdout = execFileSync('php', [CHECKOUT_FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action: 'prepare' }),
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
    throw new Error(`helppay prepare failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function dismissNoise(page) {
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

async function gotoPdp(page, gotoFrontend, pdpPath) {
  const origin = storefrontOrigin();
  const abs = pdpPath.startsWith('http')
    ? pdpPath
    : `${origin}${pdpPath.startsWith('/') ? '' : '/'}${pdpPath}`;
  try {
    await page.goto(abs, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (_) {
    await gotoFrontend(page, pdpPath, { timeout: 90000, settleMs: 600, useProxy: false });
  }
  await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
  await dismissNoise(page).catch(() => {});
  await page.waitForSelector(
    '[data-testid="product-quick-pay"], [data-helppay-quick-pay], [data-testid="product-help-pay"], [data-action="buy-now"][data-product-id]',
    { timeout: 60000 }
  );
}

/**
 * Read PDP product pricing + id for HelpPay create payloads.
 * @param {import('@playwright/test').Page} page
 */
async function readPdpProductContext(page) {
  return page.evaluate(() => {
    const btn = document.querySelector('[data-helppay-quick-pay], [data-testid="product-quick-pay"]')
      || document.querySelector('[data-helppay-open], [data-testid="help-pay-open"]')
      || document.querySelector('[data-action="buy-now"][data-product-id]')
      || document.querySelector('[data-testid="product-buy-now"][data-product-id]');
    const productId = Number(
      (btn && (btn.getAttribute('data-product-id') || btn.dataset.productId))
      || (document.querySelector('[data-product-id]') || {}).getAttribute?.('data-product-id')
      || 0
    ) || 0;
    const qtyEl = document.querySelector('[data-testid="product-qty"]');
    const qty = qtyEl ? Math.max(1, Number(qtyEl.value || 1) || 1) : 1;
    let goodsMinor = 0;
    const priceEl = document.querySelector('[data-testid="product-price"], [data-product-price], .product-price [data-amount-minor]');
    if (priceEl) {
      goodsMinor = Math.max(0, Number(priceEl.getAttribute('data-amount-minor') || priceEl.dataset.amountMinor || 0) || 0);
    }
    if (!goodsMinor) {
      const meta = document.querySelector('meta[property="product:price:amount"], meta[name="product:price:amount"]');
      const dollars = meta ? Number(meta.getAttribute('content') || 0) : 0;
      if (dollars > 0) goodsMinor = Math.round(dollars * 100);
    }
    let currency = 'USD';
    try {
      currency = String(
        (window.WelineCurrency && window.WelineCurrency.code)
        || document.documentElement.getAttribute('data-currency')
        || 'USD'
      ).toUpperCase() || 'USD';
    } catch (e) {}
    return { product_id: productId, qty, goods_amount_minor: goodsMinor, currency_code: currency };
  });
}

/**
 * Create quick-pay link + startQuickPayment(fake_card).
 * @param {import('@playwright/test').Page} page
 * @param {{scope?: string}} meta
 */
async function runQuickPayCaptureEvidence(page, meta = {}) {
  const ctx = await readPdpProductContext(page);
  assertStep(ctx.product_id > 0, 'helppay.quick.product_id', ctx);

  const started = await page.evaluate(async ({ product, address, payMethod }) => {
    try {
      let api = window.Weline && window.Weline.Api;
      if (!api || typeof api.resource !== 'function') {
        api = await window.Weline.load('api');
      }
      const helpPay = await api.resource('helpPay');
      const shipQuoted = await helpPay.listQuickShippingOptions({
        product_id: product.product_id,
        qty: product.qty,
        goods_amount_minor: product.goods_amount_minor || 1000,
        currency_code: product.currency_code || 'USD',
        shipping_address: address,
        address,
      }, { silent: true, requestTimeoutMs: 120000 });
      const shipRoot = (shipQuoted && shipQuoted.data && typeof shipQuoted.data === 'object')
        ? shipQuoted.data
        : shipQuoted;
      const options = (shipRoot && shipRoot.options) || (shipQuoted && shipQuoted.options) || [];
      const codes = Array.isArray(options)
        ? options.map((o) => ({
          code: String((o && (o.service_code || o.code)) || ''),
          label: String((o && (o.service_label || o.label)) || ''),
          minor: Math.max(0, Number((o && (o.shipping_amount_minor || o.amount_minor)) || 0) || 0),
        })).filter((o) => o.code)
        : [];
      const preferred = codes.find((c) => /AMERICAS|INTL/i.test(c.code))
        || codes.find((c) => c.code && !/DOMESTIC/i.test(c.code))
        || codes[0];
      if (!preferred) {
        return {
          success: false,
          message: 'quick_no_shipping',
          ship: shipQuoted,
          goods_amount_minor: product.goods_amount_minor,
        };
      }
      const goodsMinor = Math.max(100, Number(product.goods_amount_minor || 0) || 1000);
      const created = await helpPay.createQuickPay({
        amount_minor: goodsMinor + preferred.minor,
        currency_code: product.currency_code || 'USD',
        product_id: product.product_id,
        qty: product.qty,
        service_code: preferred.code,
        service_label: preferred.label,
        goods_amount_minor: goodsMinor,
        shipping_amount_minor: preferred.minor,
        shipping_address: {
          ...address,
          service_code: preferred.code,
          service_label: preferred.label,
          shipping_amount_minor: preferred.minor,
          goods_amount_minor: goodsMinor,
        },
        cart_type: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      const createRoot = (created && created.data && typeof created.data === 'object') ? created.data : created;
      const token = String((createRoot && createRoot.token) || (created && created.token) || '').trim();
      const payUrl = String((createRoot && createRoot.url) || (created && created.url) || '').trim();
      if (!token) {
        return { success: false, message: 'quick_token_missing', created };
      }
      const paid = await helpPay.startQuickPayment({
        token,
        payment_method: payMethod,
        billing_address: {
          name: address.name,
          phone: address.phone,
          address1: address.line1 || address.address1,
          street: address.street || address.line1,
          city: address.city,
          province: address.province,
          postal_code: address.postal_code,
          country_code: address.country_code || address.country,
          country: address.country_code || address.country,
        },
        idempotency_key: 'helppay_quick_' + Date.now(),
      }, { silent: true, requestTimeoutMs: 120000 });
      const payRoot = (paid && paid.data && typeof paid.data === 'object') ? paid.data : paid;
      const transactionNo = String(
        (payRoot && payRoot.transaction_no) || (paid && paid.transaction_no) || ''
      ).trim();
      const orderUuid = String(
        (payRoot && (payRoot.order_uuid || (Array.isArray(payRoot.order_uuids) && payRoot.order_uuids[0])))
        || (paid && (paid.order_uuid || (Array.isArray(paid.order_uuids) && paid.order_uuids[0])))
        || ''
      ).trim();
      const redirectUrl = String(
        (payRoot && (payRoot.redirect_url || payRoot.success_url || payRoot.approve_url))
        || (paid && (paid.redirect_url || paid.success_url || paid.approve_url))
        || ''
      ).trim();
      const isPaid = !!(payRoot && (payRoot.paid === true || payRoot.status === 'paid'))
        || (!!transactionNo && /fake_card|local/i.test(payMethod));

      return {
        success: !(paid && paid.success === false && paid.ok === false) && !!transactionNo,
        token,
        pay_url: payUrl,
        transaction_no: transactionNo,
        order_uuid: orderUuid,
        redirect_url: redirectUrl,
        paid: isPaid,
        service_code: preferred.code,
        goods_amount_minor: goodsMinor,
        shipping_amount_minor: preferred.minor,
        payment_method: payMethod,
        created,
        paid_result: paid,
      };
    } catch (e) {
      return { success: false, message: 'evaluate_threw', error: String(e && e.message || e) };
    }
  }, { product: ctx, address: US_ADDRESS, payMethod: PAYMENT_METHOD });

  // Best-effort: follow success redirect and scrape order_uuid if present.
  let scrapedOrder = '';
  if (started && started.redirect_url && /payment\/success|checkout\/success/i.test(started.redirect_url)) {
    try {
      const origin = storefrontOrigin();
      const url = started.redirect_url.startsWith('http')
        ? started.redirect_url
        : `${origin}${started.redirect_url.startsWith('/') ? '' : '/'}${started.redirect_url}`;
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      scrapedOrder = await page.evaluate(() => {
        const body = document.body ? document.body.innerText : '';
        const m = body.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
        return m ? m[0] : '';
      });
    } catch (_) {
      // keep transaction_no as primary evidence
    }
  }

  const evidence = {
    scope: String(meta.scope || 'helppay_quick'),
    pathway: 'helppay_quick',
    payment_method: PAYMENT_METHOD,
    product_id: ctx.product_id,
    token: String((started && started.token) || ''),
    pay_url: String((started && started.pay_url) || ''),
    transaction_no: String((started && started.transaction_no) || ''),
    order_uuid: String((started && started.order_uuid) || scrapedOrder || ''),
    redirect_url: String((started && started.redirect_url) || ''),
    service_code: String((started && started.service_code) || ''),
    paid: !!(started && started.paid),
    success: !!(started && started.success),
    raw_message: started && started.message ? String(started.message) : '',
    error: started && started.error ? String(started.error).slice(0, 400) : '',
    origin: storefrontOrigin(),
  };
  // eslint-disable-next-line no-console
  console.log('E2E_EVIDENCE_JSON ' + JSON.stringify(evidence));
  assertStep(evidence.success && !!evidence.transaction_no, 'helppay.quick.transaction_no', evidence);
  return evidence;
}

/**
 * Create friend help-pay link + startPayerPayment(fake_card).
 * @param {import('@playwright/test').Page} page
 * @param {{scope?: string}} meta
 */
async function runHelpPayFriendCaptureEvidence(page, meta = {}) {
  const ctx = await readPdpProductContext(page);
  assertStep(ctx.product_id > 0, 'helppay.friend.product_id', ctx);

  const started = await page.evaluate(async ({ product, address, payMethod }) => {
    try {
      let api = window.Weline && window.Weline.Api;
      if (!api || typeof api.resource !== 'function') {
        api = await window.Weline.load('api');
      }
      const helpPay = await api.resource('helpPay');
      const goodsMinor = Math.max(100, Number(product.goods_amount_minor || 0) || 1000);
      const created = await helpPay.createHelpPay({
        rules_accepted: true,
        address_confirmed: true,
        amount_minor: goodsMinor,
        goods_amount_minor: goodsMinor,
        currency_code: product.currency_code || 'USD',
        product_id: product.product_id,
        qty: product.qty,
        shipping_address: address,
        cart_type: 'toc',
      }, { silent: true, requestTimeoutMs: 120000 });
      const createRoot = (created && created.data && typeof created.data === 'object') ? created.data : created;
      const token = String((createRoot && createRoot.token) || (created && created.token) || '').trim();
      const payUrl = String((createRoot && createRoot.url) || (created && created.url) || '').trim();
      if (!token) {
        return { success: false, message: 'help_token_missing', created };
      }

      let serviceCode = '';
      let shipMinor = 0;
      let serviceLabel = '';
      try {
        const quoted = await helpPay.listPayerShippingOptions({ token }, { silent: true, requestTimeoutMs: 120000 });
        const root = (quoted && quoted.data && typeof quoted.data === 'object') ? quoted.data : quoted;
        const options = (root && root.options) || (quoted && quoted.options) || [];
        const codes = Array.isArray(options)
          ? options.map((o) => ({
            code: String((o && (o.service_code || o.code)) || ''),
            label: String((o && (o.service_label || o.label)) || ''),
            minor: Math.max(0, Number((o && (o.shipping_amount_minor || o.amount_minor)) || 0) || 0),
          })).filter((o) => o.code)
          : [];
        const preferred = codes.find((c) => /AMERICAS|INTL/i.test(c.code))
          || codes.find((c) => c.code && !/DOMESTIC/i.test(c.code))
          || codes[0];
        if (preferred) {
          serviceCode = preferred.code;
          shipMinor = preferred.minor;
          serviceLabel = preferred.label;
        }
      } catch (e) {
        // legacy links may not require shipping
      }

      const payPayload = {
        token,
        payment_method: payMethod,
        billing_address: {
          name: address.name,
          phone: address.phone,
          address1: address.line1 || address.address1,
          street: address.street || address.line1,
          city: address.city,
          province: address.province,
          postal_code: address.postal_code,
          country_code: address.country_code || address.country,
          country: address.country_code || address.country,
        },
        idempotency_key: 'helppay_friend_' + Date.now(),
      };
      if (serviceCode) {
        payPayload.service_code = serviceCode;
        payPayload.shipping_amount_minor = shipMinor;
      }

      const paid = await helpPay.startPayerPayment(payPayload, { silent: true, requestTimeoutMs: 120000 });
      const payRoot = (paid && paid.data && typeof paid.data === 'object') ? paid.data : paid;
      const transactionNo = String(
        (payRoot && payRoot.transaction_no) || (paid && paid.transaction_no) || ''
      ).trim();
      const orderUuid = String(
        (payRoot && (payRoot.order_uuid || (Array.isArray(payRoot.order_uuids) && payRoot.order_uuids[0])))
        || (paid && (paid.order_uuid || (Array.isArray(paid.order_uuids) && paid.order_uuids[0])))
        || ''
      ).trim();
      const redirectUrl = String(
        (payRoot && (payRoot.redirect_url || payRoot.success_url || payRoot.approve_url))
        || (paid && (paid.redirect_url || paid.success_url || paid.approve_url))
        || ''
      ).trim();
      const isPaid = !!(payRoot && (payRoot.paid === true || payRoot.status === 'paid'))
        || (!!transactionNo && /fake_card|local/i.test(payMethod));

      return {
        success: !(paid && paid.success === false && paid.ok === false) && !!transactionNo,
        token,
        pay_url: payUrl,
        transaction_no: transactionNo,
        order_uuid: orderUuid,
        redirect_url: redirectUrl,
        paid: isPaid,
        service_code: serviceCode,
        service_label: serviceLabel,
        shipping_amount_minor: shipMinor,
        goods_amount_minor: goodsMinor,
        payment_method: payMethod,
        created,
        paid_result: paid,
      };
    } catch (e) {
      return { success: false, message: 'evaluate_threw', error: String(e && e.message || e) };
    }
  }, { product: ctx, address: US_ADDRESS, payMethod: PAYMENT_METHOD });

  let scrapedOrder = '';
  if (started && started.redirect_url && /payment\/success|checkout\/success/i.test(started.redirect_url)) {
    try {
      const origin = storefrontOrigin();
      const url = started.redirect_url.startsWith('http')
        ? started.redirect_url
        : `${origin}${started.redirect_url.startsWith('/') ? '' : '/'}${started.redirect_url}`;
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      scrapedOrder = await page.evaluate(() => {
        const body = document.body ? document.body.innerText : '';
        const m = body.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
        return m ? m[0] : '';
      });
    } catch (_) {}
  }

  const evidence = {
    scope: String(meta.scope || 'helppay_friend'),
    pathway: 'helppay_friend',
    payment_method: PAYMENT_METHOD,
    product_id: ctx.product_id,
    token: String((started && started.token) || ''),
    pay_url: String((started && started.pay_url) || ''),
    transaction_no: String((started && started.transaction_no) || ''),
    order_uuid: String((started && started.order_uuid) || scrapedOrder || ''),
    redirect_url: String((started && started.redirect_url) || ''),
    service_code: String((started && started.service_code) || ''),
    paid: !!(started && started.paid),
    success: !!(started && started.success),
    raw_message: started && started.message ? String(started.message) : '',
    error: started && started.error ? String(started.error).slice(0, 400) : '',
    origin: storefrontOrigin(),
  };
  // eslint-disable-next-line no-console
  console.log('E2E_EVIDENCE_JSON ' + JSON.stringify(evidence));
  assertStep(evidence.success && !!evidence.transaction_no, 'helppay.friend.transaction_no', evidence);
  return evidence;
}

module.exports = {
  PAYMENT_METHOD,
  US_ADDRESS,
  prepareCustomer,
  storefrontOrigin,
  gotoPdp,
  readPdpProductContext,
  runQuickPayCaptureEvidence,
  runHelpPayFriendCaptureEvidence,
  assertStep,
};
