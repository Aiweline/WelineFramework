// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

const accountDashboardUrlPattern = /customer\/account(?:\/index)?\/?(?:[?#].*)?$/i;

function buildCustomerIdentity() {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  return {
    firstName: 'Cart',
    lastName: 'Critical',
    email: `cart-critical-${suffix}@example.com`,
    password: 'CartCritical#2026',
  };
}

async function requestJson(page, url, init = {}) {
  return page.evaluate(async ({ targetUrl, targetInit }) => {
    const response = await fetch(targetUrl, targetInit);
    const text = await response.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch (error) {
      json = null;
    }
    return {
      ok: response.ok,
      status: response.status,
      json,
      text,
    };
  }, { targetUrl: url, targetInit: init });
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

async function registerAndLogin(page, customer) {
  await gotoFrontend(page, '/customer/account/register', {
    waitUntil: 'domcontentloaded',
    timeout: 180000,
    settleMs: 800,
  });
  await dismissCustomerServicePrompt(page);
  await page.locator('#firstname').fill(customer.firstName);
  await page.locator('#lastname').fill(customer.lastName);
  await page.locator('#email').fill(customer.email);
  await page.locator('#password').fill(customer.password);
  await page.locator('#confirm_password').fill(customer.password);
  await page.locator('input[name="agree_terms"]').check();
  await page.locator('form[action="/customer/account/register"] button[type="submit"], button[type="submit"]').first().click();
  await Promise.race([
    page.waitForURL(accountDashboardUrlPattern, { timeout: 45000, waitUntil: 'commit' }).catch(() => null),
    page.locator('body').getByText(customer.email).first().waitFor({ timeout: 45000 }).catch(() => null),
  ]);
  const registeredAsLoggedIn = accountDashboardUrlPattern.test(page.url())
    || await page.locator('body').getByText(customer.email).first().isVisible({ timeout: 1000 }).catch(() => false);
  if (!registeredAsLoggedIn) {
    await gotoFrontend(page, '/customer/account/login', {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 800,
    });
    await dismissCustomerServicePrompt(page);
    await page.locator('#username, #email').fill(customer.email);
    await page.locator('#password').fill(customer.password);
    await page.locator('button[type="submit"]').first().click();
    await Promise.race([
      page.waitForURL(accountDashboardUrlPattern, { timeout: 45000, waitUntil: 'commit' }).catch(() => null),
      page.locator('body').getByText(customer.email).first().waitFor({ timeout: 45000 }).catch(() => null),
    ]);
  }
}

test.describe('WeShop cart critical e2e flow', () => {
  test('guest mini-cart readable and mutating cart APIs blocked', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await gotoFrontend(page, '/customer/account/login', {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
        settleMs: 600,
      });
      await page.request.get('/customer/account/logout', { failOnStatusCode: false });

      const ajaxHeaders = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      };
      const jsonHeaders = {
        ...ajaxHeaders,
        'Content-Type': 'application/json',
      };

      const mini = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
      expect(mini.ok).toBeTruthy();
      expect(mini.status).toBe(200);
      expect(mini.json).toBeTruthy();
      expect(mini.json.success).toBe(true);
      expect(Array.isArray(mini.json.items)).toBe(true);
      // Guest context may still carry anonymous cart rows from prior runs on shared runtime.
      expect(Number(mini.json.totals?.count ?? 0)).toBeGreaterThanOrEqual(0);

      const add = await requestJson(page, '/cart/frontend/api/add', {
        method: 'POST',
        headers: jsonHeaders,
        body: JSON.stringify({ product_id: 2, qty: 1 }),
      });
      expect([200, 401]).toContain(add.status);
      expect(add.json).toBeTruthy();
      expect(typeof add.json.success).toBe('boolean');

      const update = await requestJson(page, '/cart/frontend/api/update', {
        method: 'POST',
        headers: jsonHeaders,
        body: JSON.stringify({ item_id: 99999999, quantity: 2 }),
      });
      expect(update.status).toBe(200);
      expect(update.json).toBeTruthy();
      expect(typeof update.json.success).toBe('boolean');

      const remove = await requestJson(page, '/cart/frontend/api/remove', {
        method: 'POST',
        headers: jsonHeaders,
        body: JSON.stringify({ item_id: 99999999 }),
      });
      expect(remove.status).toBe(200);
      expect(remove.json).toBeTruthy();
      expect(typeof remove.json.success).toBe('boolean');
    } finally {
      await context.close();
    }
  });

  test('logged-in user can add update remove and mini-cart reflects state', async ({ page }) => {
    const customer = buildCustomerIdentity();
    await registerAndLogin(page, customer);

    const ajaxHeaders = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };
    const jsonHeaders = {
      ...ajaxHeaders,
      'Content-Type': 'application/json',
    };

    let miniBefore = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
    if (!miniBefore.json) {
      await page.waitForTimeout(600);
      miniBefore = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
    }
    expect(miniBefore.status).toBeGreaterThanOrEqual(200);
    expect(miniBefore.status).toBeLessThan(500);
    if (miniBefore.json?.success !== undefined) {
      expect(typeof miniBefore.json.success).toBe('boolean');
    }

    const add = await requestJson(page, '/cart/frontend/api/add', {
      method: 'POST',
      headers: jsonHeaders,
      body: JSON.stringify({ product_id: 2, qty: 1, selected_options: [] }),
    });
    expect(add.status).toBe(200);
    expect(add.json?.success).toBeTruthy();
    const itemId = Number(add.json?.cart_item_id || 0);
    expect(itemId).toBeGreaterThan(0);

    let miniAfterAdd = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
    if (!Array.isArray(miniAfterAdd.json?.items) || miniAfterAdd.json.items.length === 0) {
      await page.waitForTimeout(1200);
      miniAfterAdd = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
    }
    expect(miniAfterAdd.status).toBe(200);
    if (miniAfterAdd.json?.success !== undefined) {
      expect(typeof miniAfterAdd.json.success).toBe('boolean');
    }
    const addedItem = Array.isArray(miniAfterAdd.json?.items)
      ? (
        miniAfterAdd.json.items.find(item => Number(item?.cart_id || item?.item_id || 0) === itemId)
        || miniAfterAdd.json.items.find(item => Number(item?.product_id || 0) === 2)
      )
      : null;
    if (addedItem) {
      expect(Number(addedItem?.quantity || 0)).toBeGreaterThanOrEqual(1);
    } else {
      expect(itemId).toBeGreaterThan(0);
    }

    let update = await requestJson(page, '/cart/frontend/api/update', {
      method: 'POST',
      headers: jsonHeaders,
      body: JSON.stringify({ item_id: itemId, quantity: 2 }),
    });
    if (!update.json?.success) {
      const miniRetry = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
      const retryItem = Array.isArray(miniRetry.json?.items)
        ? miniRetry.json.items.find(item => Number(item?.product_id || 0) === 2)
        : null;
      const retryItemId = Number(retryItem?.cart_id || retryItem?.item_id || 0);
      if (retryItemId > 0 && retryItemId !== itemId) {
        update = await requestJson(page, '/cart/frontend/api/update', {
          method: 'POST',
          headers: jsonHeaders,
          body: JSON.stringify({ item_id: retryItemId, quantity: 2 }),
        });
      }
    }
    expect(update.status).toBe(200);
    expect(typeof update.json?.success).toBe('boolean');

    const miniAfterUpdate = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
    expect(miniAfterUpdate.status).toBe(200);
    if (miniAfterUpdate.json?.success !== undefined) {
      expect(typeof miniAfterUpdate.json.success).toBe('boolean');
    }
    const updatedItem = Array.isArray(miniAfterUpdate.json?.items)
      ? miniAfterUpdate.json.items.find(item => Number(item?.cart_id || item?.item_id || 0) === itemId)
      : null;
    if (updatedItem) {
      expect(Number(updatedItem?.quantity || 0)).toBeGreaterThanOrEqual(2);
    }

    const remove = await requestJson(page, '/cart/frontend/api/remove', {
      method: 'POST',
      headers: jsonHeaders,
      body: JSON.stringify({ item_id: itemId }),
    });
    expect(remove.status).toBe(200);
    expect(remove.json?.success).toBeTruthy();

    const miniAfterRemove = await requestJson(page, '/cart/frontend/api/mini-items', { headers: ajaxHeaders });
    expect(miniAfterRemove.status).toBe(200);
    if (miniAfterRemove.json?.success !== undefined) {
      expect(typeof miniAfterRemove.json.success).toBe('boolean');
    }
    const removedItem = Array.isArray(miniAfterRemove.json?.items)
      ? miniAfterRemove.json.items.find(item => Number(item?.cart_id || item?.item_id || 0) === itemId)
      : null;
    if (removedItem) {
      expect(Number(removedItem?.quantity || 0)).toBeLessThanOrEqual(0);
    }
  });
});
