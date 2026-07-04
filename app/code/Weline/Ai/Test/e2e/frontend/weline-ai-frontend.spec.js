// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

test.describe('Weline_Ai frontend smoke', () => {
  test('ai landing page loads', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoFrontend(page, '/ai/frontend/index', { timeout: 60000, settleMs: 1500 });

    await expect(page.locator('body')).toBeVisible();
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('ai chat page loads', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoFrontend(page, '/ai/frontend/chat', { timeout: 60000, settleMs: 1500 });

    await expect(page.locator('body')).toBeVisible();
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('ai center page loads', async ({ page }) => {
    const errors = bindPageErrors(page);
    await gotoFrontend(page, '/ai/frontend/center', { timeout: 60000, settleMs: 1500 });

    await expect(page.locator('body')).toBeVisible();
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
