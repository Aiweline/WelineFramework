// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
  getRuntimeInfo,
} = require('../../framework');

const WESHOP_ANALYTICS_MODULE = 'WeShop_Analytics';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error/i;
const STYLE_ERROR_PATTERN = /Failed to load resource|Refused to apply style|stylesheet.*404|MIME type .*text\/html/i;

async function collectStyleHealth(page) {
  return page.evaluate(() => {
    const stylesheets = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
    const loadedStylesheets = stylesheets.filter((link) => {
      try {
        return !!link.sheet;
      } catch {
        return false;
      }
    });
    const bodyStyles = getComputedStyle(document.body);

    return {
      stylesheetCount: stylesheets.length,
      loadedStylesheetCount: loadedStylesheets.length,
      bodyDisplay: bodyStyles.display,
      bodyVisibility: bodyStyles.visibility,
      bodyTextLength: (document.body?.innerText || '').trim().length,
    };
  });
}

test.describe('WeShop analytics backend', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('renders analytics management dashboard', async ({ page }) => {
    const analyticsRoute = buildModuleBackendRoute(WESHOP_ANALYTICS_MODULE, 'analytics');
    await gotoBackend(page, analyticsRoute, {
      timeout: 60000,
      settleMs: 1000,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Analytics Management/i);
    await expect(body).toContainText(/Google Analytics/i);
    await expect(body).toContainText(/Facebook Pixel/i);
    await expect(body).toContainText(/TikTok Pixel/i);
    await expect(body).toContainText(/Bing Ads/i);
    await expect(body).toContainText(/GA4 Measurement Protocol/i);
    await expect(body).toContainText(/Open Google Analytics/i);
    await expect(body).not.toContainText(FATAL_PATTERN);

    await expect(page).toHaveURL((url) => {
      const fullPath = `${url.pathname || ''}${url.search || ''}`;
      return /\/backend\/analytics(?:\?|\/|$)/i.test(fullPath) && !/\/admin\/login(?:\?|\/|$)/i.test(fullPath);
    });

    const runtimeInfo = getRuntimeInfo();
    const targetOrigin = String(runtimeInfo?.runtime?.target_origin || '');
    const providerForm = page.locator('form[data-analytics-provider-form]');
    await expect(providerForm).toBeVisible();

    const formAction = await providerForm.getAttribute('action');
    if (formAction && /^https?:\/\//i.test(formAction) && targetOrigin) {
      expect(new URL(formAction).origin).toBe(new URL(targetOrigin).origin);
    }

    await expect(body).not.toContainText(STYLE_ERROR_PATTERN);
    const styleHealth = await collectStyleHealth(page);
    expect(styleHealth.stylesheetCount).toBeGreaterThan(0);
    expect(styleHealth.loadedStylesheetCount).toBeGreaterThan(0);
    expect(styleHealth.bodyDisplay).not.toBe('none');
    expect(styleHealth.bodyVisibility).toBe('visible');
    expect(styleHealth.bodyTextLength).toBeGreaterThan(0);
  });
});
