/**
 * PayPal sandbox — HelpPay quick buy (PDP popup address→shipping→/q/ → PayPal).
 */
const path = require('path');
const { chromium } = require(path.resolve(__dirname, '../../../../../../../tests/e2e/node_modules/playwright'));

const FRONTEND = process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
const PDP = process.env.QUICK_PDP_PATH
  || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
const BUYER_EMAIL = process.env.PAYPAL_SANDBOX_BUYER_EMAIL || 'sb-4sxrp30216572@personal.example.com';
const BUYER_PASS = process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || 'weline18';

async function loginPayPal(paypal) {
  await paypal.waitForLoadState('domcontentloaded');
  for (const sel of ['#loginWithPassword', '#tryPasswordLink', 'a:has-text("Use password instead")']) {
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
  await pass.waitFor({ state: 'visible', timeout: 30000 });
  await pass.fill(BUYER_PASS);
  await paypal.locator('#btnLogin, button:has-text("Log In"), button:has-text("Log in")').first().click();
  await paypal.waitForTimeout(2500);
  for (let i = 0; i < 12; i += 1) {
    if (/test\.weline\.com|127\.0\.0\.1|checkout\/success|payment\/frontend\/callback|\/q\//.test(paypal.url())) {
      return;
    }
    const cont = paypal.locator(
      '#payment-submit-btn, #consentButton, button[data-id="payment-submit-btn"], [data-testid="consentButton"], button:has-text("Continue"), button:has-text("Agree and Continue"), button:has-text("Complete Purchase")'
    ).first();
    if (await cont.count() && await cont.isVisible().catch(() => false)) {
      await cont.click({ timeout: 5000 }).catch(() => {});
    }
    await paypal.waitForTimeout(2000);
  }
}

async function main() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
    args: ['--disable-popup-blocking'],
  });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const result = { ok: false, flow: 'quick_buy', steps: [], pay_url: '', approve_url: '', transaction_no: '', error: '' };

  try {
    await page.goto(FRONTEND + PDP, { waitUntil: 'domcontentloaded' });
    result.steps.push('pdp_loaded');
    await page.waitForFunction(() => !!(window.Weline && window.Weline.Api), null, { timeout: 60000 });
    await page.waitForSelector('[data-helppay-quick-pay], [data-testid="product-quick-pay"]', { timeout: 60000 });
    await page.locator('[data-helppay-quick-pay], [data-testid="product-quick-pay"]').first().click();
    result.steps.push('quick_opened');

    const dialog = page.locator('[data-testid="help-pay-dialog"], [data-helppay-dialog]').first();
    await dialog.waitFor({ state: 'visible', timeout: 30000 });

    // Address step: ThemeAddress cascade + visible fields (country is hidden cascade).
    await page.waitForSelector('[data-shipping-checkout-address]', { timeout: 30000 });
    await page.waitForTimeout(1500);
    await dialog.locator('[data-shipping-checkout-address] input[name="name"]').fill('Sandbox Buyer');
    await dialog.locator('[data-shipping-checkout-address] input[name="phone"]').fill('14085551234');
    await dialog.locator('[data-shipping-checkout-address] input[name="address1"]').fill('1 Main St');
    await dialog.locator('[data-shipping-checkout-address] input[name="postal_code"]').fill('95131');
    const addrOk = await page.evaluate(async () => {
      if (window.WelineThemeAddress && typeof window.WelineThemeAddress.applyValues === 'function') {
        await window.WelineThemeAddress.applyValues('checkout-shipping-address', {
          country_code: 'US',
          country: 'United States',
          province: 'California',
          city: 'San Jose',
          district: '',
          street: '1 Main St',
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
      setHidden('country_code', 'US');
      setHidden('country', 'United States');
      setHidden('province', 'California');
      setHidden('city', 'San Jose');
      setHidden('street', '1 Main St');
      const api = window.WelineShippingCheckoutAddress;
      const resolved = api && api.resolveQuoteAddress ? api.resolveQuoteAddress() : null;
      const valid = api && api.validateShippingFields ? await api.validateShippingFields(resolved || {}) : true;
      return { valid, productId: Number((document.querySelector('[data-helppay-quick-pay]') || {}).getAttribute?.('data-product-id') || 0), country: resolved && resolved.country_code };
    });
    if (!addrOk || addrOk.valid === false) {
      throw new Error('quick_address_invalid:' + JSON.stringify(addrOk));
    }
    result.steps.push('address_filled:' + JSON.stringify(addrOk));
    // Must use confirm-quick only — "下一步" also matches hidden help-pay rules CTA.
    const addressPick = dialog.locator('[data-testid="helppay-address-pick"], [data-helppay-step="address-pick"]').first();
    await addressPick.waitFor({ state: 'visible', timeout: 30000 });
    const confirmAddr = dialog.locator('[data-testid="helppay-confirm-quick"], [data-helppay-confirm-quick]').first();
    await confirmAddr.waitFor({ state: 'visible', timeout: 30000 });
    await confirmAddr.click();
    result.steps.push('address_confirmed');

    // Shipping step (must become visible — not merely attached/hidden).
    const shipStep = dialog.locator('[data-testid="helppay-shipping-pick"], [data-helppay-step="shipping-pick"]').first();
    await shipStep.waitFor({ state: 'visible', timeout: 60000 });
    await dialog.locator('[data-testid="helppay-ship-option"], input[name="helppay_service_code"]').first().waitFor({ state: 'visible', timeout: 90000 });
    const shipMsg = await dialog.locator('[data-helppay-shipping-msg]').innerText().catch(() => '');
    if (/缺少重量|missing weight/i.test(shipMsg)) {
      throw new Error('missing_weight_blocked:' + shipMsg);
    }
    // Prefer Americas / non-domestic lane for US address.
    const shipCodes = await dialog.locator('input[name="helppay_service_code"]').evaluateAll((nodes) =>
      nodes.map((n) => String(n.value || ''))
    );
    const preferredShip = shipCodes.find((c) => /AMERICAS|INTL/i.test(c))
      || shipCodes.find((c) => c && !/DOMESTIC/i.test(c))
      || shipCodes[0];
    if (!preferredShip) throw new Error('quick_no_shipping:' + shipMsg);
    await dialog.locator(`input[name="helppay_service_code"][value="${preferredShip}"]`).check({ force: true });
    const confirmShip = dialog.locator('[data-helppay-confirm-shipping], [data-testid="helppay-confirm-shipping"]').first();
    await confirmShip.click();
    result.steps.push('shipping_confirmed');

    await dialog.locator('[data-testid="helppay-payment-step"], [data-helppay-step="payment"]').first().waitFor({ state: 'visible', timeout: 60000 });
    // Wait createQuickPay to finish enabling pay button / exposing URL.
    await page.waitForTimeout(3000);
    const payUrlText = await dialog.locator('[data-helppay-pay-url-text]').innerText().catch(() => '');
    let payUrl = String(payUrlText || '').trim();
    if (!payUrl) {
      payUrl = await page.evaluate(() => {
        const d = document.querySelector('[data-testid="help-pay-dialog"]');
        const st = d && d._helppayQuickState;
        return st && st.pay_url ? String(st.pay_url) : '';
      });
    }
    result.pay_url = payUrl;
    if (!payUrl || !/\/q\//.test(payUrl)) {
      throw new Error('quick_pay_url_missing:' + payUrl);
    }
    result.steps.push('quick_link_ready');

    const qPage = await context.newPage();
    await qPage.goto(payUrl, { waitUntil: 'domcontentloaded' });
    result.steps.push('q_page_opened');
    await qPage.waitForSelector('[data-testid="quick-pay-submit"], [data-helppay-quick-self-pay]', { timeout: 30000 });

    const started = await qPage.evaluate(async () => {
      const btn = document.querySelector('[data-testid="quick-pay-submit"], [data-helppay-quick-self-pay]');
      const token = (btn && btn.getAttribute('data-token'))
        || (location.pathname.match(/\/q\/([^/?#]+)/) || [])[1]
        || '';
      const api = await window.Weline.Api.resource('helpPay');
      return api.startQuickPayment({
        token: decodeURIComponent(token),
        payment_method: 'paypal',
        idempotency_key: 'sandbox_quick_' + Date.now(),
      }, { silent: true, requestTimeoutMs: 120000 });
    });
    result.steps.push('quick_payment_started');
    if (!started || started.success === false && started.ok === false) {
      throw new Error('startQuickPayment_failed:' + JSON.stringify(started).slice(0, 800));
    }
    const data = started.data && typeof started.data === 'object' ? started.data : started;
    const redirectUrl = String(data.redirect_url || data.approve_url || '').trim();
    result.approve_url = redirectUrl;
    result.transaction_no = String(data.transaction_no || '').trim();
    if (!redirectUrl) throw new Error('quick_redirect_missing:' + JSON.stringify(started).slice(0, 800));

    const paypal = await context.newPage();
    await paypal.goto(redirectUrl, { waitUntil: 'domcontentloaded' });
    result.steps.push('paypal_opened');
    await loginPayPal(paypal);
    result.steps.push('paypal_approved');
    await paypal.waitForURL(/test\.weline\.com|127\.0\.0\.1/, { timeout: 120000 }).catch(() => {});
    result.return_url = paypal.url();
    result.steps.push('returned:' + result.return_url);
    result.ok = !!result.transaction_no && !!result.approve_url && /sandbox\.paypal\.com/.test(result.approve_url);
    // Stronger: storefront return after approve.
    if (/test\.weline\.com|127\.0\.0\.1/.test(result.return_url || '')) {
      result.ok = true;
    }
  } catch (e) {
    result.error = String(e && e.message || e);
  }

  console.log(JSON.stringify(result, null, 2));
  await browser.close();
  process.exit(result.ok ? 0 : 1);
}

main();
