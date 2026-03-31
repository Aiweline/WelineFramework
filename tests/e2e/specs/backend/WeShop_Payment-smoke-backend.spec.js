// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
  getModuleBackendRouter,
} = require('../../framework');

const MODULE_NAME = 'WeShop_Payment';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|Fatal error|syntax error|Uncaught|Call to undefined|Class .* not found/i;

function buildCandidateRoutes() {
  const backendRouter = getModuleBackendRouter(MODULE_NAME);

  return [
    buildModuleBackendRoute(MODULE_NAME, 'payment', 'index'),
    buildModuleBackendRoute(MODULE_NAME, 'payment'),
    `${backendRouter}/backend`,
    backendRouter,
  ];
}

async function openFirstHealthyRoute(page, routesTried) {
  const candidates = buildCandidateRoutes();

  for (const route of candidates) {
    routesTried.push(route);
    try {
      await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
      });
    } catch (error) {
      continue;
    }

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const bodyText = await body.innerText();
    if (!FATAL_PATTERN.test(bodyText)) {
      return route;
    }
  }

  throw new Error(`All candidate routes failed or contain fatal markers. Tried: ${routesTried.join(', ')}`);
}

test.describe('WeShop Payment backend smoke', () => {
  test('TC-01: renders backend payment page via candidate routes without fatal errors', async ({ page }) => {
    await loginAsAdmin(page);

    const routesTried = [];
    const matchedRoute = await openFirstHealthyRoute(page, routesTried);

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);

    expect(routesTried.length).toBeGreaterThan(0);
    expect(matchedRoute).toBeTruthy();
  });
});
