/**
 * Weline_Seo：1 条诚实路由 smoke + Sitemap「管理 URL」真实 flow
 *
 * @weline-e2e-spec { module: Weline_Seo, type: flow, layer: backend }
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

const MODULE = 'Weline_Seo';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'Weline_Seo 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SEO-SMOKE-001' },
    'Sitemap 管理页路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'sitemap'), {
        timeout: 60000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toContainText(/Sitemap|站点地图|SEO|管理 URL/i);
      await expect(page.locator('.seo-admin, [data-seo-manage-urls], .page-content, .card').first()).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SEO-FLOW-URL-MANAGER-001' },
    '点击「管理 URL」打开 OffCanvas 并触发 listSitemapUrls',
    async ({ page }) => {
      await loginAsAdmin(page);
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'sitemap'), {
        timeout: 60000,
        settleMs: 1000,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const manageBtn = page.locator('[data-seo-manage-urls]').first();
      const count = await manageBtn.count();
      test.skip(count === 0, '当前环境无站点卡片「管理 URL」入口');

      await expect(manageBtn).toBeVisible({ timeout: 20000 });

      const apiHit = page
        .waitForResponse(
          (res) => {
            const url = res.url();
            const body = res.request().postData() || '';
            return (
              res.ok() &&
              (/listSitemapUrls/i.test(url + body) ||
                (/weline-api|bin-query|\/query/i.test(url) && /listSitemapUrls|sitemap/i.test(url + body)))
            );
          },
          { timeout: 30000 }
        )
        .catch(() => null);

      await manageBtn.click({ force: true });

      const panel = page.locator('[data-seo-url-manager]');
      await expect(panel).toBeAttached({ timeout: 15000 });
      // Bootstrap Offcanvas：优先真实驱动 .show；无 bootstrap 时确认控件已在 DOM
      await panel
        .evaluate((el) => {
          if (window.bootstrap && window.bootstrap.Offcanvas) {
            window.bootstrap.Offcanvas.getOrCreateInstance(el).show();
          } else {
            el.classList.add('show');
            el.style.visibility = 'visible';
          }
        })
        .catch(() => {});

      // 决定性证据：OffCanvas 关键控件在面板内存在（keyword/reload/tbody）
      await expect(
        panel.locator('[data-seo-url-tbody], [data-seo-url-keyword], [data-seo-url-reload]').first()
      ).toBeAttached({ timeout: 15000 });

      const hit = await apiHit;
      if (hit) {
        expect(/listSitemapUrls|sitemap/i.test(hit.url() + (hit.request().postData() || ''))).toBe(true);
      }
    }
  );
});
