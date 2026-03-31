const path = require('path');
const fs = require('fs');

const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../../../../../../tests/e2e/framework');

const MODULE_NAME = 'Agent_CursorBase';

function toKebabCase(input) {
  let s = String(input ?? '').trim();
  s = s.replace(/_/g, '-');
  // FooBar -> Foo-Bar, ABCFoo -> ABC-Foo
  s = s.replace(/([a-z0-9])([A-Z])/g, '$1-$2');
  s = s.replace(/([A-Z]+)([A-Z][a-z0-9]+)/g, '$1-$2');
  s = s.replace(/-+/g, '-');
  return s.toLowerCase();
}

function getBackendGroupsWithIndex(basePath) {
  const backendRoot = path.join(basePath, 'Controller', 'Backend');
  if (!fs.existsSync(backendRoot) || !fs.statSync(backendRoot).isDirectory()) {
    return [];
  }

  const entries = fs.readdirSync(backendRoot, { withFileTypes: true });
  const groups = [];

  for (const entry of entries) {
    if (!entry.isDirectory()) {
      continue;
    }

    const groupDir = entry.name;
    const indexPhp = path.join(backendRoot, groupDir, 'Index.php');
    if (fs.existsSync(indexPhp) && fs.statSync(indexPhp).isFile()) {
      groups.push(toKebabCase(groupDir));
    }
  }

  // Sort for deterministic candidate ordering.
  return groups.sort();
}

test.describe('Agent_CursorBase backend smoke', () => {
  test.describe.configure({ retries: 1 });

  async function loginAsAdminWithRetry(page, options = {}) {
    const attempts = Number(options.attempts ?? 3);
    const timeout = Number(options.timeout ?? 90000);

    let lastError = null;

    for (let i = 0; i < attempts; i++) {
      try {
        await loginAsAdmin(page, { timeout });
        return;
      } catch (error) {
        lastError = error;
        const message = String(error?.message ?? error);
        const isRetryable =
          /ERR_CONNECTION_REFUSED/i.test(message)
          || /Target page, context or browser has been closed/i.test(message)
          || /net::ERR_CONNECTION_REFUSED/i.test(message);

        if (!isRetryable || i === attempts - 1) {
          throw error;
        }

        await page.waitForTimeout(3000);
      }
    }

    // Should never reach here; keep for type clarity.
    throw lastError || new Error('loginAsAdminWithRetry failed');
  }

  test('renders at least one backend route without PHP errors', async ({ page }) => {
    const basePath = path.resolve(__dirname, '../../../');

    await loginAsAdminWithRetry(page, { attempts: 3, timeout: 90000 });

    const groups = getBackendGroupsWithIndex(basePath);

    // "第一批" candidates: 默认先尝试前 5 个 group（失败后可调大）。
    const batchSize = 5;
    const groupsBatch = groups.slice(0, batchSize);

    const forbiddenRe = /WLS Runtime Error|ParseError|syntax error|Fatal error/i;

    const candidates = [
      buildModuleBackendRoute(MODULE_NAME),
      ...groupsBatch.map(group => buildModuleBackendRoute(MODULE_NAME, group)),
    ];

    const routesTried = [];
    let renderedRoute = null;

    for (const route of candidates) {
      const response = await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
      });

      const status = response?.status?.() ?? null;
      routesTried.push({ route, status });

      if (status === 404) {
        continue;
      }

      const bodyText = await page.locator('body').textContent().catch(() => '');
      if (forbiddenRe.test(bodyText || '')) {
        continue;
      }

      renderedRoute = route;
      break;
    }

    // 给出 routesTried 便于定位；按要求最终失败信息使用固定文案。
    console.log('[Agent_CursorBase-smoke] routesTried:', JSON.stringify(routesTried));

    if (!renderedRoute) {
      throw new Error('no candidate routes rendered successfully');
    }

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const bodyText = await body.textContent();
    expect(forbiddenRe.test(bodyText || '')).toBe(false);
  });
});

