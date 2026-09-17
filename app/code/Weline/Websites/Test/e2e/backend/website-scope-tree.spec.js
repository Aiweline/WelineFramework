/**
 * Website scope tree shell: left tree + right editor (Catalog-like).
 *
 * @weline-e2e-spec { module: Weline_Websites, type: smoke, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  gotoBackend,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const ROUTE = 'websites/admin/website/index';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'website-scope-tree', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'WEBSITES-SCOPE-TREE-001' },
    '左树右编壳可达且选中网站节点',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });
      await gotoBackend(page, ROUTE, { timeout: 60000, settleMs: 800 });
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const shell = page.locator('[data-testid="website-management"]');
      await expect(shell).toBeVisible({ timeout: 30000 });
      await expect(page.locator('[data-testid="websites-scope-tree"]')).toBeVisible();
      await expect(page.locator('[data-testid="websites-scope-editor"]')).toBeVisible();

      const websiteLink = page.locator('[data-scope-node][data-kind="website"] .w-catalog-tree__link').first();
      await expect(websiteLink).toBeVisible();
      const orderOk = await websiteLink.evaluate((el) => {
        const kids = [...el.children];
        const logoIdx = kids.findIndex((n) => n.getAttribute('data-testid') === 'websites-scope-tree-logo'
          || n.classList?.contains('w-catalog-tree__thumb'));
        const scopeIdx = kids.findIndex((n) => n.getAttribute('data-testid') === 'websites-scope-tree-scope'
          || n.classList?.contains('w-badge'));
        if (scopeIdx < 0) return false;
        if (logoIdx >= 0) return logoIdx < scopeIdx;
        return true;
      });
      expect(orderOk).toBeTruthy();

      const storeLink = page.locator('[data-scope-node][data-kind="store"] .w-catalog-tree__link').first();
      await expect(storeLink).toBeVisible();
      const panelResp = page.waitForResponse((res) => {
        try {
          const u = new URL(res.url());
          return u.searchParams.get('panel') === '1' && res.request().method() === 'GET';
        } catch (_e) {
          return false;
        }
      }, { timeout: 30000 });
      await storeLink.click();
      const resp = await panelResp;
      expect(resp.ok()).toBeTruthy();
      const json = await resp.json();
      expect(json.success).toBeTruthy();
      expect(String(json.kind || '')).toBe('store');
      await expect(page.locator('[data-testid="websites-scope-editor"][data-editor-kind="store"]')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-testid="store-management-edit-form"], [data-testid="store-management-edit"]').first()).toBeVisible();
      expect(page.url()).toMatch(/node=store%3A|node=store:/);

      await websiteLink.click();
      await waitForBackendShellReady(page);
      await expect(page.locator('[data-testid="websites-scope-editor"][data-editor-kind="website"]')).toBeVisible();
      await expect(page.locator('#WebsitePage, [data-w-component="website-form"]').first()).toBeVisible();

      const body = await page.locator('body').innerText();
      expect(body).not.toMatch(/storage_scope\s*=\s*global\b/i);
    },
  );
});
