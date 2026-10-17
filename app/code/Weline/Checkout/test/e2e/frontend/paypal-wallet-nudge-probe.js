/**
 * Local probe: shell-authority waiter semantics (no sandbox).
 * - Visible Continue is assist-clicked
 * - Busy "Getting your wallet" text alone never fails
 * - Mock shell paid → PASS even with busy interstitial and no control
 * - Unpaid hang within tiny budget → paypal_approve_budget_exceeded
 *
 * Usage: node app/code/Weline/Checkout/test/e2e/frontend/paypal-wallet-nudge-probe.js
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));
const {
  clickPayPalContinue,
  isWalletBusy,
  assertWalletNotHung,
  awaitPayPalApproveUnderShellAuthority,
  WALLET_FAIL_MS,
} = require('./payment-pathway-driver');

async function withPage(html, fn) {
  const tmp = path.join(require('os').tmpdir(), `weline-paypal-shell-auth-${Date.now()}-${Math.random().toString(36).slice(2)}.html`);
  fs.writeFileSync(tmp, html, 'utf8');
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto('file://' + tmp, { waitUntil: 'domcontentloaded' });
  try {
    return await fn(page);
  } finally {
    await browser.close();
    try { fs.unlinkSync(tmp); } catch (_) {}
  }
}

async function main() {
  const withBtn = await withPage(`<!doctype html><html><body>
    <h1>Hi, John!</h1>
    <p>Getting your wallet.</p>
    <button id="payment-submit-btn" type="button">Continue</button>
    <script>
      window.__clicked = 0;
      document.getElementById('payment-submit-btn').addEventListener('click', function () {
        window.__clicked += 1;
        location.href = 'http://127.0.0.1/checkout/success?ok=1';
      });
    </script>
  </body></html>`, async (page) => {
    const busy = await isWalletBusy(page);
    const click = await clickPayPalContinue(page);
    const deprecated = await assertWalletNotHung(page);
    return { busy, click, deprecated, clicked: await page.evaluate(() => Number(window.__clicked || 0)) };
  });

  const paidHang = await withPage(`<!doctype html><html><body>
    <h1>Hi, John!</h1>
    <p>Getting your wallet.</p>
  </body></html>`, async (page) => {
    // Stay on file:// (not PayPal host) — force probe-paid path by stubbing URL check via
    // navigating to a fake paypal host is hard offline; instead exercise unpaid budget on
    // a page that looks like PayPal via route hijack.
    await page.route('**/*', (route) => route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<html><body><p>Getting your wallet.</p></body></html>',
    }));
    await page.goto('https://www.sandbox.paypal.com/checkoutnow?token=probe', { waitUntil: 'domcontentloaded' });
    const result = await awaitPayPalApproveUnderShellAuthority({
      paypal: page,
      transaction_no: 'PROBE-TXN',
      wallet_budget_ms: 4000,
      land_budget_ms: 8000,
      probeShellPaid: () => ({ paid: true, status: 'success' }),
      includeLand: false,
    });
    return result;
  });

  let unpaidFail = null;
  try {
    await withPage(`<!doctype html><html><body><p>Getting your wallet.</p></body></html>`, async (page) => {
      await page.route('**/*', (route) => route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<html><body><p>Getting your wallet.</p></body></html>',
      }));
      await page.goto('https://www.sandbox.paypal.com/checkoutnow?token=probe-unpaid', { waitUntil: 'domcontentloaded' });
      await awaitPayPalApproveUnderShellAuthority({
        paypal: page,
        transaction_no: 'PROBE-UNPAID',
        wallet_budget_ms: 3500,
        land_budget_ms: 5000,
        probeShellPaid: () => ({ paid: false, status: 'pending' }),
        includeLand: false,
      });
      return { unexpected_pass: true };
    });
  } catch (err) {
    unpaidFail = String(err && err.message || err);
  }

  const ok = withBtn.busy === true
    && withBtn.click.clicked === true
    && withBtn.deprecated.ok === true
    && paidHang.ok === true
    && paidHang.shell_paid === true
    && /paypal_approve_budget_exceeded/.test(String(unpaidFail || ''));

  const out = {
    ok,
    withBtn,
    paidHang,
    unpaidFail: String(unpaidFail || '').slice(0, 240),
    WALLET_FAIL_MS,
  };
  console.log(JSON.stringify(out, null, 2));
  if (!ok) process.exit(1);
}

main().catch((e) => {
  console.error(String(e && e.stack || e));
  process.exit(1);
});
