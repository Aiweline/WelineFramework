/**
 * Express checkout pathway helpers — produce durable order_uuid evidence.
 * Uses startExpressCheckout + fake_card (local immediate paid) for deterministic evidence.
 */
const {
  ADDRESS,
  waitForWelineApi,
  assertStep,
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

  const started = await page.evaluate(async ({ payMethod, shipAddress }) => {
    try {
      let api = window.Weline && window.Weline.Api;
      if (!api || typeof api.resource !== 'function') {
        api = await window.Weline.load('api');
      }
      let guestToken = '';
      try { guestToken = String(sessionStorage.getItem('weline.cart.guest_token') || '').trim(); } catch (e) {}

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
      }, { silent: true, requestTimeoutMs: 120000 });

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
  assertStep(evidence.success && !!evidence.order_uuid, 'express.submit.order_uuid', evidence);
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
