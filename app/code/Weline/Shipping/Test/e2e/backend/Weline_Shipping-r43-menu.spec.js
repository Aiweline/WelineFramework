/** @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Shipping';
const PARENT = 'Weline_Backend::shipping_group';
const FIXTURE = path.join(__dirname, 'Weline_Shipping-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['Weline_Shipping::shipping_system', '配送总览', 'shipping-overview-management', 'CK-R43-SHIPPING-001'],
  ['Weline_Shipping::shipping_address', '发货地址', 'shipping-address-management', 'CK-R43-SHIPPING-002'],
  ['Weline_Shipping::delivery_address', '运送地址', 'delivery-address-management', 'CK-R43-SHIPPING-003'],
  ['Weline_Shipping::region', '地区管理', 'shipping-region-management', 'CK-R43-SHIPPING-004'],
  ['Weline_Shipping::zone', '配送区域', 'shipping-zone-management', 'CK-R43-SHIPPING-005'],
  ['Weline_Shipping::carrier', '快递公司', 'shipping-carrier-management', 'CK-R43-SHIPPING-006'],
  ['Weline_Shipping::rate_template', '费用模板', 'shipping-rate-template-management', 'CK-R43-SHIPPING-007'],
  ['Weline_Shipping::free_shipping_rule', '免邮规则', 'shipping-free-rule-management', 'CK-R43-SHIPPING-008'],
  ['Weline_Shipping::shipping_service', '配送服务', 'shipping-service-management', 'CK-R43-SHIPPING-009'],
  ['Weline_Shipping::tracking', '物流跟踪', 'shipping-tracking-management', 'CK-R43-SHIPPING-010'],
];
moduleDescribe(test, MODULE, 'R4.3 配送后台菜单', () => {
  for (const [source, title, anchor, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
    guards.assertClean();
  });

  const writes = [
    ['address', ITEMS[1], 'CK-R43-SHIPPING-WRITE-002'],
    ['delivery', ITEMS[2], 'CK-R43-SHIPPING-WRITE-003'],
    ['region', ITEMS[3], 'CK-R43-SHIPPING-WRITE-004'],
    ['zone', ITEMS[4], 'CK-R43-SHIPPING-WRITE-005'],
    ['carrier', ITEMS[5], 'CK-R43-SHIPPING-WRITE-006'],
    ['rate', ITEMS[6], 'CK-R43-SHIPPING-WRITE-007'],
    ['free', ITEMS[7], 'CK-R43-SHIPPING-WRITE-008'],
    ['service', ITEMS[8], 'CK-R43-SHIPPING-WRITE-009'],
  ];
  for (const [kind, [source, title, anchor], caseId] of writes) {
    moduleCase(test, { module: MODULE, id: caseId }, `${title}通过菜单完成真实写入`, async ({ page }) => {
      requireIsolatedDatabase();
      const guards = installBackendBrowserGuards(page);
      const fixture = runFixture({ action: 'prepare', case: kind });
      try {
        await loginAsAdmin(page);
        await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
        await performWrite(page, kind, fixture);
        const persisted = runFixture({ action: 'inspect', case: kind, token: fixture.token });
        expect(persisted.persisted).toBe(true);
        expect(persisted.row_id).toBeGreaterThan(0);
        guards.assertClean();
      } finally {
        runFixture({ action: 'cleanup', case: kind, token: fixture.token, username: fixture.username });
      }
    });
  }
});

async function performWrite(page, kind, fixture) {
  const token = fixture.token;
  if (kind === 'address' || kind === 'delivery') {
    const prefix = kind === 'address' ? 'shipping-address' : 'shipping-delivery-address';
    await page.getByTestId(`${prefix}-create`).click();
    const form = page.getByTestId(`${prefix}-editor-form`);
    if (kind === 'delivery') await form.locator('select[name="customer_id"]').selectOption(String(fixture.customer_id));
    await form.locator('input[name="name"]').fill(kind === 'address' ? `R43 Shipping ${token}` : `R43 Delivery ${token}`);
    await form.locator('input[name="contact_name"]').fill('R43 Operator');
    await form.locator('input[name="contact_phone"]').fill('13800138000');
    await form.locator('input[name="country"]').fill('中国');
    await form.locator('input[name="province"]').fill('上海市');
    await form.locator('input[name="city"]').fill('上海市');
    await form.locator('input[name="district"]').fill('浦东新区');
    await form.locator('textarea[name="street"]').fill(`R43 Test Street ${token}`);
    await form.locator('input[name="postal_code"]').fill('200120');
    await form.getByTestId(`${prefix}-editor-submit`).click();
  } else if (kind === 'carrier') {
    await page.getByTestId('shipping-carrier-create').click();
    const form = page.getByTestId('shipping-carrier-editor-form');
    await form.locator('input[name="carrier_code"]').fill(fixture.code);
    await form.locator('input[name="carrier_name"]').fill(`R43 Carrier ${token}`);
    await form.locator('select[name="carrier_type"]').selectOption('manual');
    await form.locator('input[name="tracking_url_template"]').fill('https://example.test/track/{tracking_number}');
    await form.getByTestId('shipping-carrier-editor-submit').click();
  } else {
    const form = page.getByTestId(`shipping-${kind === 'rate' ? 'rate-template' : kind === 'free' ? 'free-rule' : kind}-create-form`);
    const fields = {
      region: { region_code: fixture.code, region_name: `R43 Region ${token}` },
      zone: { zone_code: fixture.code, zone_name: `R43 Zone ${token}` },
      rate: { template_code: fixture.code, template_name: `R43 Rate ${token}` },
      free: { rule_code: fixture.code, rule_name: `R43 Free ${token}` },
      service: { service_code: fixture.code, service_name: `R43 Service ${token}` },
    }[kind];
    for (const [name, value] of Object.entries(fields)) await form.locator(`[name="${name}"]`).fill(value);
    if (kind === 'service') {
      await form.locator('select[name="carrier_id"]').selectOption(String(fixture.carrier_id));
      await form.locator('select[name="zone_id"]').selectOption(String(fixture.zone_id));
    }
    await form.locator('button[type="submit"]').click();
  }
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('body')).toContainText(token);
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], {
    cwd: REPO_ROOT,
    input: JSON.stringify(payload),
    encoding: 'utf8',
  });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) {
    throw new Error(`Shipping fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  }
  return decoded;
}

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 Shipping write cases require WELINE_E2E_ISOLATED_DB=1');
  }
}
