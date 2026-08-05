/** @weline-e2e-spec { module: Weline_Backend, type: flow, layer: backend } */
const {
  test,
  expect,
  installBackendBrowserGuards,
  loginAsAdmin,
  moduleCase,
  moduleDescribe,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Backend';
const PARENT = 'Weline_Backend::commerce:operations:group';

moduleDescribe(test, MODULE, 'R4.3 运营只读诊断', () => {
  for (const item of [
    {
      id: 'CK-R43-OPERATIONS-MIGRATION-001',
      sourceId: 'Weline_Backend::commerce:operations:migration-diagnostics',
      title: '迁移诊断',
      urlIncludes: '/system/backend/operations-diagnostics/migration',
      pageAnchor: '[data-testid="operations-migration-diagnostics"]',
    },
    {
      id: 'CK-R43-OPERATIONS-RELEASE-001',
      sourceId: 'Weline_Backend::commerce:operations:release-diagnostics',
      title: '发布诊断',
      urlIncludes: '/system/backend/operations-diagnostics/release',
      pageAnchor: '[data-testid="operations-release-diagnostics"]',
    },
  ]) {
    moduleCase(test, { module: MODULE, id: item.id }, `从后台菜单进入${item.title}`, async ({ page }) => {
      const guards = installBackendBrowserGuards(page);
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      await openBackendMenuBySource(page, item.sourceId, {
        parentSources: [PARENT],
        title: item.title,
        urlIncludes: item.urlIncludes,
        pageAnchor: item.pageAnchor,
      });
      await expect(page.locator(item.pageAnchor).locator('form')).toHaveCount(0);
      await expect(page.locator(item.pageAnchor).locator('button')).toHaveCount(0);
      guards.assertClean();
    });
  }
});
