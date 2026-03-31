// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoFrontend } = require('../../framework');

const FATAL_PATTERN =
  /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

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

test.describe('WelineTools_Icon smoke', () => {
  test.describe.configure({ retries: 1 });

  test('renders icon entry without PHP/WLS fatal errors', async ({ page }) => {
    const runtimeErrors = bindRuntimeErrors(page);
    const routeCandidates = ['/icon/icon/index', '/icon/icon'];
    let lastError = null;
    let navigated = false;

    for (const route of routeCandidates) {
      try {
        await gotoFrontend(page, route, {
          timeout: 30000,
          settleMs: 800,
        });
        navigated = true;
        break;
      } catch (error) {
        lastError = error;
      }
    }

    if (!navigated) {
      throw new Error(
        `WelineTools_Icon smoke failed to open frontend route. ` +
          `Routes: ${JSON.stringify(routeCandidates)}. ` +
          `Last error: ${lastError ? String(lastError && lastError.message ? lastError.message : lastError) : 'none'}.`
      );
    }

    const bodyText = await page.locator('body').innerText().catch(() => '');
    await expect(page.locator('body')).toBeVisible();
    expect(bodyText).not.toMatch(FATAL_PATTERN);
    expect(page.url()).not.toContain('/admin/login');
    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
  });
});
