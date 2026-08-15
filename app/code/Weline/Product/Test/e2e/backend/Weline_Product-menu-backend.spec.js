/**
 * R4.3 Product menus and decisive WebUI writes.
 *
 * @weline-e2e-spec { module: Weline_Product, type: flow, layer: backend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  collectBackendMenuSnapshot,
  installBackendBrowserGuards,
  openBackendMenuBySource,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'Weline_Product-write-fixture.php');
const PARENT = 'Weline_Backend::commerce:catalog:group';
const CAPABILITIES = [
  ['products', 'products'],
  ['offers', 'offers'],
  ['sku-registry', 'skuregistry'],
  ['categories', 'categories'],
  ['media', 'media'],
  ['site-content', 'sitecontent'],
  ['store-copy', 'storecopy'],
  ['shards', 'shards'],
].map(([code, action]) => ({
  sourceId: 'Weline_Product::commerce:catalog:' + code,
  parentSource: PARENT,
  urlIncludes: '/weline_product/backend/catalog/' + action,
  pageAnchor: '[data-testid="product-management-' + code + '"]',
}));

function fixture(action, payload = {}) {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 write fixture requires WELINE_E2E_ISOLATED_DB=1');
  }
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const parsed = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).at(-1) || '{}');
  if (!parsed.ok) throw new Error('Product fixture ' + action + ' failed: ' + (parsed.error || output));
  return parsed;
}

async function submit(page, testId) {
  const form = page.locator('[data-testid="' + testId + '"]');
  await expect(form).toBeVisible();
  await form.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
}

moduleDescribe(test, MODULE, 'R4.3 商品中心菜单与真实写操作', () => {
  test.setTimeout(240000);

  moduleCase(test, { module: MODULE, id: 'CK-R43-PRODUCT-MENU-001' }, '商品中心八个管理工作台各出现一次', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    await waitForBackendShellReady(page);
    const snapshot = await collectBackendMenuSnapshot(page);
    for (const capability of CAPABILITIES) {
      const rows = snapshot.filter((row) => row.sourceId === capability.sourceId);
      expect(rows, capability.sourceId).toHaveLength(1);
      expect(rows[0].parentSource, capability.sourceId).toBe(capability.parentSource);
      expect(rows[0].href.trim(), capability.sourceId).not.toBe('');
      expect(rows[0].href, capability.sourceId).not.toMatch(/^(?:#|javascript:)/i);
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-PRODUCT-MENU-002' }, '逐项点击商品管理菜单并验证工作台锚点', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    const guards = installBackendBrowserGuards(page);
    for (const capability of CAPABILITIES) {
      await openBackendMenuBySource(page, capability.sourceId, capability);
    }
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-PRODUCT-WRITE-001' }, '从真实菜单注册 SKU 并创建商品、报价、分类和媒体', async ({ page }) => {
    const data = fixture('prepare', { token: 'p' + Date.now().toString(36) });
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    try {
      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:sku-registry', CAPABILITIES[2]);
      await page.locator('[data-testid="product-sku-register-form"] [name="sku"]').fill(data.sku);
      await page.locator('[data-testid="product-sku-register-form"] [name="request_hash"]').fill(data.request_hash);
      await submit(page, 'product-sku-register-form');

      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:products', CAPABILITIES[0]);
      await page.locator('[data-testid="product-create-form"] [name="sku"]').fill(data.sku);
      await submit(page, 'product-create-form');

      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:offers', CAPABILITIES[1]);
      await page.locator('[data-testid="product-offer-create-form"] [name="sku"]').fill(data.sku);
      await submit(page, 'product-offer-create-form');

      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:categories', CAPABILITIES[3]);
      await page.locator('[data-testid="product-category-create-form"] [name="path"]').fill(data.category_path);
      await submit(page, 'product-category-create-form');

      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:media', CAPABILITIES[4]);
      await page.locator('[data-testid="product-media-create-form"] [name="sku"]').fill(data.sku);
      await page.locator('[data-testid="product-media-create-form"] [name="path"]').fill(data.media_path);
      await page.locator('[data-testid="product-media-create-form"] [name="blob_key"]').fill(data.blob_key);
      await page.locator('[data-testid="product-media-create-form"] [name="position"]').fill(String(data.position));
      await submit(page, 'product-media-create-form');

      const persisted = fixture('inspect', data);
      expect(persisted.registry).toHaveLength(1);
      expect(persisted.products).toHaveLength(1);
      expect(persisted.offers).toHaveLength(1);
      expect(persisted.categories).toHaveLength(1);
      expect(persisted.media).toHaveLength(1);
      expect(Number(persisted.registry[0].ref_count)).toBe(2);
      expect(Number(persisted.media[0].position)).toBe(data.position);
    } finally {
      try {
        const cleanup = fixture('cleanup', data);
        expect(Object.values(cleanup.remaining).every((count) => count === 0)).toBeTruthy();
      } finally {
        guards.assertClean();
      }
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-PRODUCT-SITE-CONTENT-001' }, '从站点文案菜单为 UI 创建的商品保存 Store View 文案', async ({ page }) => {
    const data = fixture('prepare', { token: 'c' + Date.now().toString(36) });
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    try {
      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:sku-registry', CAPABILITIES[2]);
      await page.locator('[data-testid="product-sku-register-form"] [name="sku"]').fill(data.sku);
      await page.locator('[data-testid="product-sku-register-form"] [name="request_hash"]').fill(data.request_hash);
      await submit(page, 'product-sku-register-form');

      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:products', CAPABILITIES[0]);
      await page.locator('[data-testid="product-create-form"] [name="sku"]').fill(data.sku);
      await submit(page, 'product-create-form');

      const created = fixture('inspect', data);
      expect(created.products).toHaveLength(1);
      const productId = Number(created.products[0].product_id);
      expect(productId).toBeGreaterThan(0);

      const capability = CAPABILITIES.find((item) => item.sourceId.endsWith(':site-content'));
      expect(capability).toBeTruthy();
      await openBackendMenuBySource(page, capability.sourceId, capability);
      const form = page.locator('[data-testid="product-site-content-form"]');
      await form.locator('[name="store_id"]').fill(String(data.store_id));
      await form.locator('[name="entity_id"]').fill(String(productId));
      await form.locator('[name="attribute_code"]').fill(data.attribute_code);
      await form.locator('[name="locale"]').fill(data.locale);
      await form.locator('[name="value_text"]').fill(data.site_content_value);
      await form.locator('[name="is_required"]').check();
      await submit(page, 'product-site-content-form');

      const persisted = fixture('inspect', data);
      expect(persisted.attribute_values).toHaveLength(1);
      expect(persisted.attribute_values[0].attribute_code).toBe(data.attribute_code);
      expect(persisted.attribute_values[0].locale).toBe(data.locale);
      expect(persisted.attribute_values[0].value_text).toBe(data.site_content_value);
      expect(Number(persisted.attribute_values[0].is_required)).toBe(1);
    } finally {
      try {
        const cleanup = fixture('cleanup', data);
        expect(Object.values(cleanup.remaining).every((count) => count === 0)).toBeTruthy();
      } finally {
        guards.assertClean();
      }
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-PRODUCT-EDIT-001' }, '从商品列表进入编辑页并完成 SKU 修改', async ({ page }) => {
    const data = fixture('prepare', { token: 'e' + Date.now().toString(36) });
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    try {
      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:sku-registry', CAPABILITIES[2]);
      await page.locator('[data-testid="product-sku-register-form"] [name="sku"]').fill(data.sku);
      await page.locator('[data-testid="product-sku-register-form"] [name="request_hash"]').fill(data.request_hash);
      await submit(page, 'product-sku-register-form');

      await openBackendMenuBySource(page, 'Weline_Product::commerce:catalog:products', CAPABILITIES[0]);
      await page.locator('[data-testid="product-create-form"] [name="sku"]').fill(data.sku);
      await submit(page, 'product-create-form');

      const created = fixture('inspect', data);
      expect(created.products).toHaveLength(1);
      const productId = Number(created.products[0].product_id);
      expect(productId).toBeGreaterThan(0);

      const editButton = page.locator('[data-testid="product-edit-button"][data-product-id="' + productId + '"]').first();
      await expect(editButton).toBeVisible();
      await editButton.click();
      await page.waitForLoadState('domcontentloaded');

      const updatedSku = data.sku + '-EDIT';
      await page.locator('[data-testid="product-edit-form"] [name="sku"]').fill(updatedSku);
      await page.locator('[data-testid="product-edit-form"] [type="submit"]').click();
      await page.waitForLoadState('domcontentloaded');

      const persisted = fixture('inspect', { ...data, sku: updatedSku });
      expect(persisted.products).toHaveLength(1);
      expect(persisted.products[0].sku).toBe(updatedSku);
    } finally {
      try {
        const cleanupUpdated = fixture('cleanup', { ...data, sku: updatedSku });
        const cleanupOriginal = fixture('cleanup', data);
        expect(Object.values(cleanupUpdated.remaining).every((count) => count === 0)).toBeTruthy();
        expect(Object.values(cleanupOriginal.remaining).every((count) => count === 0)).toBeTruthy();
      } finally {
        guards.assertClean();
      }
    }
  });
});
