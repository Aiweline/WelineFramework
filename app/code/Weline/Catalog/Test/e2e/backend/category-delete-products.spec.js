/**
 * Catalog category admin: delete dialog shows product checklist.
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
const FIXTURE = path.resolve(__dirname, 'category-delete-products-fixture.php');

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

moduleDescribe(test, MODULE, 'Category delete product checklist', () => {
  moduleCase(test, MODULE, 'delete dialog lists products and deletes with grant version', async ({ page }) => {
    test.setTimeout(240000);
    await loginAsAdmin(page, { useProxy: false });
    await gotoBackend(page, ROUTE, { waitUntil: 'domcontentloaded', useProxy: false });
    await waitForBackendShellReady(page);

    const root = page.locator('[data-testid="catalog-category-admin"]');
    await expect(root).toBeVisible({ timeout: 30000 });
    const grants = await root.evaluate((el) => ({
      create: Number(el.dataset.grantVersionCreate || 0),
      delete: Number(el.dataset.grantVersionDelete || 0),
    }));
    expect(grants.create).toBeGreaterThan(0);
    expect(grants.delete).toBeGreaterThan(0);

    const seeded = runFixture('seed', { grant_version: grants.create });
    expect(seeded.category_id).toBeGreaterThan(0);
    expect(seeded.product_id).toBeGreaterThan(0);
    const listed = runFixture('list', { category_id: seeded.category_id });
    expect(listed.products.some((row) => Number(row.product_id) === Number(seeded.product_id))).toBeTruthy();

    await gotoBackend(page, `${ROUTE}&id=${seeded.category_id}`, {
      waitUntil: 'domcontentloaded',
      useProxy: false,
    });
    await waitForBackendShellReady(page);

    const deleteBtn = page.locator(`[data-catalog-delete="${seeded.category_id}"]`).first();
    await expect(deleteBtn).toBeVisible({ timeout: 30000 });
    await deleteBtn.click();

    const checklist = page.locator('.w-catalog-delete-products');
    await expect(checklist).toBeVisible({ timeout: 15000 });
    await expect(checklist.locator(`input[data-product-id="${seeded.product_id}"]`)).toHaveCount(1);

    // Leave unchecked (unlink only), confirm delete.
    await page.getByRole('button', { name: /确认删除|删除|Confirm/i }).last().click();

    await expect.poll(async () => {
      const verified = runFixture('verify_deleted', {
        category_id: seeded.category_id,
        product_id: seeded.product_id,
      });
      return verified.category_gone === true && verified.product_kept === true;
    }, { timeout: 30000 }).toBeTruthy();
  });
});
