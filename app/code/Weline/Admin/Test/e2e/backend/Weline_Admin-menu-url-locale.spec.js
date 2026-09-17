/**
 * 后台菜单 href 保留非默认语言段
 *
 * @weline-e2e-spec { module: Weline_Admin, type: e2e, layer: backend, feature: backend-menu-url-locale }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  waitForBackendShellReady,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Admin';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, '后台菜单 URL 语言段', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'ADMIN-MENU-URL-LOCALE-001' },
    '切到 en_US 后侧栏菜单 href 含 /en_US/',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, 'admin', { timeout: 90000, settleMs: 800, useProxy: false });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const trigger = page.locator('.w-language-switcher__trigger, button:has-text("切换语言")').first();
      await expect(trigger).toBeVisible({ timeout: 15000 });
      await trigger.click();
      const enOption = page.locator('a.w-language-switcher__option[data-locale="en_US"], a.w-language-switcher__option[href*="/en_US/"]').first();
      await expect(enOption).toBeVisible({ timeout: 10000 });
      await Promise.all([
        page.waitForURL(/\/en_US\//, { timeout: 60000 }),
        enOption.click(),
      ]);
      await waitForBackendShellReady(page);
      await expect(page.locator('html')).toHaveAttribute('data-lang', 'en_US');

      const hrefs = await page.locator('#w-backend-sidebar a.w-backend-nav__item[href]').evaluateAll((nodes) =>
        nodes.slice(0, 12).map((n) => n.getAttribute('href') || ''),
      );
      expect(hrefs.length, '侧栏应有可点菜单').toBeGreaterThan(0);
      for (const href of hrefs) {
        expect(href).toMatch(/\/en_US\//);
      }
    },
  );
});
