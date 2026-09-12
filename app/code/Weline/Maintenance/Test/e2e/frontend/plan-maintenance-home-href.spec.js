/**
 * 维护页首页链接不得输出字面 @url（静态 PHP include，无 Taglib 编译）
 *
 * @weline-e2e-spec { module: Weline_Maintenance, type: plan, layer: frontend }
 */

const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Maintenance';

moduleDescribe(test, MODULE, '维护页首页链接契约', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'TEST-MAINTENANCE-HOME-HREF-ROOT' },
    'maintenance.phtml 顶栏与返回首页使用根相对 /，无字面 @url',
    async ({ page }) => {
      const templatePath = path.join(__dirname, '../../../view/templates/maintenance.phtml');
      const template = fs.readFileSync(templatePath, 'utf8');
      expect(template).not.toContain("@url{'/'}");
      expect(template).not.toMatch(/@url\{/);
      expect(template).toContain('class="maintenance-brand" href="/"');
      expect(template).toContain('href="/" class="back-btn"');

      const staticPath = path.join(__dirname, '../../../../../../../pub/errors/maintenance/zh_Hans_CN.html');
      if (fs.existsSync(staticPath)) {
        const published = fs.readFileSync(staticPath, 'utf8');
        expect(published).not.toContain("@url{'/'}");
        expect(published).toContain('class="maintenance-brand" href="/"');
        expect(published).toContain('href="/" class="back-btn"');
      }

      // Keep a live storefront probe so this remains a frontend e2e gate.
      await gotoFrontend(page, '/');
      await expect(page.locator('body')).toBeVisible();
    }
  );
});
