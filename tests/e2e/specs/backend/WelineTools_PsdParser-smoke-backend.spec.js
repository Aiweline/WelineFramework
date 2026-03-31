// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const FATAL_PATTERN =
  /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;
const BACKEND_BOOTSTRAP_INFRA_PATTERN =
  /Backend E2E session bootstrap failed: DB Error|SQLSTATE\[08006\]|\bconnection to server at "127\.0\.0\.1", port 5432 failed\b|timeout expired|Admin session bootstrap mode ".*" did not reach an authenticated backend page|Timeout \d+ms exceeded/i;

function withTimeout(promise, timeoutMs, reason) {
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error(reason)), timeoutMs);
    }),
  ]);
}

test.describe('WelineTools_PsdParser backend smoke', () => {
  test.describe.configure({ retries: 0 });

  test.beforeEach(async ({ page }) => {
    try {
      await withTimeout(
        loginAsAdmin(page, {
          refreshRuntime: true,
          timeout: 30000,
          settleMs: 800,
        }),
        35000,
        'Backend E2E session bootstrap failed: timeout expired',
      );
    } catch (error) {
      const message = String(error?.message || error || '');
      test.skip(
        BACKEND_BOOTSTRAP_INFRA_PATTERN.test(message),
        `Backend infra unavailable for PSD parser smoke: ${message}`,
      );
      throw error;
    }
  });

  test('renders PSD parser backend page without fatal errors', async ({ page }) => {
    const errors = [];
    page.on('pageerror', err => errors.push(String(err?.message || err)));
    page.on('console', msg => {
      if (msg.type() !== 'error') return;
      const text = msg.text();
      if (/Failed to load resource: the server responded with a status of 404/i.test(text)) return;
      errors.push(text);
    });

    const routeCandidates = [
      buildModuleBackendRoute('WelineTools_PsdParser'),
      buildModuleBackendRoute('WelineTools_PsdParser', 'index'),
      buildModuleBackendRoute('WelineTools_PsdParser', 'index', 'index'),
      'psdparser/backend/index/index',
      'psdparser/backend/index',
      'psdparser/backend',
    ];

    let lastRoute = '';
    let lastBodyText = '';
    let navError = null;
    let rendered = false;

    for (const route of routeCandidates) {
      lastRoute = route;
      try {
        await gotoBackend(page, route, {
          timeout: 90000,
          waitUntil: 'domcontentloaded',
          settleMs: 1200,
        });
      } catch (error) {
        navError = error;
        const message = String(error?.message || error);
        if (FATAL_PATTERN.test(message)) {
          throw error;
        }
        continue;
      }

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 30000 });
      lastBodyText = await body.innerText().catch(() => '');
      test.skip(
        BACKEND_BOOTSTRAP_INFRA_PATTERN.test(lastBodyText),
        'Backend infra unavailable during page render for PSD parser smoke.',
      );
      if (FATAL_PATTERN.test(lastBodyText)) {
        continue;
      }

      await expect(body).not.toContainText(FATAL_PATTERN);
      await expect(page.locator('#drop-zone')).toHaveCount(1);
      await expect(page.locator('#layer-panel')).toHaveCount(1);
      await expect(page.locator('#view-panel')).toHaveCount(1);
      await expect(page.locator('#lang-select')).toHaveCount(1);
      expect(page.url()).not.toContain('/admin/login');
      expect(errors, errors.join('\n')).toEqual([]);
      rendered = true;
      break;
    }

    if (!rendered) {
      throw new Error(
        `WelineTools_PsdParser backend smoke failed. ` +
          `lastRoute=${lastRoute}, navError=${String(navError?.message || navError || 'none')}, ` +
          `bodyExcerpt=${String(lastBodyText).slice(0, 500)}`,
      );
    }
  });
});
