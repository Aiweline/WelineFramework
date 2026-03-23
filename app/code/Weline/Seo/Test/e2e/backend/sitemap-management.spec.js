// @ts-check
const {
  test,
  expect,
  buildProxyUrl,
  gotoBackend,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

async function openSitemapPage(page) {
  await gotoBackend(page, 'seo/backend/sitemap');
  await page.waitForLoadState('networkidle');
}

test.describe('Sitemap management backend', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('loads the sitemap page and shows the heading', async ({ page }) => {
    await openSitemapPage(page);

    const title = page.locator('h1, .page-title').first();
    await expect(title).toBeVisible();
    await expect(title).toContainText(/Sitemap|站点地图/i);
    await expect(page.locator('body')).not.toHaveText(/^404$/);
  });

  test('shows the primary sitemap action', async ({ page }) => {
    await openSitemapPage(page);

    const generateButton = page.locator('button:has-text("生成"), button:has-text("同步"), button:has-text("Generate"), button:has-text("Sync")').first();
    await expect(generateButton).toBeVisible({ timeout: 5000 });
    await expect(generateButton).toBeEnabled();
  });

  test('renders search or file management affordances when data is present', async ({ page }) => {
    await openSitemapPage(page);

    const searchInput = page.locator('#siteSearchInput, input[placeholder*="搜索"], input[placeholder*="Search"]').first();
    const fileOrAction = page.locator(
      '.file-item, .sitemap-file, button[title*="复制"], a[target="_blank"], button:has-text("查看"), a:has-text("查看")'
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
    const response = await page.goto(buildProxyUrl('/sitemaps/default/google/sitemap.xml'));
    expect(response).toBeTruthy();
    expect([200, 404]).toContain(response.status());
  });
});
