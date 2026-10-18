/**
 * B2B test-store commerce pathway helpers.
 * Origin is the dedicated store channel (not default /b2b).
 * Scope truth: tests/e2e/framework/storefront-scope-context.js (push/pop) — not process.env.
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  pushScope,
  popScope,
  clearScopes,
  currentScope,
} = require('../../../../../../../tests/e2e/framework/storefront-scope-context');

const multi = require('./commerce-multi-scope-checkout-pathways-helpers');
const expressHelpers = require('./commerce-express-checkout-pathway-helpers');
const { parsePhpFixtureStdout, phpFixtureEnv } = require('./e2e-php-fixture-json');

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
    env: phpFixtureEnv(),
  });
  const parsed = parsePhpFixtureStdout(stdout, `b2b-store fixture ${action}`);
  if (!parsed.ok) {
    throw new Error(`b2b-store fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

/**
 * Push B2B channel onto explicit Scope stack (no process.env write).
 * Idempotent when already on the same B2B origin.
 * MUST call restoreStorefrontOrigin() in afterAll.
 */
function bindB2bStorefrontOrigin(baseUrl) {
  const origin = String(baseUrl || '').replace(/\/$/, '');
  if (!origin) {
    throw new Error('bindB2bStorefrontOrigin: baseUrl required');
  }
  const top = currentScope();
  if (top && top.b2b && top.origin === origin) {
    return;
  }
  pushScope({ origin, b2b: true });
}

/**
 * Pop all B2B Scope frames (and clear leftover B2B pollution).
 */
function restoreStorefrontOrigin() {
  while (currentScope() && currentScope().b2b) {
    popScope();
  }
  if (currentScope() && /e2e-b2b-commerce/i.test(String(currentScope().origin || ''))) {
    clearScopes();
  }
}

/**
 * Ensure store/channel and push Scope stack to channel base_url.
 * @returns {{scope: object, fixture?: object}}
 */
function prepareB2bStoreScope(action = 'ensure_store') {
  const prepared = runB2bFixture(action);
  const baseUrl = String((prepared.scope && prepared.scope.base_url) || '').replace(/\/$/, '');
  if (!baseUrl) {
    throw new Error('b2b-store base_url missing');
  }
  bindB2bStorefrontOrigin(baseUrl);
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
  bindB2bStorefrontOrigin,
  restoreStorefrontOrigin,
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
