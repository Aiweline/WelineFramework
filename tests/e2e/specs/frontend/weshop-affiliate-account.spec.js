// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const accountDashboardUrlPattern = /customer\/account(?:\/index)?\/?(?:[?#].*)?$/i;
const affiliateAccountUrlPattern = /customer\/account(?:\/index)?\/?#affiliate$/i;

function buildCustomerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'Affiliate',
    lastName: 'Account',
    email: `affiliate-account-${suffix}@example.com`,
    password: 'AffiliateAccount#2026',
  };
}

function buildReferralBuyerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'Referral',
    lastName: 'Buyer',
    email: `affiliate-referral-buyer-${suffix}@example.com`,
    password: 'ReferralBuyer#2026',
  };
}

function buildWelineReferralBuyerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'WelineReferral',
    lastName: 'Buyer',
    email: `weline-referral-buyer-${suffix}@example.com`,
    password: 'ReferralBuyer#2026',
  };
}

function maskEmailAddress(email) {
  const [local, domain] = String(email || '').split('@');
  if (!local || !domain) {
    return String(email || '');
  }

  if (local.length <= 2) {
    return `${local.charAt(0)}***@${domain}`;
  }

  return `${local.slice(0, 2)}***${local.slice(-1)}@${domain}`;
}

function expectRecordedCurrencyCode(value, label) {
  const code = String(value || '');
  expect(code, `${label} currency_code`).toMatch(/^[A-Z]{3}$/);
  return code;
}

async function dismissCustomerServicePrompt(page) {
  const modal = page.locator('#cs-bind-modal');
  const isVisible = await modal.isVisible({ timeout: 1000 }).catch(() => false);
  if (!isVisible) {
    return;
  }

  const closeButton = modal.locator('.cs-modal-close, .cs-btn-secondary').first();
  if (await closeButton.isVisible({ timeout: 1000 }).catch(() => false)) {
    await closeButton.click();
    await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  }
}

async function registerCustomer(page, customer) {
  await gotoFrontend(page, '/customer/account/register', {
    waitUntil: 'domcontentloaded',
    timeout: 120000,
    settleMs: 800,
  });

  await dismissCustomerServicePrompt(page);
  await page.locator('#firstname').fill(customer.firstName);
  await page.locator('#lastname').fill(customer.lastName);
  await page.locator('#email').fill(customer.email);
  await page.locator('#password').fill(customer.password);
  await page.locator('#confirm_password').fill(customer.password);
  await page.locator('input[name="agree_terms"]').check();

  await Promise.all([
    page.waitForURL(accountDashboardUrlPattern, {
      timeout: 120000,
      waitUntil: 'commit',
    }).catch(() => null),
    page.locator('form[action*="/customer/account/register"] button[type="submit"], button[type="submit"]').first().click(),
  ]);

  const isAccountPage = accountDashboardUrlPattern.test(page.url());
  const emailVisible = await page.locator('body').getByText(customer.email).first().isVisible({ timeout: 5000 }).catch(() => false);
  expect(isAccountPage || emailVisible).toBeTruthy();
}

async function loginCustomer(page, username, password) {
  await gotoFrontend(page, '/customer/account/login', {
    waitUntil: 'domcontentloaded',
    timeout: 120000,
    settleMs: 800,
  });

  await dismissCustomerServicePrompt(page);
  await expectNoRuntimeError(page);

  await page.locator('#username, #email, input[name="username"], input[name="email"], input[type="email"]').first().fill(username);
  await page.locator('#password, input[name="password"], input[type="password"]').first().fill(password);

  await Promise.all([
    page.waitForURL(accountDashboardUrlPattern, {
      timeout: 120000,
      waitUntil: 'commit',
    }).catch(() => null),
    page.locator('form[action*="/customer/account/login"] button[type="submit"], button[type="submit"]').first().click(),
  ]);

  await page.waitForTimeout(1000);
  expect(accountDashboardUrlPattern.test(page.url())).toBeTruthy();
}

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i, {
    timeout: 15000,
  });
}

async function callAffiliateApi(page, operation, params = {}) {
  return callResourceApi(page, 'affiliate', operation, params);
}

async function callResourceApi(page, resource, operation, params = {}) {
  await page.waitForFunction(() => Boolean(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'), null, {
    timeout: 30000,
  });

  return page.evaluate(async ({ resource, operation, params }) => {
    const api = await window.Weline.Api.resource(resource);
    if (!api || typeof api[operation] !== 'function') {
      return {
        success: false,
        message: `Missing ${resource} operation: ${operation}`,
      };
    }

    return api[operation](params);
  }, { resource, operation, params });
}

async function postJson(page, url, data) {
  const response = await page.request.post(url, {
    failOnStatusCode: false,
    data,
    headers: {
      Accept: 'application/json',
    },
  });
  const text = await response.text();
  let payload = {};
  try {
    payload = text ? JSON.parse(text) : {};
  } catch (error) {
    throw new Error(`${url} did not return JSON. status=${response.status()} body=${text.slice(0, 300)}`);
  }

  return { response, payload };
}

function backendUrl(backendRoot, route) {
  return new URL(route, `${String(backendRoot).replace(/\/+$/, '')}/`).toString();
}

function expectPositiveAmount(value, label) {
  const amount = Number(value || 0);
  if (!(amount > 0)) {
    throw new Error(`${label} should be positive, got ${value}`);
  }
  return amount;
}

function expectMaskedEmail(value) {
  expect(String(value || '')).toMatch(/^[^@\s]*\*\*\*[^@\s]*@example\.com$/);
}

async function resolveShareableProductId(page) {
  const candidates = [1075, 1074, 1073, 1072, 1071, 1070, 2, 1];
  for (const productId of candidates) {
    const response = await page.request.get(`/product/frontend/product/view?id=${productId}`, {
      timeout: 90000,
      maxRedirects: 3,
    }).catch(() => null);
    if (!response || response.status() >= 500 || /weshop\/product\/list/i.test(response.url())) {
      continue;
    }
    return productId;
  }

  throw new Error('No shareable product detail page is available for affiliate link validation.');
}

async function createAffiliateBuyerOrder(browser, referralLink, productShareUrl, productId, buyer) {
  const buyerContext = await browser.newContext();
  const buyerPage = await buyerContext.newPage();
  let orderId = 0;

  try {
    await buyerPage.goto(referralLink, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await buyerPage.locator('#firstname').fill(buyer.firstName);
    await buyerPage.locator('#lastname').fill(buyer.lastName);
    await buyerPage.locator('#email').fill(buyer.email);
    await buyerPage.locator('#password').fill(buyer.password);
    await buyerPage.locator('#confirm_password').fill(buyer.password);
    await buyerPage.locator('input[name="agree_terms"]').check();
    await Promise.all([
      buyerPage.waitForURL(accountDashboardUrlPattern, {
        timeout: 120000,
        waitUntil: 'commit',
      }).catch(() => null),
      buyerPage.locator('form[action*="/customer/account/register"] button[type="submit"], button[type="submit"]').first().click(),
    ]);
    expect(accountDashboardUrlPattern.test(buyerPage.url())).toBeTruthy();

    await buyerPage.goto(productShareUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await expectNoRuntimeError(buyerPage);
    await expect(buyerPage).toHaveURL(/\/product\//i, { timeout: 30000 });

    const addToCart = await callResourceApi(buyerPage, 'cart', 'add', {
      product_id: productId,
      qty: 1,
    });
    expect(addToCart.success || addToCart.data?.success).toBeTruthy();

    const address = {
      firstname: buyer.firstName,
      lastname: buyer.lastName,
      street: '100 Affiliate Smoke Street',
      city: 'Shanghai',
      region: 'Shanghai',
      postcode: '200000',
      telephone: '13800138000',
      country_id: 'CN',
      email: buyer.email,
    };
    const checkoutPayload = {
      checkout_mode: 'customer',
      shipping_address_id: 0,
      billing_address_id: 0,
      billing_same_as_shipping: true,
      shipping_address: address,
      billing_address: address,
    };
    const checkoutMethods = await postJson(buyerPage, '/checkout/methods', checkoutPayload);
    expect(checkoutMethods.response.status()).toBeLessThan(500);
    expect(checkoutMethods.payload.success).toBeTruthy();
    const shippingMethods = checkoutMethods.payload.data.shipping_methods || [];
    const paymentMethods = checkoutMethods.payload.data.payment_methods || [];
    const shippingMethod = shippingMethods[0]?.code
      || shippingMethods[0]?.method_code
      || 'flat_rate';
    const paymentMethod = paymentMethods.find((method) => (method.code || method.method_code) === 'manual_transfer')?.code
      || paymentMethods[0]?.code
      || paymentMethods[0]?.method_code
      || 'manual_transfer';

    const placed = await postJson(buyerPage, '/checkout/place-order', {
      ...checkoutPayload,
      shipping_method: shippingMethod,
      payment_method: paymentMethod,
      notification_channels: [],
    });
    expect(placed.response.status()).toBeLessThan(500);
    expect(placed.payload.success).toBeTruthy();
    orderId = Number(placed.payload.order_id || placed.payload.data?.order_id || 0);
    if (!orderId) {
      throw new Error(`Missing order_id from checkout/place-order: ${JSON.stringify(placed.payload)}`);
    }
    expect(orderId).toBeGreaterThan(0);
  } finally {
    await buyerContext.close();
  }

  return orderId;
}

async function markOrderPaid(browser, orderId) {
  const adminContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  try {
    const backendRoot = await loginAsAdmin(adminPage, { timeout: 120000 });
    const paymentUrl = backendUrl(
      backendRoot,
      buildModuleBackendRoute('WeShop_Order', 'order', 'update-payment-status')
    );
    const paymentResponse = await adminPage.request.post(paymentUrl, {
      failOnStatusCode: false,
      form: {
        id: orderId,
        payment_status: 'paid',
        back_url: buildModuleBackendRoute('WeShop_Order', 'order'),
      },
    });
    expect(paymentResponse.status()).toBeLessThan(500);
  } finally {
    await adminContext.close();
  }
}

async function expectAffiliateReportSearch(page, reportKey, tableSelector, positiveQuery) {
  const search = page.locator(`[data-affiliate-report-search="${reportKey}"]`);
  await expect(search).toBeVisible({ timeout: 30000 });
  await search.fill(positiveQuery);
  const visibleRows = page.locator(`${tableSelector} tbody tr:visible`);
  await expect(visibleRows.first()).toContainText(positiveQuery, { timeout: 15000 });
  await expect(page.locator(`[data-affiliate-report-empty="${reportKey}"]`)).not.toBeVisible();

  await search.fill(`no-match-${Date.now()}`);
  await expect(page.locator(`[data-affiliate-report-empty="${reportKey}"]`)).toBeVisible({ timeout: 15000 });
  await search.fill('');
  await expect(page.locator(`[data-affiliate-report-empty="${reportKey}"]`)).not.toBeVisible({ timeout: 15000 });
}

test.describe('WeShop affiliate account-center integration', () => {
  test('redirects standalone entry, exports usable absolute links, and records share/click stats', async ({ page, browser }) => {
    test.setTimeout(240000);

    const customer = buildCustomerIdentity();
    await registerCustomer(page, customer);

    await gotoFrontend(page, '/affiliate', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1200,
    });

    await expect(page).toHaveURL(affiliateAccountUrlPattern, { timeout: 30000 });
    await expectNoRuntimeError(page);

    const affiliateNav = page.locator('[data-account-nav-link][data-section="affiliate"]');
    await expect(affiliateNav).toBeVisible({ timeout: 15000 });

    const affiliatePanel = page.locator('[data-affiliate-account-panel]');
    await expect(affiliatePanel).toBeVisible({ timeout: 30000 });

    const referralInput = page.locator('[data-affiliate-referral-link]');
    await expect(referralInput).toBeVisible({ timeout: 15000 });
    await expect(referralInput).toHaveValue(/^https?:\/\/[^/]+\/customer\/account\/register\?ref=REF/i, {
      timeout: 15000,
    });
    const referralLink = await referralInput.inputValue();
    expect(referralLink).not.toMatch(/^\/register\?ref=/i);

    await page.locator('[data-affiliate-copy-link]').click();
    await expect(page.locator('[data-affiliate-copy-status]')).not.toHaveText('', { timeout: 15000 });

    await page.locator('[data-affiliate-custom-target]').fill('/catalog/category');
    await page.locator('[data-affiliate-generate-link]').click();
    const generatedCustomLink = page.locator('[data-affiliate-generated-link]');
    await expect(generatedCustomLink).toHaveValue(/^https?:\/\/[^/]+\/affiliate\/redirect\?code=AFF/i, {
      timeout: 30000,
    });

    const referralContext = await browser.newContext();
    const referralPage = await referralContext.newPage();
    const referralBuyer = buildReferralBuyerIdentity();
    try {
      const response = await referralPage.goto(referralLink, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
      });
      expect(response && response.status()).toBeLessThan(500);
      await expect(referralPage).toHaveURL(/customer\/account\/register\?ref=REF/i, { timeout: 30000 });
      await expect(referralPage.locator('form[action*="/customer/account/register"], #email').first()).toBeVisible({
        timeout: 30000,
      });
      await expect(referralPage.locator('input[name="ref"]')).toHaveValue(/REF/i);
      await expectNoRuntimeError(referralPage);

      await referralPage.locator('#firstname').fill(referralBuyer.firstName);
      await referralPage.locator('#lastname').fill(referralBuyer.lastName);
      await referralPage.locator('#email').fill(referralBuyer.email);
      await referralPage.locator('#password').fill(referralBuyer.password);
      await referralPage.locator('#confirm_password').fill(referralBuyer.password);
      await referralPage.locator('input[name="agree_terms"]').check();
      await Promise.all([
        referralPage.waitForURL(accountDashboardUrlPattern, {
          timeout: 120000,
          waitUntil: 'commit',
        }).catch(() => null),
        referralPage.locator('form[action*="/customer/account/register"] button[type="submit"], button[type="submit"]').first().click(),
      ]);
      expect(accountDashboardUrlPattern.test(referralPage.url())).toBeTruthy();
    } finally {
      await referralContext.close();
    }

    const summaryBefore = await callAffiliateApi(page, 'getMySummary');
    expect(summaryBefore.success).toBeTruthy();

    const productId = await resolveShareableProductId(page);
    const channel = `e2e_${Date.now()}`;
    const links = await callAffiliateApi(page, 'getProductShareLinks', {
      product_id: productId,
      channel,
    });
    expect(links.success).toBeTruthy();
    expect(links.data && links.data.share_code).toMatch(/^AFF/i);
    expect(links.data && links.data.tracking_url).toMatch(/^https?:\/\/[^/]+\/affiliate\/redirect\?code=AFF/i);

    const outbound = await callAffiliateApi(page, 'recordOutboundShare', {
      share_code: links.data.share_code,
      platform: 'copy',
    });
    expect(outbound.success).toBeTruthy();

    const clickContext = await browser.newContext();
    const clickPage = await clickContext.newPage();
    try {
      const response = await clickPage.goto(links.data.tracking_url, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
      });
      expect(response && response.status()).toBeLessThan(500);
      await expect(clickPage).toHaveURL(/\/product\/[^/?#]+/i, { timeout: 30000 });
      await expectNoRuntimeError(clickPage);
    } finally {
      await clickContext.close();
    }

    await page.waitForTimeout(6000);
    const summaryAfter = await callAffiliateApi(page, 'getMySummary');
    expect(summaryAfter.success).toBeTruthy();
    expect(Number(summaryAfter.data.share_count || 0)).toBeGreaterThanOrEqual(Number(summaryBefore.data.share_count || 0) + 1);
    expect(Number(summaryAfter.data.outbound_share_count || 0)).toBeGreaterThanOrEqual(Number(summaryBefore.data.outbound_share_count || 0) + 1);
    expect(Number(summaryAfter.data.click_count || 0)).toBeGreaterThanOrEqual(Number(summaryBefore.data.click_count || 0) + 1);

    const referredCustomers = summaryAfter.data.referred_customers || [];
    expect(referredCustomers.length).toBeGreaterThanOrEqual(1);
    const referredBuyer = referredCustomers.find((item) => String(item.email_masked || '').endsWith('@example.com'));
    expect(referredBuyer).toBeTruthy();
    expect(JSON.stringify(referredCustomers)).not.toContain(referralBuyer.email);
    expectMaskedEmail(referredBuyer.email_masked);
    expect(referredBuyer.registered_at || '').not.toBe('');

    const shareLinks = summaryAfter.data.share_links || [];
    const registrationLink = shareLinks.find((item) => item.channel === 'registration' && Number(item.registered_count || 0) >= 1);
    expect(registrationLink).toBeTruthy();
    expect(String(registrationLink.tracking_url || '')).toMatch(/^https?:\/\/[^/]+\/affiliate\/redirect\?code=AFF/i);
    const homepageLink = shareLinks.find((item) => item.channel === 'homepage' && String(item.tracking_url || '').startsWith('http'));
    expect(homepageLink).toBeTruthy();
    const customLinkValue = await generatedCustomLink.inputValue();
    const customLink = shareLinks.find((item) => item.tracking_url === customLinkValue);
    expect(customLink).toBeTruthy();
    expect(String(customLink.channel || '')).toMatch(/^custom_/);
    const productLink = shareLinks.find((item) => item.share_code === links.data.share_code);
    expect(productLink).toBeTruthy();
    expect(Number(productLink.outbound_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(productLink.click_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(productLink.view_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(productLink.product_id || 0)).toBe(productId);

    const promotedProducts = summaryAfter.data.promoted_products || [];
    const promotedProduct = promotedProducts.find((item) => Number(item.product_id || 0) === productId);
    expect(promotedProduct).toBeTruthy();
    expect(Number(promotedProduct.share_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(promotedProduct.click_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(promotedProduct.view_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Array.isArray(summaryAfter.data.affiliate_orders)).toBeTruthy();
    expect(Array.isArray(summaryAfter.data.commission_ledger)).toBeTruthy();

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
    await expect(page.locator('[data-affiliate-share-links-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-referred-customers-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-products-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-orders-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-commissions-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-share-links-table] tbody tr')).toHaveCount(4, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-referred-customers-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-products-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-account-panel]')).not.toContainText(referralBuyer.email);
    await expect(page.locator('[data-affiliate-account-panel]')).toContainText(/[^@]*\*\*\*[^@]*@example\.com/);
  });

  test('renders affiliate account navigation in English locale', async ({ page }) => {
    test.setTimeout(180000);

    const customer = buildCustomerIdentity();
    await registerCustomer(page, customer);

    const currentOrigin = new URL(page.url()).origin;
    await page.context().addCookies([{
      name: 'WELINE_USER_LANG',
      value: 'en_US',
      url: currentOrigin,
    }]);
    await page.evaluate(() => {
      window.localStorage.setItem('weline_user_lang', 'en_US');
    });

    await gotoFrontend(page, '/CNY/en_US/customer/account/index#affiliate', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1200,
    });

    await expectNoRuntimeError(page);
    const affiliateNav = page.locator('[data-account-nav-link][data-section="affiliate"]');
    await expect(affiliateNav).toBeVisible({ timeout: 15000 });
    await expect(affiliateNav).toContainText('My Affiliate');
    await expect(affiliateNav).toContainText('Shares, conversions, and commission');
    await expect(affiliateNav).not.toContainText('我的分销');
    await expect(affiliateNav).not.toContainText('分享、转化与佣金');

    const affiliatePanel = page.locator('[data-affiliate-account-panel]');
    await expect(affiliatePanel).toBeVisible({ timeout: 30000 });
    await expect(affiliatePanel).toContainText('Affiliate Center');
    await expect(affiliatePanel).toContainText('Total commission');
    await expect(affiliatePanel).toContainText('Withdrawal records');
    await expect(affiliatePanel).toContainText('Link-level performance');
    await expect(affiliatePanel).toContainText('Referred registered customers');
    await expect(affiliatePanel).toContainText('Affiliate orders');
    await expect(affiliatePanel).toContainText('Commission ledger');
    await expect(affiliatePanel).not.toContainText('分销中心');
    await expect(affiliatePanel).not.toContainText('提现记录');
    await expect(affiliatePanel).not.toContainText('分销订单');
  });

  test('tracks promoted order payment and withdrawal record in account center', async ({ page, browser }) => {
    test.setTimeout(360000);

    const affiliate = buildCustomerIdentity();
    await registerCustomer(page, affiliate);
    await gotoFrontend(page, '/customer/account/index#affiliate', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1200,
    });

    await expect(page.locator('[data-affiliate-account-panel]')).toBeVisible({ timeout: 30000 });
    const referralLink = await page.locator('[data-affiliate-referral-link]').inputValue();
    const before = await callAffiliateApi(page, 'getMySummary');
    expect(before.success).toBeTruthy();

    const productId = await resolveShareableProductId(page);
    const productShare = await callAffiliateApi(page, 'getProductShareLinks', {
      product_id: productId,
      channel: `paid_order_${Date.now()}`,
    });
    expect(productShare.success).toBeTruthy();
    expect(productShare.data.tracking_url).toMatch(/^https?:\/\/[^/]+\/affiliate\/redirect\?code=AFF/i);

    const buyerContext = await browser.newContext();
    const buyerPage = await buyerContext.newPage();
    const buyer = buildReferralBuyerIdentity();
    let orderId = 0;
    try {
      await buyerPage.goto(referralLink, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await buyerPage.locator('#firstname').fill(buyer.firstName);
      await buyerPage.locator('#lastname').fill(buyer.lastName);
      await buyerPage.locator('#email').fill(buyer.email);
      await buyerPage.locator('#password').fill(buyer.password);
      await buyerPage.locator('#confirm_password').fill(buyer.password);
      await buyerPage.locator('input[name="agree_terms"]').check();
      await Promise.all([
        buyerPage.waitForURL(accountDashboardUrlPattern, {
          timeout: 120000,
          waitUntil: 'commit',
        }).catch(() => null),
        buyerPage.locator('form[action*="/customer/account/register"] button[type="submit"], button[type="submit"]').first().click(),
      ]);
      expect(accountDashboardUrlPattern.test(buyerPage.url())).toBeTruthy();

      await buyerPage.goto(productShare.data.tracking_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await expectNoRuntimeError(buyerPage);
      const addToCart = await callResourceApi(buyerPage, 'cart', 'add', {
        product_id: productId,
        qty: 1,
      });
      expect(addToCart.success || addToCart.data?.success).toBeTruthy();

      const address = {
        firstname: buyer.firstName,
        lastname: buyer.lastName,
        street: '100 Affiliate Smoke Street',
        city: 'Shanghai',
        region: 'Shanghai',
        postcode: '200000',
        telephone: '13800138000',
        country_id: 'CN',
        email: buyer.email,
      };
      const checkoutPayload = {
        checkout_mode: 'customer',
        shipping_address_id: 0,
        billing_address_id: 0,
        billing_same_as_shipping: true,
        shipping_address: address,
        billing_address: address,
      };
      const checkoutMethods = await postJson(buyerPage, '/checkout/methods', checkoutPayload);
      expect(checkoutMethods.response.status()).toBeLessThan(500);
      expect(checkoutMethods.payload.success).toBeTruthy();
      const shippingMethods = checkoutMethods.payload.data.shipping_methods || [];
      const paymentMethods = checkoutMethods.payload.data.payment_methods || [];
      const shippingMethod = shippingMethods[0]?.code
        || shippingMethods[0]?.method_code
        || 'flat_rate';
      const paymentMethod = paymentMethods.find((method) => (method.code || method.method_code) === 'manual_transfer')?.code
        || paymentMethods[0]?.code
        || paymentMethods[0]?.method_code
        || 'manual_transfer';

      const placed = await postJson(buyerPage, '/checkout/place-order', {
        ...checkoutPayload,
        shipping_method: shippingMethod,
        payment_method: paymentMethod,
        notification_channels: [],
      });
      expect(placed.response.status()).toBeLessThan(500);
      expect(placed.payload.success).toBeTruthy();
      orderId = Number(placed.payload.order_id || placed.payload.data?.order_id || 0);
      if (!orderId) {
        throw new Error(`Missing order_id from checkout/place-order: ${JSON.stringify(placed.payload)}`);
      }
      expect(orderId).toBeGreaterThan(0);
    } finally {
      await buyerContext.close();
    }

    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    try {
      const backendRoot = await loginAsAdmin(adminPage, { timeout: 120000 });
      const paymentUrl = backendUrl(
        backendRoot,
        buildModuleBackendRoute('WeShop_Order', 'order', 'update-payment-status')
      );
      const paymentResponse = await adminPage.request.post(paymentUrl, {
        failOnStatusCode: false,
        form: {
          id: orderId,
          payment_status: 'paid',
          back_url: buildModuleBackendRoute('WeShop_Order', 'order'),
        },
      });
      expect(paymentResponse.status()).toBeLessThan(500);
    } finally {
      await adminContext.close();
    }

    let paidSummary = null;
    for (let attempt = 0; attempt < 20; attempt += 1) {
      paidSummary = await callAffiliateApi(page, 'getMySummary');
      if (
        paidSummary.success
        && Number(paidSummary.data.order_count || 0) > Number(before.data.order_count || 0)
        && Number(paidSummary.data.paid_count || 0) > Number(before.data.paid_count || 0)
        && Number(paidSummary.data.approved_commission || 0) > Number(before.data.approved_commission || 0)
      ) {
        break;
      }
      await page.waitForTimeout(1500);
    }
    expect(paidSummary.success).toBeTruthy();
    expect(Number(paidSummary.data.order_count || 0)).toBeGreaterThan(Number(before.data.order_count || 0));
    expect(Number(paidSummary.data.paid_count || 0)).toBeGreaterThan(Number(before.data.paid_count || 0));
    expect(Number(paidSummary.data.approved_commission || 0)).toBeGreaterThan(Number(before.data.approved_commission || 0));
    const paidShareLink = (paidSummary.data.share_links || []).find((row) => row.share_code === productShare.data.share_code);
    expect(paidShareLink).toBeTruthy();
    expect(Number(paidShareLink.product_id || 0)).toBe(productId);
    expect(Number(paidShareLink.click_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.view_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.add_to_cart_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.order_count || 0)).toBe(1);
    expectPositiveAmount(paidShareLink.commission_amount, 'share link commission');
    const shareLinkCurrency = expectRecordedCurrencyCode(paidShareLink.currency_code, 'share link');

    const paidCustomer = (paidSummary.data.referred_customers || []).find((row) => String(row.email_masked || '').endsWith('@example.com'));
    expect(paidCustomer).toBeTruthy();
    expectMaskedEmail(paidCustomer.email_masked);
    expect(Number(paidCustomer.order_count || 0)).toBe(1);
    expect(Number(paidCustomer.paid_order_count || 0)).toBe(1);
    expectPositiveAmount(paidCustomer.total_amount, 'referred customer total amount');
    expectPositiveAmount(paidCustomer.paid_amount, 'referred customer paid amount');
    const referredCustomerCurrency = expectRecordedCurrencyCode(paidCustomer.currency_code, 'referred customer');
    expect(String(paidCustomer.registered_at || '')).not.toBe('');
    expect(String(paidCustomer.last_order_at || '')).not.toBe('');

    const paidProduct = (paidSummary.data.promoted_products || []).find((row) => Number(row.product_id || 0) === productId);
    expect(paidProduct).toBeTruthy();
    expect(Number(paidProduct.share_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.click_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.view_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.add_to_cart_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.order_count || 0)).toBe(1);
    expectPositiveAmount(paidProduct.base_amount, 'promoted product base amount');
    expectPositiveAmount(paidProduct.commission_amount, 'promoted product commission amount');
    const promotedProductCurrency = expectRecordedCurrencyCode(paidProduct.currency_code, 'promoted product');

    const paidOrder = (paidSummary.data.affiliate_orders || []).find((order) => Number(order.order_id) === orderId);
    expect(paidOrder).toBeTruthy();
    expect(String(paidOrder.increment_id || '')).not.toBe('');
    expectMaskedEmail(paidOrder.customer_email_masked);
    expectPositiveAmount(paidOrder.order_total, 'affiliate order total');
    expectPositiveAmount(paidOrder.base_amount, 'affiliate order base amount');
    expectPositiveAmount(paidOrder.commission_amount, 'affiliate order commission amount');
    const affiliateOrderCurrency = expectRecordedCurrencyCode(paidOrder.currency_code, 'affiliate order');
    expect(paidOrder.order_status).toBe('paid');
    expect(paidOrder.payment_status).toBe('paid');
    expect(paidOrder.commission_status).toBe('approved');
    expect(String(paidOrder.product_names || '')).not.toBe('');

    const paidLedger = (paidSummary.data.commission_ledger || []).find((row) => Number(row.order_id) === orderId && row.status === 'approved');
    expect(paidLedger).toBeTruthy();
    expect(String(paidLedger.increment_id || '')).toBe(String(paidOrder.increment_id || ''));
    expectMaskedEmail(paidLedger.customer_email_masked);
    expect(Number(paidLedger.product_id || 0)).toBe(productId);
    expectPositiveAmount(paidLedger.base_amount, 'commission ledger base amount');
    expectPositiveAmount(paidLedger.commission_rate, 'commission ledger rate');
    expectPositiveAmount(paidLedger.commission_amount, 'commission ledger amount');
    const commissionLedgerCurrency = expectRecordedCurrencyCode(paidLedger.currency_code, 'commission ledger');
    expect(paidLedger.reason).toBe('payment_paid');
    expect(new Set([
      shareLinkCurrency,
      referredCustomerCurrency,
      promotedProductCurrency,
      affiliateOrderCurrency,
      commissionLedgerCurrency,
    ]).size).toBe(1);

    const available = Number(paidSummary.data.available_commission || 0);
    expect(available).toBeGreaterThan(0);
    const requestedBefore = Number(paidSummary.data.withdrawal_summary?.requested_amount || 0);
    const withdrawAmount = Math.max(0.01, Math.min(available, 1));
    const withdrawal = await callAffiliateApi(page, 'requestWithdrawal', {
      amount: withdrawAmount,
      method: 'manual',
      account_label: 'bank 622288881234',
    });
    expect(withdrawal.success).toBeTruthy();
    expect(withdrawal.data.status).toBe('requested');
    expect(withdrawal.data.currency_code).toBe(affiliateOrderCurrency);
    expect(String(withdrawal.data.account_label || '')).toContain('***');

    const afterWithdrawal = await callAffiliateApi(page, 'getMySummary');
    expect(afterWithdrawal.success).toBeTruthy();
    expect(Number(afterWithdrawal.data.withdrawal_summary?.requested_amount || 0)).toBeCloseTo(requestedBefore + withdrawAmount, 2);
    expect(Number(afterWithdrawal.data.available_commission || 0)).toBeCloseTo(available - withdrawAmount, 2);
    const withdrawalRow = (afterWithdrawal.data.withdrawal_records || []).find((row) => Number(row.withdrawal_id || 0) === Number(withdrawal.data.withdrawal_id || 0));
    expect(withdrawalRow).toBeTruthy();
    expect(Number(withdrawalRow.amount || 0)).toBeCloseTo(withdrawAmount, 2);
    expect(withdrawalRow.status).toBe('requested');
    expect(withdrawalRow.currency_code).toBe(affiliateOrderCurrency);
    expect(String(withdrawalRow.account_label || '')).toContain('***');
    expect(String(withdrawalRow.requested_at || '')).not.toBe('');

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
    await expect(page.locator('[data-affiliate-share-links-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-referred-customers-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-products-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-orders-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-commissions-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-withdrawals-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-withdrawals-table]')).toContainText(String(withdrawal.data.currency_code || ''), { timeout: 15000 });
    await expect(page.locator('[data-affiliate-share-links-table] tbody tr')).toHaveCount(3, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-referred-customers-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-products-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-orders-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-commissions-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-withdrawals-table] tbody tr')).toHaveCount(1, { timeout: 30000 });
    await expect(page.locator('[data-affiliate-withdrawals-table]')).toContainText(/\*\*\*/);
    await expect(page.locator('[data-affiliate-account-panel]')).not.toContainText(buyer.email);
  });

  test('validates seeded weline affiliate attribution with another buyer', async ({ page, browser }) => {
    test.setTimeout(360000);

    await loginCustomer(page, 'weline', 'weline');
    await gotoFrontend(page, '/customer/account/index#affiliate', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 1200,
    });

    await expect(page.locator('[data-affiliate-account-panel]')).toBeVisible({ timeout: 30000 });
    const referralLink = await page.locator('[data-affiliate-referral-link]').inputValue();
    expect(referralLink).toMatch(/^https?:\/\/[^/]+\/customer\/account\/register\?ref=REF/i);
    const before = await callAffiliateApi(page, 'getMySummary');
    expect(before.success).toBeTruthy();

    const productId = await resolveShareableProductId(page);
    const productShare = await callAffiliateApi(page, 'getProductShareLinks', {
      product_id: productId,
      channel: `weline_fixed_${Date.now()}`,
    });
    expect(productShare.success).toBeTruthy();
    expect(productShare.data.tracking_url).toMatch(/^https?:\/\/[^/]+\/affiliate\/redirect\?code=AFF/i);

    const outbound = await callAffiliateApi(page, 'recordOutboundShare', {
      share_code: productShare.data.share_code,
      platform: 'copy',
    });
    expect(outbound.success).toBeTruthy();

    const buyer = buildWelineReferralBuyerIdentity();
    const expectedMaskedBuyerEmail = maskEmailAddress(buyer.email);
    const orderId = await createAffiliateBuyerOrder(
      browser,
      referralLink,
      productShare.data.tracking_url,
      productId,
      buyer
    );
    await markOrderPaid(browser, orderId);

    let paidSummary = null;
    for (let attempt = 0; attempt < 20; attempt += 1) {
      paidSummary = await callAffiliateApi(page, 'getMySummary');
      const orderRow = (paidSummary.data?.affiliate_orders || []).find((row) => Number(row.order_id) === orderId);
      const ledgerRow = (paidSummary.data?.commission_ledger || []).find((row) => Number(row.order_id) === orderId && row.status === 'approved');
      if (
        paidSummary.success
        && Number(paidSummary.data.order_count || 0) > Number(before.data.order_count || 0)
        && Number(paidSummary.data.paid_count || 0) > Number(before.data.paid_count || 0)
        && Number(paidSummary.data.approved_commission || 0) > Number(before.data.approved_commission || 0)
        && orderRow
        && ledgerRow
      ) {
        break;
      }
      await page.waitForTimeout(1500);
    }

    expect(paidSummary.success).toBeTruthy();
    expect(Number(paidSummary.data.order_count || 0)).toBeGreaterThan(Number(before.data.order_count || 0));
    expect(Number(paidSummary.data.paid_count || 0)).toBeGreaterThan(Number(before.data.paid_count || 0));
    expect(Number(paidSummary.data.approved_commission || 0)).toBeGreaterThan(Number(before.data.approved_commission || 0));

    const paidShareLink = (paidSummary.data.share_links || []).find((row) => row.share_code === productShare.data.share_code);
    expect(paidShareLink).toBeTruthy();
    expect(Number(paidShareLink.product_id || 0)).toBe(productId);
    expect(Number(paidShareLink.outbound_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.click_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.view_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.add_to_cart_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidShareLink.order_count || 0)).toBeGreaterThanOrEqual(1);
    expectPositiveAmount(paidShareLink.commission_amount, 'seeded weline share link commission');
    const shareLinkCurrency = expectRecordedCurrencyCode(paidShareLink.currency_code, 'seeded weline share link');

    const paidCustomer = (paidSummary.data.referred_customers || []).find((row) => row.email_masked === expectedMaskedBuyerEmail);
    expect(paidCustomer).toBeTruthy();
    expect(Number(paidCustomer.order_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidCustomer.paid_order_count || 0)).toBeGreaterThanOrEqual(1);
    expectPositiveAmount(paidCustomer.total_amount, 'seeded weline referred customer total amount');
    expectPositiveAmount(paidCustomer.paid_amount, 'seeded weline referred customer paid amount');
    const referredCustomerCurrency = expectRecordedCurrencyCode(paidCustomer.currency_code, 'seeded weline referred customer');

    const paidProduct = (paidSummary.data.promoted_products || []).find((row) => Number(row.product_id || 0) === productId);
    expect(paidProduct).toBeTruthy();
    expect(Number(paidProduct.click_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.view_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.add_to_cart_count || 0)).toBeGreaterThanOrEqual(1);
    expect(Number(paidProduct.order_count || 0)).toBeGreaterThanOrEqual(1);
    expectPositiveAmount(paidProduct.commission_amount, 'seeded weline promoted product commission');
    expect(String(paidProduct.currency_code || '')).toMatch(/^([A-Z]{3}|MIXED)$/);

    const paidOrder = (paidSummary.data.affiliate_orders || []).find((order) => Number(order.order_id) === orderId);
    expect(paidOrder).toBeTruthy();
    expectMaskedEmail(paidOrder.customer_email_masked);
    expect(paidOrder.customer_email_masked).toBe(expectedMaskedBuyerEmail);
    expect(paidOrder.payment_status).toBe('paid');
    expect(paidOrder.commission_status).toBe('approved');
    expectPositiveAmount(paidOrder.commission_amount, 'seeded weline order commission amount');
    const affiliateOrderCurrency = expectRecordedCurrencyCode(paidOrder.currency_code, 'seeded weline affiliate order');

    const paidLedger = (paidSummary.data.commission_ledger || []).find((row) => Number(row.order_id) === orderId && row.status === 'approved');
    expect(paidLedger).toBeTruthy();
    expect(paidLedger.customer_email_masked).toBe(expectedMaskedBuyerEmail);
    expect(paidLedger.reason).toBe('payment_paid');
    expectPositiveAmount(paidLedger.commission_amount, 'seeded weline commission ledger amount');
    const commissionLedgerCurrency = expectRecordedCurrencyCode(paidLedger.currency_code, 'seeded weline commission ledger');
    expect(new Set([
      shareLinkCurrency,
      referredCustomerCurrency,
      affiliateOrderCurrency,
      commissionLedgerCurrency,
    ]).size).toBe(1);

    const available = Number(paidSummary.data.available_commission || 0);
    expect(available).toBeGreaterThan(0);
    const withdrawal = await callAffiliateApi(page, 'requestWithdrawal', {
      amount: Math.max(0.01, Math.min(available, 1)),
      method: 'manual',
      account_label: 'bank 622288881234',
    });
    expect(withdrawal.success).toBeTruthy();
    expect(withdrawal.data.status).toBe('requested');
    expect(String(withdrawal.data.currency_code || '')).toMatch(/^([A-Z]{3}|MIXED)$/);
    expect(String(withdrawal.data.account_label || '')).toContain('***');

    const afterWithdrawal = await callAffiliateApi(page, 'getMySummary');
    expect(afterWithdrawal.success).toBeTruthy();
    const withdrawalRow = (afterWithdrawal.data.withdrawal_records || []).find((row) => Number(row.withdrawal_id || 0) === Number(withdrawal.data.withdrawal_id || 0));
    expect(withdrawalRow).toBeTruthy();
    expect(withdrawalRow.status).toBe('requested');
    expect(withdrawalRow.currency_code).toBe(withdrawal.data.currency_code);
    expect(String(withdrawalRow.account_label || '')).toContain('***');

    await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
    await expect(page.locator('[data-affiliate-orders-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-commissions-table]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('[data-affiliate-withdrawals-table]')).toBeVisible({ timeout: 30000 });
    for (const reportKey of ['withdrawals', 'share-links', 'referred-customers', 'products', 'orders', 'commissions']) {
      await expect(page.locator(`[data-affiliate-report-search="${reportKey}"]`)).toBeVisible({ timeout: 30000 });
      await expect(page.locator(`[data-affiliate-report-size="${reportKey}"]`)).toBeVisible({ timeout: 30000 });
      await expect(page.locator(`[data-affiliate-report-prev="${reportKey}"]`)).toBeVisible({ timeout: 30000 });
      await expect(page.locator(`[data-affiliate-report-next="${reportKey}"]`)).toBeVisible({ timeout: 30000 });
      await expect(page.locator(`[data-affiliate-report-summary="${reportKey}"]`)).toContainText(/显示|Showing/, { timeout: 30000 });
    }
    await expectAffiliateReportSearch(page, 'referred-customers', '[data-affiliate-referred-customers-table]', expectedMaskedBuyerEmail);
    await page.locator('[data-affiliate-report-search="referred-customers"]').fill(expectedMaskedBuyerEmail);
    await expect(page.locator('[data-affiliate-referred-customers-table]')).toContainText(affiliateOrderCurrency, { timeout: 15000 });
    await expectAffiliateReportSearch(page, 'orders', '[data-affiliate-orders-table]', expectedMaskedBuyerEmail);
    await page.locator('[data-affiliate-report-search="orders"]').fill(expectedMaskedBuyerEmail);
    await expect(page.locator('[data-affiliate-orders-table]')).toContainText(affiliateOrderCurrency, { timeout: 15000 });
    await expectAffiliateReportSearch(page, 'commissions', '[data-affiliate-commissions-table]', expectedMaskedBuyerEmail);
    await page.locator('[data-affiliate-report-search="commissions"]').fill(expectedMaskedBuyerEmail);
    await expect(page.locator('[data-affiliate-commissions-table]')).toContainText(affiliateOrderCurrency, { timeout: 15000 });
    await page.locator('[data-affiliate-report-size="share-links"]').selectOption('2');
    if (await page.locator('[data-affiliate-report-next="share-links"]').isEnabled()) {
      const firstPageSummary = await page.locator('[data-affiliate-report-summary="share-links"]').innerText();
      await page.locator('[data-affiliate-report-next="share-links"]').click();
      await expect(page.locator('[data-affiliate-report-summary="share-links"]')).not.toHaveText(firstPageSummary, { timeout: 15000 });
    }
    await expect(page.locator('[data-affiliate-orders-table]')).not.toContainText('暂无数据');
    await expect(page.locator('[data-affiliate-commissions-table]')).not.toContainText('暂无数据');
    await expect(page.locator('[data-affiliate-withdrawals-table]')).not.toContainText('暂无数据');
    await expect(page.locator('[data-affiliate-account-panel]')).not.toContainText(buyer.email);
    await expect(page.locator('[data-affiliate-account-panel]')).toContainText(expectedMaskedBuyerEmail);
  });
});
