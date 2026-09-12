/**
 * Catalog category admin: page grant versions + delete submit re-auth.
 *
 * @weline-e2e-spec { module: Weline_Catalog, type: feature, layer: backend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Catalog';
const ROUTE = 'weline_catalog/backend/category/index?website_id=0&scope_level=website&space=product';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'category-delete-grant-version-fixture.php');

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  const parsed = JSON.parse(last);
  if (!parsed.ok) {
    throw new Error(`fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

moduleDescribe(test, MODULE, 'Category delete grant version', () => {
  moduleCase(test, MODULE, 'page exposes grant versions and delete submit re-auth works', async ({ page }) => {
    test.setTimeout(180000);
    await loginAsAdmin(page, { useProxy: false });
    await gotoBackend(page, ROUTE, { waitUntil: 'domcontentloaded', useProxy: false });
    await waitForBackendShellReady(page);

    const root = page.locator('[data-testid="catalog-category-admin"]');
    await expect(root).toBeVisible({ timeout: 30000 });

    const grants = await root.evaluate((el) => ({
      create: Number(el.dataset.grantVersionCreate || 0),
      update: Number(el.dataset.grantVersionUpdate || 0),
      delete: Number(el.dataset.grantVersionDelete || 0),
    }));
    expect(grants.create).toBeGreaterThan(0);
    expect(grants.update).toBeGreaterThan(0);
    expect(grants.delete).toBeGreaterThan(0);

    const markup = await root.evaluate((el) => el.outerHTML);
    expect(markup).toContain('data-grant-version-delete');

    const created = runFixture('create', { grant_version: grants.create });
    expect(created.category_id).toBeGreaterThan(0);

    const denied = runFixture('delete', {
      category_id: created.category_id,
      grant_version: 0,
      expect_fail: true,
    });
    expect(String(denied.reason || denied.message || '')).toMatch(/missing_grant_version/);

    const deleted = runFixture('delete', {
      category_id: created.category_id,
      grant_version: grants.delete,
    });
    expect(deleted.deleted).toBeTruthy();
  });
});
