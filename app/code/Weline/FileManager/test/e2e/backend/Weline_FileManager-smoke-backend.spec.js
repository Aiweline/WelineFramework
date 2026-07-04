// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin } = require('../../../../../../../tests/e2e/framework');

test.describe('Weline_FileManager backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

  test('FileManager is a frontend taglib component - no dedicated backend controller', async ({ page }) => {
    test.setTimeout(30000);
    // FileManager provides taglib components for frontend use
    // It does not have a dedicated backend management UI
    // Smoke test verifies backend is still accessible
    await gotoBackend(page, 'admin', { timeout: 30000, settleMs: 800 });
    const bodyText = await page.locator('body').innerText().catch(() => '');
    expect(bodyText).not.toMatch(FATAL_PATTERN);
  });
});
