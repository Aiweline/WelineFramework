/**
 * Express checkout pathway helpers — produce durable order_uuid evidence.
 * Uses startExpressCheckout + fake_card (local immediate paid) for deterministic evidence.
 */
const {
  ADDRESS,
  waitForWelineApi,
  assertStep,
  reloadIfThemeGenerationStale,
  evaluateWithThemeReload,
} = require('./release-paypal-account-lifecycle-helpers');

const {
  prepareCustomer,
  addToCartMode,
  multiScopeOrigin,
  gotoMultiScope,
  dismissStorefrontNoise,
} = require('./commerce-multi-scope-checkout-pathways-helpers');

const PAYMENT_METHOD = String(process.env.FORCE_PAYMENT_METHOD || 'fake_card').toLowerCase();

/**
 * @param {import('@playwright/test').Page} page
 * @param {{scope?: string, email?: string}} meta
 */
async function runExpressCheckoutCaptureEvidence(page, meta = {}) {
  await waitForWelineApi(page);
  await dismissStorefrontNoise(page).catch(() => {});

  const shippingEmail = String(meta.email || `e2e.express.${Date.now()}@example.test`);
  const address = {
    ...ADDRESS,
    email: shippingEmail,
    name: 'Express Pathway Buyer',
    country: 'United States',
    country_name: 'United States',
    province: 'California',
    province_name: 'California',
  };

  // Do not hard-refresh here: theme reload between addToCart and express drops in-flight cart
  // identity for some guest sessions and surfaces 购物车为空 on startExpressCheckout.
  const started = await evaluateWithThemeReload(page, async ({ payMethod, shipAddress }) => {
    try {
      const pinTheme = () => {
        const id = String(window.__WELINE_E2E_THEME_ID || '').trim();
        if (!id || !window.Weline || !window.Weline.config) return;
        window.Weline.config.themePublishedVersionId = id;
        window.Weline.config.theme_published_version_id = id;
        if (window.Weline.config.api && typeof window.Weline.config.api === 'object') {
          window.Weline.config.api.themePublishedVersionId = id;
        }
      };
      pinTheme();
      let api = window.Weline && window.Weline.Api;
      if (!api || api.__full !== true || typeof api.resource !== 'function') {
        const loaded = await window.Weline.load('api');
        if (loaded && loaded.__full === true) {
          window.Weline.Api = loaded;
          api = loaded;
        } else {
          api = loaded || api;
        }
      }
      pinTheme();
      let guestToken = '';
      try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}
      if (!guestToken) {
        try {
          const cartApi = await api.resource('cart');
          const issued = await cartApi.issueGuestToken
            ? await cartApi.issueGuestToken({}, { silent: true })
            : null;
          const payload = (issued && issued.data && typeof issued.data === 'object') ? issued.data : issued;
          guestToken = String((payload && payload.guest_token) || (issued && issued.guest_token) || '').trim();
          if (guestToken) {
            try { sessionStorage.setItem('weline.cart.guest_token', guestToken); } catch (e2) {}
          }
        } catch (e3) { /* keep empty */ }
      }
      if (!guestToken) {
        return { success: false, message: 'evaluate_threw', error: 'guest_token_missing' };
      }

      const checkoutApi = await api.resource('checkout');
      // Do not pass service_code — frontend worker whitelist rejects unknown params.
      // ExpressCheckoutFlowService picks a lane from getData + address internally.
      const express = await checkoutApi.startExpressCheckout({
        payment_method: payMethod,
        guest_token: guestToken,
        cart_type: 'toc',
        selling_mode: 'toc',
        address: shipAddress,
        idempotency_key: 'express_pathway_' + Date.now(),
      }, { silent: true, requestTimeoutMs: 45000 });

      const payload = (express && express.data && typeof express.data === 'object') ? express.data : express;
      const orderUuids = Array.isArray(payload && payload.order_uuids)
        ? payload.order_uuids
        : (Array.isArray(express && express.order_uuids) ? express.order_uuids : []);
      const orderUuid = String(
        (orderUuids[0])
        || (payload && payload.order_uuid)
        || (express && express.order_uuid)
        || ''
      ).trim();
      const transactionNo = String(
        (payload && payload.transaction_no)
        || (express && express.transaction_no)
        || ''
      ).trim();
      const redirectUrl = String(
        (payload && (payload.redirect_url || payload.approve_url))
        || (express && (express.redirect_url || express.approve_url))
        || ''
      ).trim();
      const payment = (payload && payload.payment) || (express && express.payment) || {};
      const paidHint = !!(
        (payment && (payment.status === 'paid' || payment.paid === true))
        || (!redirectUrl && orderUuid)
        || /fake_card|local/i.test(payMethod)
      );

      return {
        success: !(express && express.success === false) && !!orderUuid,
        order_uuid: orderUuid,
        order_uuids: orderUuids,
        transaction_no: transactionNo,
        redirect_url: redirectUrl,
        payment_method: payMethod,
        paid_hint: paidHint,
        express,
      };
    } catch (e) {
      return { success: false, message: 'evaluate_threw', error: String(e && e.message || e) };
    }
  }, { payMethod: PAYMENT_METHOD, shipAddress: address });

  const evidence = {
    scope: String(meta.scope || 'express_guest'),
    pathway: 'express',
    email: shippingEmail,
    payment_method: PAYMENT_METHOD,
    order_uuid: String((started && started.order_uuid) || ''),
    order_uuids: (started && started.order_uuids) || [],
    transaction_no: String((started && started.transaction_no) || ''),
    redirect_url: String((started && started.redirect_url) || ''),
    success: !!(started && started.success),
    raw_message: started && started.message ? String(started.message) : '',
    error: started && started.error ? String(started.error).slice(0, 400) : '',
    origin: multiScopeOrigin(),
  };
  // eslint-disable-next-line no-console
  console.log('E2E_EVIDENCE_JSON ' + JSON.stringify(evidence));
  // Soft return on empty-cart / guest_token flake so callers can re-add and retry
  // before hard-asserting order_uuid (assertStep here would abort the retry path).
  return evidence;
}

module.exports = {
  PAYMENT_METHOD,
  prepareCustomer,
  addToCartMode,
  gotoMultiScope,
  multiScopeOrigin,
  runExpressCheckoutCaptureEvidence,
  assertStep,
};
