/**
 * B2B test-store commerce pathway helpers.
 * Origin is the dedicated store channel (not default /b2b).
 */
const path = require('path');
const { execFileSync } = require('child_process');

const multi = require('./commerce-multi-scope-checkout-pathways-helpers');
const expressHelpers = require('./commerce-express-checkout-pathway-helpers');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'commerce-b2b-store-checkout-pathways-fixture.php');
const COUPON = multi.COUPON;
const NOTE_TEXT = multi.NOTE_TEXT;

function runB2bFixture(action, payload = {}) {
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
    throw new Error(`b2b-store fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

/**
 * Ensure store/channel and bind WELINE_E2E_STOREFRONT_ORIGIN to channel base_url.
 * @returns {{scope: object, fixture?: object}}
 */
function prepareB2bStoreScope(action = 'ensure_store') {
  const prepared = runB2bFixture(action);
  const baseUrl = String((prepared.scope && prepared.scope.base_url) || '').replace(/\/$/, '');
  if (!baseUrl) {
    throw new Error('b2b-store base_url missing');
  }
  process.env.WELINE_E2E_STOREFRONT_ORIGIN = baseUrl;
  process.env.WELINE_E2E_BASE_URL = baseUrl;
  return prepared;
}

function prepareCustomerOnB2bStore() {
  return prepareB2bStoreScope('prepare');
}

function prepareWholesaleOnB2bStore() {
  return prepareB2bStoreScope('prepare_wholesale');
}

function assertStep(condition, stepId, detail) {
  return multi.assertStep(condition, stepId, detail);
}

module.exports = {
  COUPON,
  NOTE_TEXT,
  prepareB2bStoreScope,
  prepareCustomerOnB2bStore,
  prepareWholesaleOnB2bStore,
  addToCartMode: multi.addToCartMode,
  openCheckout: multi.openCheckout,
  ensureCheckoutAddress: multi.ensureCheckoutAddress,
  applyCouponViaUi: multi.applyCouponViaUi,
  fillOrderNoteViaUi: multi.fillOrderNoteViaUi,
  probeWholesaleSurfaces: multi.probeWholesaleSurfaces,
  applyCouponExpectFailForTob: multi.applyCouponExpectFailForTob,
  submitCheckoutCaptureEvidence: multi.submitCheckoutCaptureEvidence,
  loginAsCustomerApi: multi.loginAsCustomerApi,
  runExpressCheckoutCaptureEvidence: expressHelpers.runExpressCheckoutCaptureEvidence,
  assertStep,
};
