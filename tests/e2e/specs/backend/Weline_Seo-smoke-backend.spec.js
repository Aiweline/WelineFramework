/**
 * Weline_Seo SEO管理 E2E 冒烟测试
 *
 * 测试范围：
 * - SEO仪表盘：SEO概览数据
 * - Sitemap管理：网站地图配置
 * - SEO账户：第三方SEO服务账户配置
 *
 * 控制器来源：app/code/Weline/Seo/Controller/Backend/Seo.php
 * 模板来源：app/code/Weline/Seo/view/templates/Backend/Seo/*.phtml
 *
 * @weline-e2e-spec { module: Weline_Seo, type: smoke, layer: backend }
 */

const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../framework');

const MODULE = 'Weline_Seo';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Seo SEO管理模块冒烟测试', () => {

  moduleCase(
    test,
    { module: MODULE, id: 'SEO-SMOKE-001' },
    'SEO仪表盘能够正常加载，显示SEO概览',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'seo/dashboard');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证页面标题
      const heading = page.locator('h4').first();
      await expect(heading).toContainText(/SEO|Search.*Engine/i, { timeout: 10000 });

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SEO-SMOKE-002' },
    'Sitemap管理页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'seo/sitemap');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SEO-SMOKE-003' },
    'SEO账户页面能够正常加载',
    async ({ page }) => {
      await loginAsAdmin(page);
      const url = buildModuleBackendRoute(MODULE, 'seo/account');
      await gotoBackend(page, url, { timeout: 30000 });

      const body = page.locator('body');
      await expect(body).toBeVisible();

      // 验证无 Fatal 错误
      await expect(body).not.toContainText(FATAL_PATTERN);
    }
  );
});
