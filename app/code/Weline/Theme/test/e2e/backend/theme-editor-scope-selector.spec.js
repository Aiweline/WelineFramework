// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';

async function expectAuthorizedScopedPreview(page) {
  const preview = page.locator('#previewFrame');
  await expect.poll(
    () => preview.getAttribute('src'),
    { timeout: 60000, message: 'frontend preview must receive a server-issued capability token' },
  ).toContain('weline_preview_token=');
  await page.waitForFunction(() => {
    const frame = document.querySelector('#previewFrame');
    if (!(frame instanceof HTMLIFrameElement) || !frame.src.includes('weline_preview_token=')) return false;
    try {
      return frame.contentWindow?.location.href.includes('weline_preview_token=')
        && frame.contentDocument?.readyState === 'complete';
    } catch (error) {
      return false;
    }
  }, null, { timeout: 60000 });
  const report = await page.evaluate(() => {
    const frame = document.querySelector('#previewFrame');
    try {
      const bodyText = String(frame?.contentDocument?.body?.innerText || '');
      const html = String(frame?.contentDocument?.documentElement?.outerHTML || '');
      return {
        sameOrigin: true,
        src: String(frame?.src || ''),
        bodyText,
        htmlLength: html.length,
      };
    } catch (error) {
      return { sameOrigin: false, error: String(error?.message || error) };
    }
  });
  expect(report.sameOrigin, JSON.stringify(report, null, 2)).toBeTruthy();
  expect(report.src).toContain('weline_preview_token=');
  expect(report.htmlLength).toBeGreaterThan(100);
  expect(report.bodyText).not.toMatch(/WLS Runtime Error|Theme 预览需要有效 Token|theme_preview_authorization_required/i);
}

moduleDescribe(test, MODULE, 'system scope selector', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-SCOPE-001' },
    'Scope drives area and Theme, exposes all four levels, and navigates with canonical values',
    async ({ page }) => {
      const activeTheme = getActiveTheme('frontend');
      test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

      await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
      await gotoBackend(
        page,
        'theme/backend/theme-editor?theme_id=' + Number(activeTheme.id || 0) + '&editor_area=frontend&page_type=homepage',
        { waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1500 },
      );

      const root = page.locator('#themeEditor');
      await expect(root).toBeVisible({ timeout: 60000 });
      await expectAuthorizedScopedPreview(page);
      const scope = page.locator('.toolbar-select-field-scope .w-scope-select');
      await expect(scope).toBeVisible();
      await expect(scope.locator('input[type="hidden"][name="scope"]')).toHaveValue(/.+/);

      const order = await page.evaluate(() => {
        const scopeControl = document.querySelector('.toolbar-select-field-scope');
        const themeControl = document.querySelector('.toolbar-select-field-theme');
        const areaControl = document.querySelector('.toolbar-select-field-editor-area');
        return {
          scopeBeforeArea: Boolean(scopeControl && areaControl && (scopeControl.compareDocumentPosition(areaControl) & Node.DOCUMENT_POSITION_FOLLOWING)),
          areaBeforeTheme: Boolean(areaControl && themeControl && (areaControl.compareDocumentPosition(themeControl) & Node.DOCUMENT_POSITION_FOLLOWING)),
        };
      });
      expect(order).toEqual({ scopeBeforeArea: true, areaBeforeTheme: true });

      await scope.locator('.w-tree-select-trigger').click();
      const dropdown = page.locator('#scopeSelect_dropdown');
      await expect(dropdown).toBeVisible();
      const global = dropdown.locator('[data-kind="global"]').first();
      const website = dropdown.locator('[data-kind="website"]').first();
      const store = dropdown.locator('[data-kind="store"]').first();
      const channel = dropdown.locator('[data-kind="channel"]').first();
      await expect(global).toBeVisible();
      await expect(website).toBeVisible();
      await expect(store).toBeVisible();
      await expect(channel).toBeVisible();

      const scopeValues = {
        global: await global.getAttribute('data-value'),
        website: await website.getAttribute('data-value'),
        store: await store.getAttribute('data-value'),
        channel: await channel.getAttribute('data-value'),
      };
      expect(scopeValues.global).toBe('default.default.default');
      for (const value of Object.values(scopeValues)) {
        expect(value).toBeTruthy();
        expect(value.split('.')).toHaveLength(3);
      }
      expect(scopeValues.website.endsWith('.default.default') || scopeValues.website === 'default.__website__.default').toBe(true);
      expect(scopeValues.store.endsWith('.default')).toBe(true);
      expect(scopeValues.channel.endsWith('.default')).toBe(false);

      const expectedScope = scopeValues.store;
      expect(expectedScope).toBeTruthy();
      await Promise.all([
        page.waitForURL((url) => url.searchParams.get('scope') === expectedScope, { timeout: 60000 }),
        store.locator('[data-w-scope-option]').first().click(),
      ]);
      await expect(page.locator('#scopeSelect')).toHaveValue(expectedScope);
      await expectAuthorizedScopedPreview(page);

      await page.locator('.toolbar-select-field-scope .w-tree-select-trigger').click();
      const channelAfterReload = page.locator('#scopeSelect_dropdown [data-kind="channel"]').first();
      await expect(channelAfterReload).toBeVisible();
      const expectedChannelScope = await channelAfterReload.getAttribute('data-value');
      expect(expectedChannelScope).toBeTruthy();
      await Promise.all([
        page.waitForURL((url) => url.searchParams.get('scope') === expectedChannelScope, { timeout: 60000 }),
        channelAfterReload.locator('[data-w-scope-option]').first().click(),
      ]);
      await expect(page.locator('#scopeSelect')).toHaveValue(expectedChannelScope);
      await expectAuthorizedScopedPreview(page);
    },
  );
});
