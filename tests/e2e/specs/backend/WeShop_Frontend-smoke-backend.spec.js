// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'WeShop_Frontend';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function isIgnorableConsoleError(text) {
  if (/Failed to load resource: the server responded with a status of (404|500)/i.test(text)) {
    return true;
  }
  // Playwright backend proxy runs on 127.0.0.1:3999, font/API requests to 127.0.0.1 may trigger expected CORS noise.
  if (/from origin 'https:\/\/127\.0\.0\.1:3999' has been blocked by CORS policy/i.test(text)) {
    return true;
  }
  if (/^Failed to load resource: net::ERR_FAILED$/i.test(text)) {
    return true;
  }
  return false;
}

function bindRuntimeErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => {
    errors.push(String(error && error.message ? error.message : error));
  });
  page.on('console', (msg) => {
    if (msg.type() !== 'error') {
      return;
    }
    const text = msg.text();
    if (isIgnorableConsoleError(text)) {
      return;
    }
    errors.push(text);
  });
  return errors;
}

test.describe('WeShop_Frontend backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test('renders at least one backend route without fatal runtime errors', async ({ page }) => {
    test.setTimeout(240000);
    await loginAsAdmin(page, { timeout: 180000 });
    const runtimeErrors = bindRuntimeErrors(page);

    const routesTried = [
      buildModuleBackendRoute(MODULE),
      buildModuleBackendRoute(MODULE, 'index'),
      buildModuleBackendRoute(MODULE, 'dashboard'),
      'admin',
      'admin/dashboard',
    ];

    /** @type {Array<{route: string, ok: boolean, reason: string}>} */
    const attempts = [];

    for (const route of routesTried) {
      try {
        await gotoBackend(page, route, {
          timeout: 60000,
          settleMs: 1000,
        });

        const body = page.locator('body');
        await expect(body).toBeVisible();
        const text = await body.innerText();
        if (FATAL_PATTERN.test(text)) {
          attempts.push({ route, ok: false, reason: 'fatal-pattern-detected' });
          continue;
        }

        attempts.push({ route, ok: true, reason: 'ok' });
      } catch (error) {
        const reason = error instanceof Error ? error.message : String(error);
        attempts.push({ route, ok: false, reason });
      }
    }

    test.info().annotations.push({
      type: 'routesTried',
      description: JSON.stringify(attempts),
    });

    const successful = attempts.filter(item => item.ok);
    expect(successful.length, `All candidate routes failed: ${JSON.stringify(attempts, null, 2)}`).toBeGreaterThan(0);
    // Debug breadcrumb for CI logs and local diagnosis.
    console.log(`[${MODULE}] routesTried=${JSON.stringify(attempts)}`);
    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
  });
});
