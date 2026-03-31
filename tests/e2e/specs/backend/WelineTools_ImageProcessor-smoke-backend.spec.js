// @weline-e2e-runtime fallback
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE_NAME = 'WelineTools_ImageProcessor';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function waitForStableUi(page) {
  await page.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  // 字体与图标加载完成后再截图，减少同一页面的细微像素抖动。
  await page.evaluate(async () => {
    if (document.fonts && typeof document.fonts.ready?.then === 'function') {
      await document.fonts.ready;
    }
  });
  await page.waitForTimeout(300);
}

test.describe('WelineTools_ImageProcessor backend smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('TC-01: renders image processor backend page without fatal errors', async ({ page }) => {
    const errors = bindPageErrors(page);
    const route = buildModuleBackendRoute(MODULE_NAME, 'index', 'index');

    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    const moduleCard = page.locator('.weline-image-processor').first();
    await expect(moduleCard).toBeVisible();
    await waitForStableUi(page);

    const text = await body.innerText();
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
    expect(page.url()).not.toContain('/admin/login');

    await expect(moduleCard).toHaveScreenshot('WelineTools_ImageProcessor-smoke-backend.png', {
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
      maxDiffPixelRatio: 0.01,
      mask: [
        page.locator('#remove-bg-hint'),
        page.locator('#remove-bg-samples-hint'),
      ],
    });
  });
});
