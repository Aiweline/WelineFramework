/**
 * 上传冲突弹窗：新文件名自动补齐扩展名（章通路）
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: e2e, layer: backend, feature: upload-conflict-auto-extension }
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

moduleDescribe(test, MODULE, 'Weline_MediaManager 冲突名自动扩展', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-UPLOAD-AUTO-EXT-001' },
    '管理器运行时 ensureUploadFileExtension 可带可不带扩展',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), {
        timeout: 90000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await page.waitForFunction(() => {
        return !!(window.WelineMediaManager && typeof window.WelineMediaManager.ensureUploadFileExtension === 'function');
      });

      const result = await page.evaluate(() => {
        const fn = window.WelineMediaManager.ensureUploadFileExtension;
        return {
          omit: fn('新图', 'image.png'),
          withExt: fn('新图.png', 'image.png'),
          caseKeep: fn('新图.PNG', 'image.png'),
          wrongReplaced: fn('新图.jpg', 'image.png'),
          suggested: fn('image (1)', 'image.png'),
          empty: fn('  ', 'image.png'),
          noOriginalExt: fn('新图', 'README'),
        };
      });

      expect(result).toEqual({
        omit: '新图.png',
        withExt: '新图.png',
        caseKeep: '新图.PNG',
        wrongReplaced: '新图.png',
        suggested: 'image (1).png',
        empty: '',
        noOriginalExt: '新图',
      });
    },
  );
});
