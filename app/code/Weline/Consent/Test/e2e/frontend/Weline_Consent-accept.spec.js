/**
 * Consent accept via bin-query (consent.accept).
 *
 * @weline-e2e-spec { module: Weline_Consent, type: smoke, layer: frontend }
 */

const { test, expect, gotoFrontend, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Consent';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Consent accept bin-query', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CONSENT-ACCEPT-001' },
    'homepage consent accept hides banner and writes visitor cookie',
    async ({ page }) => {
      await gotoFrontend(page, `/?consent_e2e=${Date.now()}`, { timeout: 60000, settleMs: 1000 });
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

      const banner = page.locator('#weline-consent-banner');
      const accept = page.locator('[data-consent-accept]');
      await expect(banner).toHaveCount(1);
      await expect(accept).toHaveCount(1);
      await expect(banner).toBeVisible();
      await accept.click();
      await expect(banner).toBeHidden({ timeout: 10000 });

      const cookies = await page.context().cookies();
      const vid = cookies.find((item) => item.name === 'weline_consent_vid');
      expect(vid, 'weline_consent_vid cookie').toBeTruthy();
      expect(String(vid.value || '')).toMatch(/^v1_[A-Za-z0-9_-]{43}$/);
      expect(vid.httpOnly).toBe(true);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('#weline-consent-banner')).toBeHidden();
    }
  );
});
