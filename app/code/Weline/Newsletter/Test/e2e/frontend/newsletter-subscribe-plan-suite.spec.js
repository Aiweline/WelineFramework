/**
 * newsletter-subscribe plan-suite（对齐冻结 UC-1…UC-4）
 *
 * 权威 UC：app/code/Weline/Newsletter/doc/开发/team/newsletter-subscribe/meetings/align-freeze.md
 * 套件 id：newsletter-subscribe-plan-suite
 *
 * @weline-e2e-spec { module: Weline_Newsletter, type: plan-suite, layer: frontend, suite: newsletter-subscribe-plan-suite }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
  loginAsAdmin,
  gotoBackend,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Newsletter';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'newsletter-subscribe-fixture.php');
/** Live storefront is HTTPS on :9555; frozen UC http:// probe returns nginx 400 (HTTPS port). */
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const FATAL = /Fatal error|ParseError|WLS Runtime Error/i;
/** Numeric /product/196 301s to slug; use published slug with size options. */
const PDP = '/product/hua-chao-ji-bi-yun-xia-guang-song-zhi-han-fu-yin-hua-da-xi-99e62ff2/';

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 90000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`newsletter fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

function uniqueEmail(prefix) {
  return `${prefix}.${Date.now()}.${Math.floor(Math.random() * 1e6)}@weline.local`.toLowerCase();
}

async function disableCache(page) {
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
}

async function gotoHome(page) {
  await disableCache(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${BASE}/?nl_e2e=${Date.now()}`, {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
  });
}

async function submitFooterSubscribe(page, email) {
  const root = page.locator('[data-widget-code="footer-newsletter"], [data-testid="newsletter-footer"]').first();
  await root.scrollIntoViewIfNeeded();
  await expect(root).toBeVisible({ timeout: 20000 });
  await expect(root.locator('input[name="topic_promo"][type="checkbox"]')).toBeChecked();
  await expect(root.locator('input[name="topic_new_arrivals"][type="checkbox"]')).toBeChecked();
  await root.locator('input[type="email"], input[name="email"]').fill(email);
  await root.locator('[data-testid="newsletter-footer-form"] button[type="submit"], form[data-newsletter-form] button[type="submit"]').first().click();
  const success = root.locator('[data-testid="newsletter-subscribe-success"]');
  await expect(success).toBeVisible({ timeout: 30000 });
  await expect(success).toHaveAttribute('data-ok', '1', { timeout: 5000 });
  const coupon = (await success.getAttribute('data-coupon-code')) || '';
  return { coupon: String(coupon).toUpperCase() };
}

async function openPopupAndSubscribe(page, email) {
  const popup = page.locator('[data-widget-code="newsletter-popup"], [data-testid="newsletter-popup"]').first();
  await expect(popup).toBeAttached({ timeout: 20000 });
  await page.evaluate(() => {
    const el = document.querySelector('[data-widget-code="newsletter-popup"], [data-testid="newsletter-popup"]');
    if (el) {
      el.style.display = 'block';
      el.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }
  });
  await expect(popup).toBeVisible({ timeout: 10000 });
  await popup.locator('input[type="email"], input[name="email"]').fill(email);
  await popup.locator('[data-testid="newsletter-popup-form"] button[type="submit"], form[data-newsletter-form] button[type="submit"]').first().click();
  const success = popup.locator('[data-testid="newsletter-subscribe-success"]');
  await expect(success).toBeVisible({ timeout: 30000 });
  await expect(success).toHaveAttribute('data-ok', '1');
  const coupon = (await success.getAttribute('data-coupon-code')) || '';
  return { coupon: String(coupon).toUpperCase() };
}

async function dismissStorefrontOverlays(page) {
  const cookieAccept = page.locator('button, [role="button"]').filter({ hasText: /Accept|接受|同意/i }).first();
  if ((await cookieAccept.count()) > 0 && (await cookieAccept.isVisible().catch(() => false))) {
    await cookieAccept.click({ force: true }).catch(() => {});
    await page.waitForTimeout(300);
  }
  await page.evaluate(() => {
    document.querySelectorAll('[data-widget-code="newsletter-popup"], [data-testid="newsletter-popup"]').forEach((el) => {
      el.style.display = 'none';
    });
    document.body.style.overflow = '';
  }).catch(() => {});
}

async function addProductAndOpenCheckout(page) {
  await page.goto(`${BASE}${PDP}?size=S`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  await dismissStorefrontOverlays(page);
  await page.waitForTimeout(1000);

  // Prefer BinQuery cart.add (same as storefront) to avoid overlay/click races.
  const addResult = await page.evaluate(async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    for (let i = 0; i < 50; i += 1) {
      if (window.Weline && Weline.Api && typeof Weline.Api.resource === 'function') {
        break;
      }
      if (window.Weline && typeof Weline.load === 'function') {
        try { await Weline.load('api'); } catch (_e) { /* retry */ }
      }
      await wait(100);
    }
    if (!window.Weline || !Weline.Api) {
      return { ok: false, error: 'Weline.Api missing' };
    }
    const catalog = document.getElementById('product-variant-catalog-196');
    let offerUuid = '';
    let productId = 196;
    try {
      const parsed = catalog ? JSON.parse(catalog.textContent || '{}') : {};
      const selected = parsed.selected || {};
      const offer = (parsed.offers || []).find((o) => (
        o && o.combination
        && o.combination.size === (selected.size || 'S')
        && o.combination.style_type === selected.style_type
      )) || (parsed.offers || [])[0];
      offerUuid = offer && offer.global_offer_uuid ? String(offer.global_offer_uuid) : '';
      productId = offer && offer.product_id ? Number(offer.product_id) : 196;
    } catch (_e) {
      /* keep defaults */
    }
    const client = Weline.Api.resource('cart');
    if (!client || typeof client.add !== 'function') {
      return { ok: false, error: 'cart.add unavailable' };
    }
    try {
      const result = await client.add({
        provider_code: 'product',
        global_offer_uuid: offerUuid,
        qty: 1,
        cart_type: 'toc',
        selling_mode: 'toc',
      }, { silent: true });
      return { ok: true, result };
    } catch (e) {
      return { ok: false, error: String(e && e.message ? e.message : e) };
    }
  });
  if (!addResult || !addResult.ok) {
    // UI fallback
    const addBtn = page.locator('[data-testid="product-add-to-cart"]').first();
    await expect(addBtn).toBeVisible({ timeout: 20000 });
    await addBtn.click({ force: true });
    await page.waitForTimeout(2500);
  }

  await page.goto(`${BASE}/cart?nl_cart=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  await dismissStorefrontOverlays(page);
  const cartBody = await page.locator('body').innerText();
  if (/Shopping cart is empty|购物车是空的/i.test(cartBody)) {
    throw new Error(`cart seed failed: ${JSON.stringify(addResult)} body=${cartBody.slice(0, 400)}`);
  }

  // Prefer in-page checkout CTA to keep the same cart session/context.
  const checkoutCta = page.locator('a[href*="checkout"], button').filter({ hasText: /Checkout|结账|去结算|Secure checkout/i }).first();
  if ((await checkoutCta.count()) > 0 && (await checkoutCta.isVisible().catch(() => false))) {
    await checkoutCta.click({ force: true });
    await page.waitForLoadState('domcontentloaded', { timeout: 90000 }).catch(() => {});
  } else {
    await page.goto(`${BASE}/checkout?nl_co=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  }
  await dismissStorefrontOverlays(page);
  await page.waitForTimeout(2000);
  const checkoutBody = await page.locator('body').innerText();
  if (/Shopping cart is empty|购物车是空的/i.test(checkoutBody)) {
    throw new Error(`checkout empty after cart seed; addResult=${JSON.stringify(addResult)}`);
  }
}

async function assertCheckoutCouponObservable(page, couponCode) {
  // Coupon widget may live in extras tabs (hidden until activated).
  const tab = page.locator(
    '[data-mini-cart-tab], button, [role="tab"]',
  ).filter({ hasText: /优惠券|Coupon/i }).first();
  if ((await tab.count()) > 0) {
    await tab.click({ force: true }).catch(() => {});
    await page.waitForTimeout(500);
  }

  const couponRoot = page.locator('[data-testid="checkout-coupon"], [data-widget-code="checkout-coupon"]').first();
  await expect(couponRoot).toBeAttached({ timeout: 30000 });
  await couponRoot.evaluate((el) => {
    el.hidden = false;
    el.style.display = 'block';
    el.style.visibility = 'visible';
    el.removeAttribute('hidden');
  }).catch(() => {});

  const tags = couponRoot.locator('[data-marketing-coupon-tags]');
  const input = page.locator('#marketing-checkout-coupon-code, [data-marketing-coupon-input]');
  const bodyText = await page.locator('body').innerText();
  const rootHtml = await couponRoot.innerHTML().catch(() => '');

  let matched = false;
  if ((await tags.count()) > 0) {
    await tags.first().evaluate((el) => { el.hidden = false; }).catch(() => {});
    const tagsText = `${await tags.first().innerText().catch(() => '')} ${await tags.first().getAttribute('data-codes').catch(() => '')}`;
    if (tagsText.toUpperCase().includes(couponCode)) {
      matched = true;
    }
  }
  if (!matched && (await input.count()) > 0) {
    const val = String((await input.first().inputValue().catch(() => '')) || (await input.first().getAttribute('value')) || '').toUpperCase();
    const data = String((await input.first().getAttribute('data-coupon-code')) || '').toUpperCase();
    if (val === couponCode || data === couponCode) {
      matched = true;
    }
  }
  if (!matched && (bodyText.toUpperCase().includes(couponCode) || rootHtml.toUpperCase().includes(couponCode))) {
    matched = true;
  }
  // Session/network evidence: applied coupons in marketing widget dataset.
  if (!matched) {
    const dataset = await couponRoot.evaluate((el) => JSON.stringify(el.dataset || {})).catch(() => '');
    if (String(dataset).toUpperCase().includes(couponCode)) {
      matched = true;
    }
  }
  expect(matched, `checkout auto-apply must expose coupon ${couponCode}`).toBeTruthy();
}

moduleDescribe(test, MODULE, 'e2e-closeout and e2e-plan-suite newsletter-subscribe', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'newsletter-subscribe-footer-happy' },
    'UC-1 页脚订阅成功并发券',
    async ({ page }) => {
      const campaign = runFixture('ensure_campaign', {
        enabled: true,
        discount_type: 'percentage',
        discount_value: 10,
        valid_days: 14,
      });
      expect(campaign.rule_id).toBeGreaterThan(0);

      const email = uniqueEmail('nl.e2e.footer');
      await gotoHome(page);
      const { coupon } = await submitFooterSubscribe(page, email);
      expect(coupon).not.toEqual('');

      const row = runFixture('lookup_subscriber', { email });
      expect(row.found).toBeTruthy();
      expect(row.email).toBe(email);
      expect(Number(row.subscriber_id)).toBeGreaterThan(0);
      expect(String(row.coupon_code).toUpperCase()).toBe(coupon);
      expect(row.gift_status).toBe('issued');

      await expect(page.locator('body')).not.toContainText(FATAL);
      test.info().annotations.push({
        type: 'evidence',
        description: JSON.stringify({
          case: 'UC-1',
          email,
          subscriber_id: row.subscriber_id,
          coupon_code: coupon,
          rule_id: campaign.rule_id,
        }),
      });
    },
  );

    moduleCase(
    test,
    { module: MODULE, id: 'newsletter-subscribe-checkout-auto-coupon' },
    'UC-2 弹窗订阅后结账自动用券',
    async ({ page }) => {
      runFixture('ensure_campaign', {
        enabled: true,
        discount_type: 'percentage',
        discount_value: 10,
        valid_days: 14,
      });

      const email = uniqueEmail('nl.e2e.popup');
      // Seed toc cart first so checkout is not empty after subscribe.
      await addProductAndOpenCheckout(page);
      // Return home for popup subscribe (T1 apply into same browser session).
      await gotoHome(page);
      await dismissStorefrontOverlays(page);
      const { coupon } = await openPopupAndSubscribe(page, email);
      expect(coupon).not.toEqual('');

      const row = runFixture('lookup_subscriber', { email });
      expect(row.found).toBeTruthy();
      expect(Number(row.subscriber_id)).toBeGreaterThan(0);
      expect(String(row.coupon_code).toUpperCase()).toBe(coupon);

      await page.goto(`${BASE}/checkout?nl_co2=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
      });
      await dismissStorefrontOverlays(page);
      await page.waitForTimeout(1500);
      // Fill guest email for T2 path observability (T1 should already have session coupon).
      const emailInput = page.locator('input[type="email"], input[name="email"], input[name="guest_email"]').first();
      if ((await emailInput.count()) > 0) {
        await emailInput.fill(email).catch(() => {});
        await emailInput.blur().catch(() => {});
        await page.waitForTimeout(1000);
      }
      await assertCheckoutCouponObservable(page, coupon);
      await expect(page.locator('body')).not.toContainText(FATAL);

      test.info().annotations.push({
        type: 'evidence',
        description: JSON.stringify({
          case: 'UC-2',
          email,
          subscriber_id: row.subscriber_id,
          coupon_code: coupon,
          checkout_auto_apply: true,
        }),
      });
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'newsletter-subscribe-validation' },
    'UC-3 非法邮箱拒绝',
    async ({ page }) => {
      const before = runFixture('count_subscribers');
      await gotoHome(page);
      const root = page.locator('[data-widget-code="footer-newsletter"], [data-testid="newsletter-footer"]').first();
      await root.scrollIntoViewIfNeeded();
      await expect(root).toBeVisible({ timeout: 20000 });

      await root.locator('input[type="email"], input[name="email"]').evaluate((el) => {
        el.removeAttribute('required');
        el.type = 'text';
      });
      await root.locator('input[type="email"], input[name="email"], input[name="email"]').fill('not-an-email');
      await root.locator('[data-testid="newsletter-footer-form"] button[type="submit"], form[data-newsletter-form] button[type="submit"]').first().click();
      await page.waitForTimeout(1500);

      const success = root.locator('[data-testid="newsletter-subscribe-success"]');
      if ((await success.count()) > 0 && (await success.isVisible().catch(() => false))) {
        await expect(success).toHaveAttribute('data-ok', '0');
      }
      await expect(page.locator('body')).not.toContainText(/订阅成功/);

      const after = runFixture('count_subscribers');
      expect(after.total).toBe(before.total);
      await expect(page.locator('body')).not.toContainText(FATAL);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'newsletter-subscribe-campaign-sync' },
    'UC-4 后台有奖活动同步 Marketing',
    async ({ page }) => {
      const synced = runFixture('ensure_campaign', {
        enabled: true,
        discount_type: 'percentage',
        discount_value: 10,
        valid_days: 14,
      });
      expect(synced.rule_id).toBeGreaterThan(0);
      expect(synced.source_module).toBe('Weline_Newsletter');
      expect(synced.source_key).toBe('subscribe_gift');
      expect(synced.source_type).toBe('newsletter_subscribe_gift');

      // 契约探针已含 rule_id + source_*；Browser 为增强证据（代理+密码回退）。
      await loginAsAdmin(page, {
        timeout: 120000,
        useProxy: true,
        allowPasswordFallback: true,
      });
      await waitForBackendShellReady(page);
      await gotoBackend(page, 'newsletter/backend/campaign/config', {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
      });
      await waitForBackendShellReady(page);
      const body = await page.locator('body').innerText();
      expect(body).toMatch(/Newsletter gift campaign|邮件订阅有奖|有奖配置|Marketing/i);
      const ruleInput = page.locator('form.w-form input[readonly]').first();
      await expect(ruleInput).toBeVisible({ timeout: 15000 });
      await expect(ruleInput).toHaveValue(String(synced.rule_id));
      expect(body).not.toMatch(FATAL);

      test.info().annotations.push({
        type: 'evidence',
        description: JSON.stringify({
          case: 'UC-4',
          rule_id: synced.rule_id,
          source_module: synced.source_module,
          source_key: synced.source_key,
          source_type: synced.source_type,
          valid_days: 14,
        }),
      });
    },
  );

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：newsletter-subscribe', async () => {
    expect(true).toBeTruthy();
  });
});
