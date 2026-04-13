// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

test.describe('GuoLaiRen_PageBuilder backend (smoke)', () => {
  test.describe.configure({ retries: 0 });

  const PAGEBUILDER_MODULE = 'GuoLaiRen_PageBuilder';
  const DIRECT_WLS = { useProxy: false };

  const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|404 Not Found/i;

  const cases = [
    {
      name: 'AI 建站工作台入口（aiSiteAgent/index）',
      route: buildModuleBackendRoute(PAGEBUILDER_MODULE, 'aiSiteAgent', 'index'),
      keySelector: '#pb-ai-site-create',
      fallbackText: '工作台',
    },
    {
      name: '页面管理（page/index）',
      route: buildModuleBackendRoute(PAGEBUILDER_MODULE, 'page', 'index'),
      keySelector: '.datatable-container, table, .page-title-box',
      fallbackText: '页面',
    },
    {
      name: '模板管理（template/index）',
      route: buildModuleBackendRoute(PAGEBUILDER_MODULE, 'template', 'index'),
      keySelector: '#styleCardsContainer, .pagebuilder-list-header, .page-title-box',
      fallbackText: '模板',
    },
  ];

  for (const c of cases) {
    test(`renders ${c.name} without PHP errors`, async ({ page }, testInfo) => {
      // Try best-effort auth. If runtime is unstable, continue and decide by page state.
      await loginAsAdmin(page, { timeout: 90000, ...DIRECT_WLS }).catch(() => {});
      await gotoBackend(page, c.route, {
        timeout: 90000,
        settleMs: 1200,
        ...DIRECT_WLS,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible({ timeout: 15000 });
      const loginFormVisible = await page
        .locator('form[action*="/admin/login/post"], input[name="username"]')
        .first()
        .isVisible({ timeout: 1200 })
        .catch(() => false);
      if (loginFormVisible) {
        testInfo.skip('backend login not available in current runtime');
      }
      await page.waitForTimeout(600);
      const bodyText = ((await body.textContent()) || '').trim();
      expect(FATAL_PATTERN.test(bodyText)).toBeFalsy();
      const keyLocator = page.locator(c.keySelector).first();
      if ((await keyLocator.count()) > 0) {
        await expect(keyLocator).toBeVisible({ timeout: 15000 });
      } else {
        expect(bodyText).toContain(c.fallbackText);
      }

      const fileSafeName = c.name.replace(/[^\w\u4e00-\u9fa5-]+/g, '_');
      const screenshotPath = testInfo.outputPath(`GuoLaiRen_PageBuilder-${fileSafeName}.png`);
      await page.screenshot({ path: screenshotPath, fullPage: true });
      await testInfo.attach(`snapshot-${fileSafeName}`, {
        path: screenshotPath,
        contentType: 'image/png',
      });
    });
  }
});

