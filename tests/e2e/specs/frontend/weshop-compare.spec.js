// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop compare clean routes', () => {
  test('compare page redirects guests without runtime failures', async ({ page }) => {
    await gotoFrontend(page, '/compare', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/login/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
  });

  test('compare add and remove endpoints return guest redirect payloads', async ({ page }) => {
    await gotoFrontend(page, '/compare', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    const addPayload = await page.evaluate(async () => {
      const response = await fetch('/compare/add', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ product_id: 1 }),
      });

      return {
        ok: response.ok,
        status: response.status,
        json: await response.json(),
      };
    });

    expect(addPayload.ok).toBeTruthy();
    expect(addPayload.status).toBe(200);
    expect(addPayload.json.success).toBeFalsy();
    expect(String(addPayload.json.message || '')).toMatch(/Please log in|请先登录/i);
    expect(String(addPayload.json.data?.redirect_url || '')).toMatch(/customer\/account\/login/i);

    const removePayload = await page.evaluate(async () => {
      const response = await fetch('/compare/remove', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ compare_id: 1 }),
      });

      return {
        ok: response.ok,
        status: response.status,
        json: await response.json(),
      };
    });

    expect(removePayload.ok).toBeTruthy();
    expect(removePayload.status).toBe(200);
    expect(removePayload.json.success).toBeFalsy();
    expect(String(removePayload.json.message || '')).toMatch(/Please log in|请先登录/i);
    expect(String(removePayload.json.data?.redirect_url || '')).toMatch(/customer\/account\/login/i);
    await expectNoRuntimeError(page);
  });

  test('compare mutation endpoints reject non-post methods', async ({ page }) => {
    await gotoFrontend(page, '/compare', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    const [addPayload, removePayload] = await page.evaluate(async () => {
      const callJson = async (url) => {
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        return {
          ok: response.ok,
          status: response.status,
          json: await response.json(),
        };
      };

      return Promise.all([callJson('/compare/add'), callJson('/compare/remove')]);
    });

    for (const payload of [addPayload, removePayload]) {
      expect(payload.ok).toBeTruthy();
      expect(payload.status).toBe(200);
      expect(payload.json.success).toBeFalsy();
      expect(String(payload.json.message || '')).toMatch(/Invalid request method|请求方法无效/i);
    }
    await expectNoRuntimeError(page);
  });

  test('guest non-ajax remove post is redirected to login page', async ({ page }) => {
    await gotoFrontend(page, '/compare', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    const nonAjaxRemove = await page.evaluate(async () => {
      const response = await fetch('/compare/remove', {
        method: 'POST',
        headers: {
          'Accept': 'text/html,application/xhtml+xml',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: 'compare_id=1',
        redirect: 'follow',
      });

      return {
        ok: response.ok,
        status: response.status,
        finalUrl: response.url,
      };
    });

    expect(nonAjaxRemove.ok).toBeTruthy();
    expect(nonAjaxRemove.status).toBe(200);
    expect(String(nonAjaxRemove.finalUrl || '')).toMatch(/customer\/account\/login/i);
    await expectNoRuntimeError(page);
  });
});
