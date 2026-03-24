// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop compliance clean routes', () => {
  test('guest compliance routes resolve without 404 or 500', async ({ page }) => {
    await gotoFrontend(page, '/compliance', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    expect(/customer\/account\/login|\/compliance(?:$|[?#])/i.test(page.url())).toBeTruthy();

    await gotoFrontend(page, '/compliance/privacy', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    expect(/\/compliance\/privacy|customer\/account\/login/i.test(page.url())).toBeTruthy();

    await gotoFrontend(page, '/compliance/consent', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);
    expect(/customer\/account\/login|\/compliance\/consent(?:$|[?#])/i.test(page.url())).toBeTruthy();

    const savePayload = await page.evaluate(async () => {
      const response = await fetch('/compliance/consent/save?consent_type=privacy&is_accepted=1', {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

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
        text,
        json,
      };
    });

    expect(savePayload.ok).toBeTruthy();
    expect(savePayload.status).toBe(200);
    expect(savePayload.json).not.toBeNull();
    expect(savePayload.json.success).toBeFalsy();
    expect(String(savePayload.json.data?.redirect_url || '')).toMatch(/customer\/account\/login/i);
  });
});
