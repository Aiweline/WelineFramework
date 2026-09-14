// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_CjDropshipping';

moduleDescribe(test, MODULE, '店面分类导航 CJ 汉化', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-CJ-CATEGORY-NAV-ZH-001' },
    '章节1 前后端通路：顶栏可见方向盘套与家居、园艺与家具且无英文原名',
    async ({ page }) => {
      await gotoFrontend(page, '/products', { waitUntil: 'domcontentloaded', timeout: 60000 });
      const menu = page.locator('[data-testid="header-category-menu"]');
      await expect(menu.getByRole('link', { name: '方向盘套' })).toBeVisible({ timeout: 20000 });
      await expect(menu.getByRole('link', { name: '家居、园艺与家具' })).toBeVisible({ timeout: 20000 });
      await expect(menu).not.toContainText('Steering Covers');
      await expect(menu).not.toContainText('Home, Garden & Furniture');
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CJ-CATEGORY-NAV-ZH-PLAN-001' },
    '完整功能通路组套件：顶栏中文分类链路',
    async ({ page }) => {
      await gotoFrontend(page, '/products', { waitUntil: 'domcontentloaded', timeout: 60000 });
      const menu = page.locator('[data-testid="header-category-menu"]');
      await expect(menu.getByRole('link', { name: '方向盘套' })).toBeVisible({ timeout: 20000 });
      await expect(menu.getByRole('link', { name: '家居、园艺与家具' })).toBeVisible({ timeout: 20000 });
    }
  );
});
