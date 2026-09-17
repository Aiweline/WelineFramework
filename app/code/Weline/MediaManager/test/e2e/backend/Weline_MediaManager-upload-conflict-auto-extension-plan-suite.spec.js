/**
 * 上传冲突弹窗自动补齐扩展名 — 计划收口套件
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: plan-suite, layer: backend, feature: upload-conflict-auto-extension }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
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

moduleDescribe(test, MODULE, '冲突名自动扩展计划套件', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-UPLOAD-AUTO-EXT-SUITE-001' },
    '冲突弹窗控件与扩展补齐 helper 已下发',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), {
        timeout: 90000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      await page.waitForFunction(() => {
        return !document.querySelector('.mmf-loading')
          && !!document.querySelector('#mmf-file-input')
          && !!(window.WelineMediaManager && window.WelineMediaManager.ensureUploadFileExtension);
      });

      await expect(page.locator('.mmf-dialog-secondary')).toHaveCount(1);
      const sample = await page.evaluate(() =>
        window.WelineMediaManager.ensureUploadFileExtension('paste-name', 'image.webp'),
      );
      expect(sample).toBe('paste-name.webp');
    },
  );
});
