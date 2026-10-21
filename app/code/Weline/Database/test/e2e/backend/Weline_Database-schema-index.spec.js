/**
 * Weline_Database: async schema index management page.
 *
 * @weline-e2e-spec { module: Weline_Database, type: flow, layer: backend }
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

const MODULE = 'Weline_Database';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Database 索引管理', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'DATABASE-INDEX-001' },
    '索引管理页显示自动开关、待索引与索引状态',
    async ({ page }, testInfo) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'schema-index'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);

      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/索引管理|Index Management/);
      await expect(page.locator('[data-w-config-embed]').first()).toBeVisible();
      await expect(page.locator('body')).toContainText(/待索引|Pending indexes/);
      await expect(page.locator('body')).toContainText(/索引状态|Index state/);

      const indexNow = page.locator('[data-schema-index-now]').first();
      await expect(indexNow).toBeVisible();
      await indexNow.click();
      await expect(page.locator('#schema-index-progress')).toBeVisible();
      await expect(page.locator('[data-schema-index-progress-meta]')).not.toHaveText('', { timeout: 15000 });

      await page.screenshot({ path: testInfo.outputPath('schema-index.png'), fullPage: true });
    }
  );
});
