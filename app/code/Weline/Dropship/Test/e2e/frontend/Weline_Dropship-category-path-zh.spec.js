// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');
const fs = require('fs');
const path = require('path');

const MODULE = 'Weline_Dropship';

moduleDescribe(test, MODULE, '发布分类 path 汉化契约', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CATEGORY-PATH-ZH-001' },
    '章节2 前后端通路：PublishService 在 ensure 前经 Localizer Interface 汉化 path',
    async () => {
      const publishSrc = fs.readFileSync(
        path.join(__dirname, '../../../Service/DropshipPublishService.php'),
        'utf8'
      );
      const ifaceSrc = fs.readFileSync(
        path.join(__dirname, '../../../Interface/DropshipCategoryPathLocalizerInterface.php'),
        'utf8'
      );
      expect(ifaceSrc).toContain('localizeCategoryPath');
      expect(publishSrc).toContain('DropshipCategoryPathLocalizerInterface');
      expect(publishSrc).toContain('localizeCategoryPath');
      expect(publishSrc).toContain('ensureFromRemotePath($websiteId, $snapshot->providerCode, $path, $locale)');
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CATEGORY-PATH-ZH-002' },
    '章节3 前后端通路：治理后店面顶栏中文分类可见',
    async ({ page }) => {
      await gotoFrontend(page, '/products', { waitUntil: 'domcontentloaded', timeout: 60000 });
      const menu = page.locator('[data-testid="header-category-menu"]');
      await expect(menu.getByRole('link', { name: '方向盘套' })).toBeVisible({ timeout: 20000 });
      await expect(menu.getByRole('link', { name: '家居、园艺与家具' })).toBeVisible({ timeout: 20000 });
    }
  );
});
