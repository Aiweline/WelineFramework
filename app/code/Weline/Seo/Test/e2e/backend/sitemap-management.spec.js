// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  buildTargetUrl,
  gotoBackend,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

async function openSitemapPage(page) {
  await gotoBackend(page, 'seo/backend/sitemap', {
    timeout: 60000,
    settleMs: 1000,
  });
  await expect(page.locator('body')).not.toHaveText(/^404$/);
}

test.describe('Sitemap management backend', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('loads the sitemap page and shows the heading', async ({ page }) => {
    await openSitemapPage(page);

    const title = page.locator('h1, h2, h3, h4, .page-title, .card-title').filter({
      hasText: /Sitemap|站点地图/i,
    }).first();
    await expect(title).toBeVisible();
    await expect(title).toContainText(/Sitemap|站点地图/i);
  });

  test('shows the primary sitemap action', async ({ page }) => {
    await openSitemapPage(page);

    const generateButton = page.locator(
      '#generateBtn, button:has-text("生成Sitemap"), button:has-text("生成"), button:has-text("同步"), button:has-text("Generate"), button:has-text("Sync")'
    ).first();
    await expect(generateButton).toBeVisible({ timeout: 5000 });
    await expect(generateButton).toBeEnabled();
  });

  test('renders search or file management affordances when data is present', async ({ page }) => {
    await openSitemapPage(page);

    const searchInput = page.locator('#siteSearchInput, input[placeholder*="搜索"], input[placeholder*="Search"]').first();
    const fileOrAction = page.locator(
      '#generateBtn, button:has-text("调用所有生成器"), button:has-text("生成Sitemap"), .file-item, .sitemap-file, a[target="_blank"]'
    ).first();

    const hasSearch = await searchInput.isVisible().catch(() => false);
    const hasFileAction = await fileOrAction.isVisible().catch(() => false);
    expect(hasSearch || hasFileAction).toBeTruthy();
  });

  test('does not explode with major JavaScript errors', async ({ page }) => {
    const errors = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    page.on('pageerror', error => {
      errors.push(error.message);
    });

    await openSitemapPage(page);
    await page.waitForTimeout(1000);

    expect(errors.length).toBeLessThan(5);
  });

  test('keeps the page usable on a mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await openSitemapPage(page);

    await expect(page.locator('body')).toBeVisible();

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 20);
  });
});

test.describe('Sitemap public files', () => {
  test('public sitemap endpoints stay reachable through the unified proxy entry', async ({ page }) => {
    const response = await page.goto(buildTargetUrl('/sitemaps/default/google/sitemap.xml'), {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
    });
    expect(response).toBeTruthy();
    expect([200, 404]).toContain(response.status());
  });
});
