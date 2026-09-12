/**
 * Catalog category tree: no reseller long-path / blunt sourcing chrome.
 *
 * @weline-e2e-spec { module: Weline_Catalog, type: feature, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
  installBackendBrowserGuards,
  getRuntimeInfo,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Catalog';
const ROUTE = 'weline_catalog/backend/category/index?website_id=0&scope_level=website&space=product';

moduleDescribe(test, MODULE, '分类树不露货源壳', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CATALOG-TREE-LEAF-001' },
    '树不刷满 /sourcing/cj 长路径，也不直白「来源 CJ」文案',
    async ({ page }) => {
      installBackendBrowserGuards(page);
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
      await gotoBackend(page, ROUTE, { waitUntil: 'domcontentloaded', useProxy: false, timeout: 90000 });
      await waitForBackendShellReady(page);

      const tree = page.locator('[data-testid="catalog-category-tree"]');
      await expect(tree).toBeVisible({ timeout: 30000 });

      await expect(tree.locator('.w-badge', { hasText: /\/sourcing\/cj\// })).toHaveCount(0);
      await expect(tree.locator('.w-badge', { hasText: /来源\s*CJ/i })).toHaveCount(0);
      await expect(tree.getByText('货源商城', { exact: true })).toHaveCount(0);
      await expect(tree.getByText('CJ货源', { exact: true })).toHaveCount(0);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-CATALOG-GOOGLE-MAP-001' },
    'Google 映射区标签与查阅参照树按钮不重叠',
    async ({ page }) => {
      // Prefer public Host (avoids intermittent Chromium ECONNRESET on 127.0.0.1:9555).
      const runtime = getRuntimeInfo({ refresh: true });
      const hostOrigin = String(
        process.env.PLAYWRIGHT_PUBLIC_ORIGIN
          || process.env.PLAYWRIGHT_TARGET_ORIGIN
          || 'https://p05113ef3.test.weline.com:9555',
      ).replace(/\/$/, '');
      const backendPrefix = String(runtime?.paths?.backend_prefix_path || '').replace(/\/$/, '');
      installBackendBrowserGuards(page);
      await page.goto(`${hostOrigin}${backendPrefix}/admin/login`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
      });
      const user = page.locator('input[name="username"], input[name="user"], input[type="email"], input[name="login"]').first();
      const pass = page.locator('input[name="password"], input[type="password"]').first();
      if (await user.count()) {
        await user.fill(process.env.PLAYWRIGHT_ADMIN_USER || 'admin');
      }
      if (await pass.count()) {
        await pass.fill(process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin');
      }
      await page.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForTimeout(800);
      await page.goto(
        `${hostOrigin}${backendPrefix}/${ROUTE}&new=1`,
        { waitUntil: 'domcontentloaded', timeout: 90000 },
      );
      await waitForBackendShellReady(page);

      const mapping = page.locator('[data-testid="catalog-google-mapping"]');
      await expect(mapping).toBeVisible({ timeout: 30000 });
      await expect(mapping).toHaveAttribute('data-label-layout', 'stacked');

      const label = mapping.locator('.w-field__label');
      const link = mapping.getByRole('link', { name: /查阅参照树|Reference/i });
      await expect(label).toBeVisible();
      await expect(link).toBeVisible();

      const overlap = await Promise.all([label.boundingBox(), link.boundingBox()]).then(([a, b]) => {
        if (!a || !b) return true;
        const noOverlap =
          a.x + a.width <= b.x ||
          b.x + b.width <= a.x ||
          a.y + a.height <= b.y ||
          b.y + b.height <= a.y;
        return !noOverlap;
      });
      expect(overlap).toBe(false);

      await expect(mapping.locator('#catalog-google-taxonomy-id')).toBeVisible();
    },
  );
});
