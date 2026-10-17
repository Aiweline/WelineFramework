/**
 * Payment pathway driver: controllable (offline fake_card) vs paypal_sandbox.
 *
 * commerce-release uses controllable — does NOT wait on PayPal "Getting your wallet".
 * commerce-paypal-sandbox suite uses paypal_sandbox with a bounded approve window (≤45s).
 *
 * PASS authority is shell Transaction.isSuccess / storefront landing — NOT PayPal DOM copy.
 * DOM approve controls are assist-only (click when visible). Busy interstitial text alone
 * never throws. See architect-wallet-authority.md.
 */

const WALLET_FAIL_MS = Math.max(
  5000,
  Number(process.env.WELINE_E2E_PAYPAL_WALLET_TIMEOUT_MS || 45000) || 45000,
);

const DEFAULT_LAND_BUDGET_MS = Math.max(WALLET_FAIL_MS + 60000, 120000);

/** DEMO10-aligned Continue / consent selectors (order matters). */
const PAYPAL_CONTINUE_SELECTORS = [
  '#payment-submit-btn',
  '#consentButton',
  'button[data-id="payment-submit-btn"]',
  '[data-testid="consentButton"]',
  '[data-testid="submit-button"]',
  'button:has-text("Complete Purchase")',
  'button:has-text("Agree and Continue")',
  'button:has-text("Pay Now")',
  'button:has-text("Continue")',
  'button:has-text("Confirm")',
  'button:has-text("同意并继续")',
  'button:has-text("继续")',
].join(', ');

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

function isStorefrontReturnUrl(url) {
  return /test\.weline\.com|127\.0\.0\.1/.test(String(url || ''))
    && /checkout\/success|express-review|payment\/frontend\/callback|payment\/handoff/.test(String(url || ''));
}

function isPayPalHost(url) {
  return /paypal\.com|sandbox\.paypal/i.test(String(url || ''));
}

function isLandedSuccessUrl(url) {
  return /checkout\/success|express-review/.test(String(url || ''));
}

/**
 * Pull transaction_no / order_uuid from callback / handoff / success query when present.
 * @param {string} url
 * @returns {{transaction_no:string, order_uuid:string}}
 */
function extractShellKeysFromUrl(url) {
  try {
    const u = new URL(String(url || ''), 'https://example.invalid');
    return {
      transaction_no: String(u.searchParams.get('transaction_no') || '').trim(),
      order_uuid: String(u.searchParams.get('order_uuid') || '').trim(),
    };
  } catch (_) {
    return { transaction_no: '', order_uuid: '' };
  }
}

/**
 * Click PayPal Continue/consent on page + child frames when visible (DOM assist only).
 * @param {import('@playwright/test').Page} paypal
 * @returns {Promise<{clicked:boolean, where:string}>}
 */
async function clickPayPalContinue(paypal) {
  const roots = [paypal, ...(paypal.frames ? paypal.frames() : [])];
  for (const root of roots) {
    try {
      const cont = root.locator(PAYPAL_CONTINUE_SELECTORS).first();
      const count = await cont.count().catch(() => 0);
      if (!count) continue;
      const visible = await cont.isVisible().catch(() => false);
      if (!visible) continue;
      let clicked = false;
      await cont.click({ timeout: 4000 }).then(() => { clicked = true; }).catch(async () => {
        await cont.click({ timeout: 3000, force: true }).then(() => { clicked = true; }).catch(() => {});
      });
      if (clicked) {
        const where = root === paypal ? 'page' : `frame:${String(root.url ? root.url() : '').slice(0, 80)}`;
        return { clicked: true, where };
      }
    } catch (_) {
      // frame may be detached
    }
  }
  return { clicked: false, where: '' };
}

/**
 * Diagnostic only — never a PASS/FAIL gate by itself.
 * @param {import('@playwright/test').Page} paypal
 */
async function isWalletBusy(paypal) {
  return paypal.locator('text=/Getting your wallet/i').isVisible().catch(() => false);
}

/**
 * @deprecated Soft-reload / Escape nudge is forbidden under shell-authority.
 * Kept as a thin alias that only clicks visible approve controls (no reload).
 */
async function nudgeWalletInterstitial(paypal) {
  const click = await clickPayPalContinue(paypal);
  return { clicked: !!click.clicked, reloaded: false };
}

/**
 * @deprecated Must not throw on busy interstitial copy.
 * Returns after one assist click attempt; never fail-fast on "Getting your wallet".
 */
async function assertWalletNotHung(paypal) {
  if (isStorefrontReturnUrl(paypal.url()) || !isPayPalHost(paypal.url())) {
    return { ok: true, sawWallet: false, deprecated: true };
  }
  const busy = await isWalletBusy(paypal);
  await clickPayPalContinue(paypal);
  return { ok: true, sawWallet: busy, deprecated: true };
}

/**
 * Normalize probe result to { paid:boolean, ... }.
 * @param {any} raw
 */
function normalizeShellProbe(raw) {
  if (raw === true) return { paid: true };
  if (raw === false || raw == null) return { paid: false };
  if (typeof raw === 'object') {
    return {
      ...raw,
      paid: !!(raw.paid === true
        || raw.is_success === true
        || /^(success|succeeded|paid|captured|completed)$/i.test(String(raw.status || ''))),
    };
  }
  return { paid: false };
}

async function throwApproveBudgetExceeded(paypal, timeoutMs, extra = {}) {
  const shotDir = process.env.WELINE_E2E_EVIDENCE_DIR || require('path').join(
    require('path').resolve(__dirname, '../../../../../../..'),
    'dev/session',
  );
  const fs = require('fs');
  const detail = {
    shell_paid: !!extra.shell_paid,
    url: paypal.url(),
    timeout_ms: timeoutMs,
    transaction_no: String(extra.transaction_no || ''),
    order_uuid: String(extra.order_uuid || ''),
  };
  let shot = '';
  try {
    fs.mkdirSync(shotDir, { recursive: true });
    shot = require('path').join(shotDir, `paypal-approve-budget-${Date.now()}.png`);
    await paypal.screenshot({ path: shot, fullPage: true }).catch(() => {});
    detail.shot = shot;
  } catch (_) {
    // screenshot best-effort
  }
  throw new Error(
    `STEP_FAILED[paypal.paypal_approve_budget_exceeded]: approve window ${timeoutMs}ms exhausted`
    + ` shell_paid=${detail.shell_paid} url=${detail.url}`
    + (detail.transaction_no ? ` txn=${detail.transaction_no}` : '')
    + (detail.order_uuid ? ` order=${detail.order_uuid}` : '')
    + (shot ? ` shot=${shot}` : ''),
  );
}

/**
 * Parallel approve waiter under shell authority:
 * - URL back to storefront landing/callback/handoff
 * - optional shell paid probe (Transaction.isSuccess / inspect)
 * - DOM click only when approve controls are visible
 *
 * Busy "Getting your wallet" copy alone never fails.
 *
 * @param {object} opts
 * @param {import('@playwright/test').Page} opts.paypal
 * @param {string} [opts.transaction_no]
 * @param {string} [opts.order_uuid]
 * @param {number} [opts.wallet_budget_ms]
 * @param {number} [opts.land_budget_ms]
 * @param {(() => Promise<any>|any)|null} [opts.probeShellPaid]
 * @param {import('@playwright/test').Locator} [opts.email]
 * @param {import('@playwright/test').Locator} [opts.next]
 * @param {string} [opts.buyerEmail]
 * @param {boolean} [opts.includeLand=true] when false, stop after leave-PayPal or shell paid
 * @returns {Promise<{ok:true, shell_paid:boolean, url:string, phase:string}>}
 */
async function awaitPayPalApproveUnderShellAuthority(opts = {}) {
  const paypal = opts.paypal || opts.page;
  if (!paypal) {
    throw new Error('awaitPayPalApproveUnderShellAuthority: paypal/page required');
  }
  let transactionNo = String(opts.transaction_no || '').trim();
  let orderUuid = String(opts.order_uuid || '').trim();
  const walletBudgetMs = Math.max(1000, Number(opts.wallet_budget_ms || WALLET_FAIL_MS) || WALLET_FAIL_MS);
  const landBudgetMs = Math.max(walletBudgetMs, Number(opts.land_budget_ms || DEFAULT_LAND_BUDGET_MS) || DEFAULT_LAND_BUDGET_MS);
  const probeShellPaid = typeof opts.probeShellPaid === 'function' ? opts.probeShellPaid : null;
  const includeLand = opts.includeLand !== false;
  const email = opts.email || null;
  const next = opts.next || null;
  const buyerEmail = String(opts.buyerEmail || '').trim();

  const walletDeadline = Date.now() + walletBudgetMs;
  const landDeadline = Date.now() + landBudgetMs;
  let shellPaid = false;

  async function refreshShellKeysFromUrl() {
    const keys = extractShellKeysFromUrl(paypal.url());
    if (keys.transaction_no) transactionNo = keys.transaction_no;
    if (keys.order_uuid) orderUuid = keys.order_uuid;
  }

  async function runProbe() {
    if (!probeShellPaid) return { paid: false, skipped: true };
    try {
      return normalizeShellProbe(await probeShellPaid({
        transaction_no: transactionNo,
        order_uuid: orderUuid,
      }));
    } catch (_) {
      return { paid: false, probe_error: true };
    }
  }

  async function assistLoginRemount() {
    if (!email || !buyerEmail) return;
    if (!(await email.isVisible().catch(() => false))) return;
    const cur = await email.inputValue().catch(() => '');
    if (!String(cur || '').trim()) {
      await email.fill(buyerEmail).catch(() => {});
    }
    if (next && await next.isVisible().catch(() => false)) {
      await next.click().catch(() => {});
    }
  }

  // --- Approve window (≤ wallet budget): URL + shell + optional DOM click ---
  while (Date.now() < walletDeadline) {
    await refreshShellKeysFromUrl();
    const url = paypal.url();
    if (isStorefrontReturnUrl(url) || !isPayPalHost(url)) {
      break;
    }
    const probe = await runProbe();
    if (probe.paid) {
      shellPaid = true;
      break;
    }
    await assistLoginRemount();
    await clickPayPalContinue(paypal);
    await paypal.waitForTimeout(2000);
  }

  await refreshShellKeysFromUrl();
  if (!shellPaid) {
    const probe = await runProbe();
    shellPaid = !!probe.paid;
  }

  const leftPayPal = isStorefrontReturnUrl(paypal.url()) || !isPayPalHost(paypal.url());
  if (!leftPayPal && !shellPaid) {
    await throwApproveBudgetExceeded(paypal, walletBudgetMs, {
      shell_paid: false,
      transaction_no: transactionNo,
      order_uuid: orderUuid,
    });
  }

  if (!includeLand) {
    return {
      ok: true,
      shell_paid: shellPaid,
      url: paypal.url(),
      phase: leftPayPal ? 'returned' : 'shell_paid_pending_return',
      transaction_no: transactionNo,
      order_uuid: orderUuid,
    };
  }

  // --- Land window: wait for success / express-review (handoff/callback OK intermediate) ---
  while (Date.now() < landDeadline) {
    await refreshShellKeysFromUrl();
    const u = paypal.url();
    if (isLandedSuccessUrl(u)) {
      return {
        ok: true,
        shell_paid: shellPaid || true,
        url: u,
        phase: /express-review/.test(u) ? 'express_review' : 'success',
        transaction_no: transactionNo,
        order_uuid: orderUuid,
      };
    }
    if (/payment\/frontend\/callback|payment\/handoff/.test(u)) {
      if (!shellPaid) {
        const probe = await runProbe();
        shellPaid = !!probe.paid;
      }
      await paypal.waitForTimeout(1000);
      continue;
    }
    if (isPayPalHost(u)) {
      // Shell may already be paid while Hermes interstitial lingers — wait for redirect.
      if (!shellPaid) {
        const probe = await runProbe();
        shellPaid = !!probe.paid;
      }
      if (!shellPaid && Date.now() >= walletDeadline) {
        await throwApproveBudgetExceeded(paypal, walletBudgetMs, {
          shell_paid: false,
          transaction_no: transactionNo,
          order_uuid: orderUuid,
        });
      }
      await clickPayPalContinue(paypal);
      await paypal.waitForTimeout(1000);
      continue;
    }
    await paypal.waitForTimeout(1000);
  }

  if (shellPaid || (await runProbe()).paid) {
    throw new Error(
      `STEP_FAILED[paypal.land_budget_exceeded]: shell_paid=true but no success/express-review`
      + ` within ${landBudgetMs}ms url=${paypal.url()}`
      + (transactionNo ? ` txn=${transactionNo}` : '')
      + (orderUuid ? ` order=${orderUuid}` : ''),
    );
  }
  await throwApproveBudgetExceeded(paypal, walletBudgetMs, {
    shell_paid: false,
    transaction_no: transactionNo,
    order_uuid: orderUuid,
  });
}

/**
 * Backward-compatible approve loop — delegates to shell-authority waiter.
 * Without probeShellPaid, URL return is the only early exit; unpaid hang → budget FAIL.
 *
 * @param {import('@playwright/test').Page} paypal
 * @param {object} [opts]
 */
async function approvePayPalWithWalletBudget(paypal, opts = {}) {
  return awaitPayPalApproveUnderShellAuthority({
    paypal,
    transaction_no: opts.transaction_no || '',
    order_uuid: opts.order_uuid || '',
    wallet_budget_ms: opts.timeoutMs || opts.wallet_budget_ms || WALLET_FAIL_MS,
    land_budget_ms: opts.land_budget_ms || DEFAULT_LAND_BUDGET_MS,
    probeShellPaid: opts.probeShellPaid || null,
    email: opts.email || null,
    next: opts.next || null,
    buyerEmail: opts.buyerEmail || '',
    includeLand: opts.includeLand === true ? true : false,
  });
}

module.exports = {
  WALLET_FAIL_MS,
  DEFAULT_LAND_BUDGET_MS,
  PAYPAL_CONTINUE_SELECTORS,
  resolveDriverMode,
  isControllable,
  paymentMethodCode,
  isStorefrontReturnUrl,
  isPayPalHost,
  isLandedSuccessUrl,
  extractShellKeysFromUrl,
  isWalletBusy,
  clickPayPalContinue,
  assertWalletNotHung,
  nudgeWalletInterstitial,
  approvePayPalWithWalletBudget,
  awaitPayPalApproveUnderShellAuthority,
  normalizeShellProbe,
};
