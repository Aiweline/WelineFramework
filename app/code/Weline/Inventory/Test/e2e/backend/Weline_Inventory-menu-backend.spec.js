/**
 * R4.3 Inventory menus and decisive WebUI writes.
 *
 * @weline-e2e-spec { module: Weline_Inventory, type: flow, layer: backend }
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

const MODULE = 'Weline_Inventory';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'Weline_Inventory-write-fixture.php');
const PARENT = 'Weline_Backend::commerce:inventory:group';
const CODES = ['stocks', 'adjustments', 'warehouses', 'authorizations', 'reservations', 'leases', 'ledger', 'migration'];
const CAPABILITIES = CODES.map((code) => ({
  sourceId: 'Weline_Inventory::commerce:inventory:' + code,
  parentSource: PARENT,
  urlIncludes: '/weline_inventory/backend/inventory/' + code,
  pageAnchor: '[data-testid="inventory-management-' + code + '"]',
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
  if (!parsed.ok) throw new Error('Inventory fixture ' + action + ' failed: ' + (parsed.error || output));
  return parsed;
}

async function submit(page, testId) {
  const form = page.locator('[data-testid="' + testId + '"]');
  await expect(form).toBeVisible();
  await form.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
}

moduleDescribe(test, MODULE, 'R4.3 库存与仓储菜单及真实写操作', () => {
  test.setTimeout(240000);

  moduleCase(test, { module: MODULE, id: 'CK-R43-INVENTORY-MENU-001' }, '库存与仓储八个管理工作台各出现一次', async ({ page }) => {
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

  moduleCase(test, { module: MODULE, id: 'CK-R43-INVENTORY-MENU-002' }, '逐项点击库存与仓储菜单并验证工作台锚点', async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    const guards = installBackendBrowserGuards(page);
    for (const capability of CAPABILITIES) {
      await openBackendMenuBySource(page, capability.sourceId, capability);
    }
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-INVENTORY-WRITE-001' }, '从真实菜单创建仓库、授权并提交库存调整', async ({ page }) => {
    const data = fixture('prepare', { token: 'i' + Date.now().toString(36) });
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
    try {
      await openBackendMenuBySource(page, 'Weline_Inventory::commerce:inventory:warehouses', CAPABILITIES[2]);
      const warehouseForm = page.locator('[data-testid="inventory-warehouse-create-form"]');
      await warehouseForm.locator('[name="website_id"]').fill(String(data.website_id));
      await warehouseForm.locator('[name="warehouse_code"]').fill(data.warehouse_code);
      await warehouseForm.locator('[name="name"]').fill(data.warehouse_name);
      await warehouseForm.locator('[name="mode"]').selectOption(data.warehouse_mode);
      await warehouseForm.locator('[name="warehouse_type"]').selectOption(data.warehouse_type);
      await submit(page, 'inventory-warehouse-create-form');

      let persisted = fixture('inspect', data);
      expect(persisted.warehouses).toHaveLength(1);
      data.warehouse_id = Number(persisted.warehouses[0].warehouse_id);

      await openBackendMenuBySource(page, 'Weline_Inventory::commerce:inventory:authorizations', CAPABILITIES[3]);
      const authorizationForm = page.locator('[data-testid="inventory-warehouse-authorization-form"]');
      await authorizationForm.locator('[name="website_id"]').fill(String(data.website_id));
      await authorizationForm.locator('[name="store_id"]').fill(String(data.store_id));
      await authorizationForm.locator('[name="warehouse_id"]').fill(String(data.warehouse_id));
      await authorizationForm.locator('[name="is_default"]').selectOption('0');
      await submit(page, 'inventory-warehouse-authorization-form');

      await openBackendMenuBySource(page, 'Weline_Inventory::commerce:inventory:adjustments', CAPABILITIES[1]);
      const stockForm = page.locator('[data-testid="inventory-stock-adjust-form"]');
      await stockForm.locator('[name="website_id"]').fill(String(data.website_id));
      await stockForm.locator('[name="store_id"]').fill(String(data.store_id));
      await stockForm.locator('[name="offer_id"]').fill(String(data.offer_id));
      await stockForm.locator('[name="on_hand_minor"]').fill(String(data.on_hand_minor));
      await stockForm.locator('[name="command_id"]').fill(data.command_id);
      await stockForm.locator('[name="strategy"]').selectOption(data.strategy);
      await submit(page, 'inventory-stock-adjust-form');

      persisted = fixture('inspect', data);
      expect(persisted.authorizations).toHaveLength(1);
      expect(persisted.stocks).toHaveLength(1);
      expect(Number(persisted.stocks[0].on_hand_minor)).toBe(data.on_hand_minor);
      expect(persisted.ledger).toHaveLength(1);
      expect(persisted.ledger[0].event_type).toBe('stock_set');
      guards.assertClean();
    } finally {
      const cleanup = fixture('cleanup', data);
      expect(Object.values(cleanup.remaining).every((count) => count === 0)).toBeTruthy();
    }
  });
});
