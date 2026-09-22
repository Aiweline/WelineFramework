// @weline-e2e-runtime wls
// @weline-e2e-transport direct

/**
 * Theme editor「返回」：referrer 同源时回上一页；与当前 URL 相同时回主题列表。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: flow, layer: backend }
 */
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

moduleDescribe(test, MODULE, 'theme editor back return', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-BACK-001' },
    'referrer 与当前 URL 相同时返回主题列表',
    async ({ page }) => {
      const activeTheme = getActiveTheme('frontend');
      test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

      await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });

      const editorPath =
        'theme/backend/theme-editor/index?theme_id=' +
        Number(activeTheme.id || 0) +
        '&editor_area=frontend&page_type=homepage';

      await gotoBackend(page, editorPath, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
        settleMs: 1000,
      });

      const back = page.locator('#themeEditorBackLink');
      await expect(back).toBeVisible({ timeout: 60000 });

      // 再次进入同编辑器 URL：referrer 仍是编辑器页（或与当前相同）→ 不得写 data-return-href
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(back).toBeVisible({ timeout: 60000 });

      const state = await page.evaluate(() => {
        const link = document.getElementById('themeEditorBackLink');
        let refPath = '';
        try {
          const ref = new URL(document.referrer || '');
          refPath = ref.pathname + ref.search + ref.hash;
        } catch (_e) {}
        return {
          returnHref: link ? link.getAttribute('data-return-href') : null,
          href: link ? link.getAttribute('href') : null,
          current:
            window.location.pathname + window.location.search + window.location.hash,
          referrer: document.referrer || '',
          refPath,
        };
      });

      // 无 referrer / referrer 是编辑器 / 与当前相同：都必须落到主题列表 href
      expect(state.returnHref, JSON.stringify(state)).toBeNull();
      expect(String(state.href || '')).toMatch(/theme\/backend\/index/i);

      await Promise.all([
        page.waitForURL(/theme\/backend\/index/i, { timeout: 60000 }),
        back.click(),
      ]);

      await expect(page.locator('body')).toContainText(/主题|Theme/i);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-BACK-002' },
    '从主题列表进入编辑器时返回走 referrer',
    async ({ page }) => {
      const activeTheme = getActiveTheme('frontend');
      test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

      await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
      await gotoBackend(page, 'theme/backend/index', {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
        settleMs: 800,
      });

      const editorPath =
        'theme/backend/theme-editor/index?theme_id=' +
        Number(activeTheme.id || 0) +
        '&editor_area=frontend&page_type=homepage';

      await gotoBackend(page, editorPath, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
        settleMs: 1000,
      });

      const back = page.locator('#themeEditorBackLink');
      await expect(back).toBeVisible({ timeout: 60000 });

      const state = await page.evaluate(() => {
        const link = document.getElementById('themeEditorBackLink');
        const returnHref = link ? link.getAttribute('data-return-href') : null;
        return {
          returnHref,
          href: link ? link.getAttribute('href') : null,
        };
      });

      expect(String(state.returnHref || '')).toMatch(/theme\/backend\/index/i);

      await Promise.all([
        page.waitForURL(/theme\/backend\/index/i, { timeout: 60000 }),
        back.click(),
      ]);
    },
  );
});
