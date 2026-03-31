// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
} = require('../../framework');

const MODULE = 'WeShop_RecentlyViewed';
const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

async function openFirstHealthyRoute(page, routes) {
  const tried = [];

  for (const route of routes) {
    tried.push(route);
    try {
      await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
      });
    } catch (error) {
      continue;
    }

    const text = await page.locator('body').innerText();
    if (!FATAL_PATTERN.test(text)) {
      return { route, tried };
    }
  }

  throw new Error(`All candidate routes failed. Tried: ${tried.join(', ')}`);
}

test.describe('WeShop_RecentlyViewed backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test('TC-01: renders recently viewed backend page without PHP fatal errors', async ({ page }) => {
    await loginAsAdmin(page);

    const routes = [
      'recentlyViewed/index',
      'recentlyViewed',
      buildModuleBackendRoute(MODULE, 'recentlyViewed'),
      buildModuleBackendRoute(MODULE, 'recentlyviewed'),
      buildModuleBackendRoute(MODULE, 'recently-viewed'),
    ];

    const result = await openFirstHealthyRoute(page, routes);
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
    expect(result.tried.length).toBeGreaterThan(0);
  });
});
