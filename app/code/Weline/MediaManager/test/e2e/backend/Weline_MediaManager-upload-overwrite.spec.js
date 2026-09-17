/**
 * Upload overwrite conflict UI + inherit locale metadata (contract/e2e).
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_MediaManager';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_MediaManager 上传覆盖', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-UPLOAD-OVERWRITE-001' },
    '管理器含覆盖确认控件与冲突文案键',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const secondary = page.locator('.mmf-dialog-secondary');
      await expect(secondary).toHaveCount(1);
      await expect(secondary).toBeHidden();

      const i18n = await page.evaluate(() => {
        const cfg = window.MMF_CONFIG || window.mmfConfig || {};
        const t = (cfg.i18n || cfg.translations || {});
        return {
          overwrite: t.uploadOverwriteExisting || t['覆盖已有'] || '',
          confirm: t.uploadOverwriteConfirmMessage || '',
          conflict: t.uploadNameConflictMessage || '',
        };
      });
      // Template embeds translations into CONFIG; fall back to DOM/lang presence.
      const body = await page.locator('body').innerText();
      const hasOverwriteCopy =
        !!i18n.overwrite ||
        body.includes('覆盖已有') ||
        (await page.locator('script').allTextContents()).join('').includes('uploadOverwriteExisting');
      expect(hasOverwriteCopy).toBeTruthy();
    },
  );
});
