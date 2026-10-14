/**
 * Payment pathway driver: controllable (offline fake_card) vs paypal_sandbox.
 *
 * commerce-release uses controllable — does NOT wait on PayPal "Getting your wallet".
 * commerce-paypal-sandbox suite uses paypal_sandbox with wallet fail-fast.
 */

const WALLET_FAIL_MS = Math.max(
  5000,
  Number(process.env.WELINE_E2E_PAYPAL_WALLET_TIMEOUT_MS || 45000) || 45000,
);

/**
 * @returns {'controllable'|'paypal_sandbox'}
 */
function resolveDriverMode() {
  const explicit = String(process.env.WELINE_E2E_PAYMENT_DRIVER || '').trim().toLowerCase();
  if (explicit === 'controllable' || explicit === 'paypal_sandbox') {
    return explicit;
  }
  const method = String(process.env.FORCE_PAYMENT_METHOD || '').trim().toLowerCase();
  if (/^(fake_card|offline|free|cod)$/.test(method)) {
    return 'controllable';
  }
  if (method === 'paypal' || method === '') {
    // Empty defaults to paypal historically; suite env must set controllable for gate.
    return method === 'paypal' ? 'paypal_sandbox' : 'paypal_sandbox';
  }
  return 'controllable';
}

function isControllable() {
  return resolveDriverMode() === 'controllable';
}

function paymentMethodCode() {
  if (isControllable()) {
    return String(process.env.FORCE_PAYMENT_METHOD || 'fake_card').toLowerCase() || 'fake_card';
  }
  return String(process.env.FORCE_PAYMENT_METHOD || 'paypal').toLowerCase() || 'paypal';
}

/**
 * Fail-fast when PayPal wallet interstitial hangs (third-party uncontrollable).
 * @param {import('@playwright/test').Page} paypal
 * @param {number} [timeoutMs]
 */
async function assertWalletNotHung(paypal, timeoutMs = WALLET_FAIL_MS) {
  const startedAt = Date.now();
  let sawWallet = false;
  while (Date.now() - startedAt < timeoutMs) {
    if (!/paypal\.com|sandbox\.paypal/i.test(paypal.url())) {
      return { ok: true, sawWallet };
    }
    const busy = await paypal.locator('text=/Getting your wallet/i').isVisible().catch(() => false);
    if (busy) {
      sawWallet = true;
      await paypal.waitForTimeout(1500);
      continue;
    }
    // Left the interstitial (login/consent/success) — OK
    if (sawWallet) {
      return { ok: true, sawWallet };
    }
    return { ok: true, sawWallet: false };
  }
  if (sawWallet || await paypal.locator('text=/Getting your wallet/i').isVisible().catch(() => false)) {
    const shotDir = process.env.WELINE_E2E_EVIDENCE_DIR || require('path').join(
      require('path').resolve(__dirname, '../../../../../../..'),
      'dev/session',
    );
    const fs = require('fs');
    try {
      fs.mkdirSync(shotDir, { recursive: true });
      const shot = require('path').join(shotDir, `paypal-wallet-hang-${Date.now()}.png`);
      await paypal.screenshot({ path: shot, fullPage: true }).catch(() => {});
      throw new Error(
        `STEP_FAILED[paypal.wallet_fail_fast]: still on Getting your wallet after ${timeoutMs}ms url=${paypal.url()} shot=${shot}`,
      );
    } catch (err) {
      if (/wallet_fail_fast/.test(String(err && err.message))) throw err;
      throw new Error(
        `STEP_FAILED[paypal.wallet_fail_fast]: still on Getting your wallet after ${timeoutMs}ms url=${paypal.url()}`,
      );
    }
  }
  return { ok: true, sawWallet };
}

module.exports = {
  WALLET_FAIL_MS,
  resolveDriverMode,
  isControllable,
  paymentMethodCode,
  assertWalletNotHung,
};
