// @weline-e2e-runtime fallback
// @weline-e2e-transport direct

const { test, expect, gotoFrontend } = require('../../framework');

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(/404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught Error/i, {
    timeout: 15000,
  });
}

test.describe('WeShop recently viewed clean routes', () => {
  test('recently-viewed routes protect guests without runtime failures', async ({ page }) => {
    await gotoFrontend(page, '/recently-viewed', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/login/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    await gotoFrontend(page, '/recently-viewed/remove', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
      settleMs: 800,
    });

    await expect(page).toHaveURL(/customer\/account\/login/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expectNoRuntimeError(page);

    const removePayload = await page.evaluate(async () => {
      const response = await fetch('/recently-viewed/remove', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ view_id: 1 }),
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
    expect(String(removePayload.json.message || '').trim().length).toBeGreaterThan(0);
    expect(String(removePayload.json.data?.redirect_url || '')).toMatch(/customer\/account\/login/i);
  });
});
