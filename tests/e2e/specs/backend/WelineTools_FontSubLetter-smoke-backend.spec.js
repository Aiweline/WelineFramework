// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'WelineTools_FontSubLetter';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

function bindRuntimeErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => {
    errors.push(String(error && error.message ? error.message : error));
  });
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    if (/Failed to load resource: the server responded with a status of 404/i.test(text)) {
      return;
    }
    errors.push(text);
  });
  return errors;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {Array<string>} routeCandidates
 */
async function gotoFirstNonFatal(page, routeCandidates) {
  let lastRoute = '';
  let lastBodyText = '';

  for (const route of routeCandidates) {
    lastRoute = route;
    try {
      await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
      });
      const bodyText = await page.locator('body').innerText().catch(() => '');
      lastBodyText = bodyText;
      if (!FATAL_PATTERN.test(bodyText)) {
        return route;
      }
    } catch (error) {
      lastBodyText = String(error && error.message ? error.message : error);
    }
  }

  throw new Error(
    `WelineTools_FontSubLetter backend smoke failed to find non-fatal route. `
    + `lastRoute="${lastRoute}". lastBodyText="${String(lastBodyText).slice(0, 500)}"`,
  );
}

test.describe('WelineTools_FontSubLetter backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('TC-01: renders backend entry without fatal errors', async ({ page }) => {
    const runtimeErrors = bindRuntimeErrors(page);

    const routeCandidates = [
      buildModuleBackendRoute(MODULE),
      buildModuleBackendRoute(MODULE, 'index'),
      buildModuleBackendRoute(MODULE, 'index', 'index'),
      buildModuleBackendRoute(MODULE, 'record'),
      buildModuleBackendRoute(MODULE, 'record', 'index'),
      'fontsubletter/backend',
      'fontsubletter/backend/index',
      'fontsubletter/backend/record',
      'fontsubletter/backend/record/index',
    ];

    await gotoFirstNonFatal(page, routeCandidates);

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(String(text).trim().length).toBeGreaterThan(0);
    await expect(body).not.toContainText(FATAL_PATTERN);
    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
  });
});
