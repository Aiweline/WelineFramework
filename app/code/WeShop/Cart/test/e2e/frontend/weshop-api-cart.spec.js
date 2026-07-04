// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

test.describe('WeShop cart API bridge', () => {
  test('guest cart REST endpoints block mutations but keep mini-cart readable', async ({ browser }) => {
    // Create a completely new browser context for this test
    const newContext = await browser.newContext();
    const newPage = await newContext.newPage();

    try {
      await gotoFrontend(newPage, '/customer/account/login', {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
        settleMs: 800,
      });
      await newPage.request.get('/customer/account/logout', { failOnStatusCode: false });

      const payload = await newPage.evaluate(async () => {
        const parseJson = async (response) => {
          const text = await response.text();
          try {
            return JSON.parse(text);
          } catch (error) {
            return null;
          }
        };

        const addResponse = await fetch('/cart/frontend/api/add', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            product_id: 1,
            qty: 1,
          }),
        });
        const addJson = await parseJson(addResponse);

        const updateResponse = await fetch('/cart/frontend/api/update', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            item_id: 1,
            quantity: 2,
          }),
        });
        const updateJson = await parseJson(updateResponse);

        const removeResponse = await fetch('/cart/frontend/api/remove', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            item_id: 1,
          }),
        });
        const removeJson = await parseJson(removeResponse);

        const miniItemsResponse = await fetch('/cart/frontend/api/mini-items', {
          method: 'GET',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        const miniJson = await parseJson(miniItemsResponse);

        return {
          add: {
            ok: addResponse.ok,
            status: addResponse.status,
            json: addJson,
          },
          update: {
            ok: updateResponse.ok,
            status: updateResponse.status,
            json: updateJson,
          },
          remove: {
            ok: removeResponse.ok,
            status: removeResponse.status,
            json: removeJson,
          },
          mini: {
            ok: miniItemsResponse.ok,
            status: miniItemsResponse.status,
            json: miniJson,
          },
        };
      });

      // Mutating APIs are auth-protected for guests.
      // Different runtimes may normalize guard responses to 200/401, but success must be false.
      expect([200, 401]).toContain(payload.add.status);
      expect(payload.add.json).toBeTruthy();
      expect(payload.add.json.success).toBe(false);

      expect(payload.update.ok).toBeTruthy();
      expect(payload.update.status).toBe(200);
      expect(payload.update.json).toBeTruthy();
      expect(payload.update.json.success).toBe(false);

      expect(payload.remove.ok).toBeTruthy();
      expect(payload.remove.status).toBe(200);
      expect(payload.remove.json).toBeTruthy();
      expect(payload.remove.json.success).toBe(false);

      // mini-items remains readable with empty cart for guests.
      expect(payload.mini.ok).toBeTruthy();
      expect(payload.mini.status).toBe(200);
      expect(payload.mini.json.success).toBe(true);
      expect(payload.mini.json.items).toEqual([]);
      expect(payload.mini.json.totals.count).toBe(0);
    } finally {
      await newContext.close();
    }
  });
});
