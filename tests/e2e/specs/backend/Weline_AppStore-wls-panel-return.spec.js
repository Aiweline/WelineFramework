const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
} = require('../../framework');

const MODULE = 'Weline_AppStore';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found|500 Internal Server Error/i;
const WLS_FILTER_QUERY = 'tag=module%3Awls&surface=backend&wls_panel_return=1&q=wls-file-manager';

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

async function expectHealthyPage(page, errors) {
  const body = page.locator('body');
  await expect(body).toBeVisible();
  const text = await body.innerText();
  expect(text).not.toMatch(FATAL_PATTERN);
  expect(errors, errors.join('\n')).toEqual([]);
}

async function expectWlsBackAction(page) {
  const backAction = page.locator('a[href*="server/backend/wls-panel/marketplace"]').first();
  await expect(backAction).toBeVisible();
  const href = await backAction.getAttribute('href');
  expect(href || '').toContain('panel_notice=plugins_refreshed');
  expect(href || '').toContain('panel_auto_refresh=plugins');
  expect(href || '').toContain('#installed-plugins');
}

async function expectOptionalFormContext(form) {
  if (await form.count()) {
    await expect(form.locator('input[type="hidden"][name="wls_panel_return"][value="1"]').first()).toBeAttached();
  }
}

moduleDescribe(test, MODULE, 'WLS Panel AppStore return context', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 1280, height: 820 });
  });

  moduleCase(
    test,
    { module: MODULE, id: 'WLS-PANEL-APPSTORE-RETURN-001' },
    'online marketplace keeps WLS Panel return context',
    async ({ page }) => {
      const errors = bindPageErrors(page);
      await gotoBackend(page, `appstore/backend?${WLS_FILTER_QUERY}`, {
        timeout: 60000,
        settleMs: 1000,
      });

      await expectHealthyPage(page, errors);
      await expectWlsBackAction(page);
      expect(new URL(page.url()).searchParams.get('wls_panel_return')).toBe('1');
      expect(new URL(page.url()).searchParams.get('tag')).toBe('module:wls');
      expect(new URL(page.url()).searchParams.get('surface')).toBe('backend');

      const searchForm = page.locator('#appstore-search-form');
      if (await searchForm.count()) {
        await expect(searchForm.locator('input[name="wls_panel_return"][value="1"]')).toBeAttached();
        await expect(searchForm.locator('input[name="tag"]')).toHaveValue('module:wls');
        await expect(searchForm.locator('input[name="surface"]')).toHaveValue('backend');
      }

      const installForms = page.locator('form[action*="appstore/backend/index/install"]');
      await expectOptionalFormContext(installForms.first());
      const downloadForms = page.locator('form[action*="appstore/backend/index/download"]');
      await expectOptionalFormContext(downloadForms.first());

      const authorizeLinks = page.locator('a[href*="appstore/backend/index/authorize-install"]');
      if (await authorizeLinks.count()) {
        const href = await authorizeLinks.first().getAttribute('href');
        expect(href || '').toContain('wls_panel_return=1');
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'WLS-PANEL-APPSTORE-RETURN-002' },
    'installed modules keeps WLS Panel return context for checks and filters',
    async ({ page }) => {
      const errors = bindPageErrors(page);
      await gotoBackend(page, 'appstore/backend/installed?tag=module%3Awls&surface=backend&wls_panel_return=1', {
        timeout: 60000,
        settleMs: 1000,
      });

      await expectHealthyPage(page, errors);
      await expectWlsBackAction(page);
      expect(new URL(page.url()).searchParams.get('wls_panel_return')).toBe('1');
      expect(new URL(page.url()).searchParams.get('tag')).toBe('module:wls');
      expect(new URL(page.url()).searchParams.get('surface')).toBe('backend');

      const checkUpdateForm = page.locator('form[action*="appstore/backend/installed/checkUpdate"]').first();
      await expect(checkUpdateForm).toBeVisible();
      await expect(checkUpdateForm.locator('input[name="wls_panel_return"][value="1"]')).toBeAttached();

      const filterForm = page.locator('#appstore-installed-tag').locator('xpath=ancestor::form[1]');
      await expectOptionalFormContext(filterForm);

      const updateForms = page.locator('form[action*="appstore/backend/installed/update"]');
      await expectOptionalFormContext(updateForms.first());
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'WLS-PANEL-APPSTORE-RETURN-003' },
    'authorize install keeps WLS Panel return context',
    async ({ page }) => {
      const errors = bindPageErrors(page);
      await gotoBackend(page, 'appstore/backend/index/authorize-install?module_id=1&version=1.0.0&wls_panel_return=1', {
        timeout: 60000,
        settleMs: 1000,
      });

      await expectHealthyPage(page, errors);
      await expectWlsBackAction(page);
      expect(new URL(page.url()).searchParams.get('wls_panel_return')).toBe('1');

      const installShell = page.locator('.appstore-install-authorization');
      await expect(installShell).toBeVisible();
      const storeLink = installShell.locator('a.btn[href*="appstore/backend"][href*="wls_panel_return=1"]').first();
      await expect(storeLink).toBeVisible();
      const installedLink = installShell.locator('a.btn[href*="appstore/backend/installed"][href*="wls_panel_return=1"]').first();
      await expectOptionalFormContext(installShell.locator('form[action*="appstore/backend/index/install"]').first());
      if (await installedLink.count()) {
        await expect(installedLink).toBeVisible();
      }
    },
  );
});
