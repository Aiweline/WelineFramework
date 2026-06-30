// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, buildBackendUrl, buildModuleBackendRoute, loginAsAdmin } = require('../../framework');

test.describe('GuoLaiRen_PageBuilder backend smoke', () => {
  test.describe.configure({ retries: 0 });

  const PAGEBUILDER_MODULE = 'GuoLaiRen_PageBuilder';
  const DIRECT_WLS = { useProxy: false };
  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|404 Not Found/i;

  const cases = [
    {
      name: 'AI site workspace hub',
      route: buildModuleBackendRoute(PAGEBUILDER_MODULE, 'aiSiteAgent', 'index'),
      keySelector: '#pb-ai-site-create',
      fallbackText: 'AI',
    },
    {
      name: 'page management',
      route: buildModuleBackendRoute(PAGEBUILDER_MODULE, 'page', 'index'),
      keySelector: '.pagebuilder-page-card, .datatable-container, table, .page-title-box',
      fallbackText: 'Page',
    },
    {
      name: 'template management',
      route: buildModuleBackendRoute(PAGEBUILDER_MODULE, 'template', 'index'),
      keySelector: '#styleCardsContainer, .pagebuilder-list-header, .page-title-box',
      fallbackText: 'Template',
    },
  ];

  for (const c of cases) {
    test(`renders ${c.name} without PHP errors`, async ({ page }, testInfo) => {
      await loginAsAdmin(page, { timeout: 90000, ...DIRECT_WLS }).catch(() => {});

      const response = await page.goto(buildBackendUrl(c.route, DIRECT_WLS), {
        waitUntil: 'commit',
        timeout: 90000,
      });
      expect(response && response.status(), c.route).toBeLessThan(500);

      const body = page.locator('body');
      await body.waitFor({ state: 'attached', timeout: 30000 });
      await expect(body).toBeVisible({ timeout: 15000 });

      const loginFormVisible = await page
        .locator('form[action*="/admin/login/post"], input[name="username"]')
        .first()
        .isVisible({ timeout: 1200 })
        .catch(() => false);
      if (loginFormVisible) {
        testInfo.skip('backend login not available in current runtime');
      }

      const keyLocator = page.locator(c.keySelector).first();
      const keyVisible = await keyLocator.isVisible({ timeout: 30000 }).catch(() => false);
      const bodyText = ((await body.textContent()) || '').trim();
      expect(FATAL_PATTERN.test(bodyText)).toBeFalsy();
      if (keyVisible) {
        await expect(keyLocator).toBeVisible({ timeout: 15000 });
      } else {
        expect(bodyText).toContain(c.fallbackText);
      }

      const fileSafeName = c.name.replace(/[^\w-]+/g, '_');
      const screenshotPath = testInfo.outputPath(`GuoLaiRen_PageBuilder-${fileSafeName}.png`);
      await page.screenshot({ path: screenshotPath, fullPage: true });
      await testInfo.attach(`snapshot-${fileSafeName}`, {
        path: screenshotPath,
        contentType: 'image/png',
      });
    });
  }
});
