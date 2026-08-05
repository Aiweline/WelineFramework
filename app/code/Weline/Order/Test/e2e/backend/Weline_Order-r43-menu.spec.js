/** @weline-e2e-spec { module: Weline_Order, type: flow, layer: backend } */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  openBackendMenuBySource,
  waitForBackendShellReady,
  BACKEND_FATAL_PATTERN,
} = require('../../../../../../../tests/e2e/framework');

const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const STATUS_FIXTURE = path.resolve(__dirname, 'order-r43-write-fixture.php');
const TRADE_FIXTURE = path.resolve(__dirname, 'order-r43-trade-fixture.php');
const ACL_FIXTURE = path.resolve(
  __dirname,
  '../../../../Backend/test/e2e/backend/commerce-r43-acl-fixture.php'
);

function fixture(file, payload) {
  const output = execFileSync('php', [file], {
    cwd: ROOT_DIR,
    input: JSON.stringify(payload),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const value = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).pop() || '{}');
  if (!value.ok) throw new Error(value.error || output);
  return value;
}

function statusFixture(action, token) {
  return fixture(STATUS_FIXTURE, { action, token });
}

function tradeFixture(action, token) {
  return fixture(TRADE_FIXTURE, { action, token });
}

function aclFixture(payload) {
  return fixture(ACL_FIXTURE, payload);
}

function cleanupAclActor(actor) {
  return aclFixture({
    action: 'cleanup',
    acl_state_hash: actor.acl_state_hash,
    full: actor.full,
    partial: actor.partial,
    denied: actor.denied,
  });
}

function token() {
  return Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
}

function expectExactCleanup(cleanup) {
  expect(cleanup.remaining).toEqual(Object.fromEntries(
    Object.keys(cleanup.remaining).map(key => [key, 0]),
  ));
}

async function openMenu(page, entry, guards, identity = null) {
  await loginAsAdmin(page, {
    timeout: 90000,
    settleMs: 800,
    ...(identity ? { username: identity.username, password: identity.password } : {}),
  });
  await openBackendMenuBySource(page, entry.source, {
    title: entry.title,
    parentSources: [entry.parent],
    urlIncludes: entry.url,
  });
  await waitForBackendShellReady(page);
  await expect(page.locator(entry.anchor)).toHaveCount(1);
  await expect(page.locator(entry.anchor)).toBeVisible();
  await expect(page.locator(entry.anchor).locator('.alert-danger')).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
  guards.assertClean();
}

async function submitWorkbenchForm(page, form, expectedUrl) {
  await Promise.all([
    page.waitForURL(url => url.toString().includes(expectedUrl), { timeout: 30000 }),
    form.locator('button[type="submit"]').click(),
  ]);
  await waitForBackendShellReady(page);
}

const MODULE = 'Weline_Order';

moduleDescribe(test, MODULE, 'R4.3 Order 后台菜单', () => {
  for (const entry of [
    { id: 'CK-R43-ORDER-001', source: 'Weline_Order::order_list', title: '订单列表', parent: 'Weline_Backend::order_group', url: '/weline_order/backend/order/index', anchor: '[data-testid="order-management"]' },
    { id: 'CK-R43-ORDER-002', source: 'Weline_Order::payment_manage', title: '订单收款记录', parent: 'Weline_Backend::payment_group', url: '/weline_order/backend/records/payment', anchor: '[data-testid="order-payment-management"]' },
    { id: 'CK-R43-ORDER-003', source: 'Weline_Order::shipment_manage', title: '订单发货管理', parent: 'Weline_Backend::order_group', url: '/weline_order/backend/shipment/index', anchor: '[data-testid="order-shipment-management"]' },
    { id: 'CK-R43-ORDER-004', source: 'Weline_Order::refund_manage', title: '订单退款管理', parent: 'Weline_Backend::order_group', url: '/weline_order/backend/refund/index', anchor: '[data-testid="order-refund-management"]' },
    { id: 'CK-R43-ORDER-005', source: 'Weline_Order::invoice_manage', title: '订单发票管理', parent: 'Weline_Backend::order_group', url: '/weline_order/backend/invoice/index', anchor: '[data-testid="order-invoice-management"]' },
    { id: 'CK-R43-ORDER-006', source: 'Weline_Order::status_manage', title: '订单状态管理', parent: 'Weline_Backend::order_group', url: '/weline_order/backend/status/index', anchor: '[data-testid="order-status-management"]' },
    { id: 'CK-R43-ORDER-007', source: 'Weline_Order::exception_manage', title: '订单异常与补偿', parent: 'Weline_Backend::order_group', url: '/weline_order/backend/records/exceptions', anchor: '[data-testid="order-exception-management"]' },
  ]) {
    moduleCase(
      test,
      { module: MODULE, id: entry.id },
      `从侧栏进入${entry.title}`,
      async ({ page }) => {
        const guards = installBackendBrowserGuards(page);
        await openMenu(page, entry, guards);
        guards.assertClean();
      },
    );
  }

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ORDER-101' },
    '通过后台 UI 创建订单状态并验证 PostgreSQL 持久化',
    async ({ page }) => {
      const ownedToken = token();
      const seed = statusFixture('prepare', ownedToken);
      const actor = aclFixture({ action: 'prepare', token: `order_status_${ownedToken}` });
      const guards = installBackendBrowserGuards(page);
      try {
        await openMenu(page, {
          source: 'Weline_Order::status_manage',
          title: '订单状态管理',
          parent: 'Weline_Backend::order_group',
          url: '/weline_order/backend/status/index',
          anchor: '[data-testid="order-status-management"]',
        }, guards, actor.full);
        await page.locator('[data-testid="order-status-create"]').click();
        await expect(page).toHaveURL(/order\/backend\/status\/edit/);
        const statusForm = page.locator('[data-testid="order-status-form"]');
        await expect(statusForm).toBeVisible();
        const grantVersion = Number(await statusForm.locator('[name="expected_grant_version"]').inputValue());
        expect(grantVersion).toBeGreaterThan(0);
        await page.locator('input[name="code"]').fill(seed.code);
        await page.locator('input[name="name"]').fill(seed.name);
        await page.locator('select[name="color"]').selectOption('success');
        await page.locator('input[name="icon"]').fill('mdi-check-decagram');
        await page.locator('textarea[name="description"]').fill('R43 browser-created order status');
        await page.locator('select[name="is_active"]').selectOption('1');
        await page.locator('input[name="sort_order"]').fill('43');
        await page.locator('[data-testid="order-status-form"] button[type="submit"]').click();
        await expect(page).toHaveURL(/order\/backend\/status\/index/, { timeout: 20000 });
        await expect(page.getByText(seed.code, { exact: true })).toBeVisible();
        await expect.poll(() => statusFixture('inspect', ownedToken).rows.length, { timeout: 15000 }).toBe(1);
        expect(statusFixture('inspect', ownedToken).rows[0]).toMatchObject({
          code: seed.code,
          name: seed.name,
          color: 'success',
          is_active: 1,
          sort_order: 43,
        });
        guards.assertClean();
      } finally {
        const cleanupFailures = [];
        try { statusFixture('cleanup', ownedToken); }
        catch (error) { cleanupFailures.push(`status:${error && (error.stack || error.message || error)}`); }
        try { cleanupAclActor(actor); }
        catch (error) { cleanupFailures.push(`actor:${error && (error.stack || error.message || error)}`); }
        expect(cleanupFailures, cleanupFailures.join('\n')).toEqual([]);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ORDER-102' },
    '从发货菜单提交仓维 CAS 发货并验证幂等 PostgreSQL ledger',
    async ({ page }) => {
      test.setTimeout(120000);
      const ownedToken = token();
      const seed = tradeFixture('prepare', ownedToken).fixture;
      const guards = installBackendBrowserGuards(page);
      try {
        await openMenu(page, {
          source: 'Weline_Order::shipment_manage',
          title: '订单发货管理',
          parent: 'Weline_Backend::order_group',
          url: '/weline_order/backend/shipment/index',
          anchor: '[data-testid="order-shipment-management"]',
        }, guards, seed.admin);
        let row = page.locator(`[data-testid="shipment-candidate"][data-unit-uuid="${seed.unit_uuid}"]`);
        await expect(row).toBeVisible();
        let form = row.locator('[data-testid="shipment-command-form"]');
        await form.locator('input[name="qty_minor"]').fill('1');
        await form.locator('input[name="idempotency_key"]').fill(seed.shipment_idempotency_key);
        await submitWorkbenchForm(page, form, '/weline_order/backend/shipment/index');

        await expect.poll(
          () => Number(tradeFixture('inspect', ownedToken).data.fulfillment.fulfilled_qty_minor),
          { timeout: 20000 },
        ).toBe(1);
        let inspected = tradeFixture('inspect', ownedToken).data;
        expect(inspected.shipment_ledger_count).toBe(1);
        expect(inspected.shipment_ledger.idempotency_key).toBe(seed.shipment_idempotency_key);
        expect(Number(inspected.fulfillment.fulfillment_version)).toBe(1);

        row = page.locator(`[data-testid="shipment-candidate"][data-unit-uuid="${seed.unit_uuid}"]`);
        form = row.locator('[data-testid="shipment-command-form"]');
        await form.locator('input[name="qty_minor"]').fill('1');
        await form.locator('input[name="idempotency_key"]').fill(seed.shipment_idempotency_key);
        await form.locator('input[name="expected_version"]').evaluate(input => { input.value = '0'; });
        await submitWorkbenchForm(page, form, '/weline_order/backend/shipment/index');
        inspected = tradeFixture('inspect', ownedToken).data;
        expect(inspected.shipment_ledger_count).toBe(1);
        expect(Number(inspected.fulfillment.fulfilled_qty_minor)).toBe(1);
        guards.assertClean();
      } finally {
        expectExactCleanup(tradeFixture('cleanup', ownedToken).data);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ORDER-103' },
    '从退款菜单提交服务端重算退款并验证占额与确定性 outbox',
    async ({ page }) => {
      test.setTimeout(120000);
      const ownedToken = token();
      const seed = tradeFixture('prepare', ownedToken).fixture;
      const guards = installBackendBrowserGuards(page);
      try {
        await openMenu(page, {
          source: 'Weline_Order::refund_manage',
          title: '订单退款管理',
          parent: 'Weline_Backend::order_group',
          url: '/weline_order/backend/refund/index',
          anchor: '[data-testid="order-refund-management"]',
        }, guards, seed.admin);
        let row = page.locator(`[data-testid="refund-candidate"][data-item-uuid="${seed.item_uuid}"]`);
        await expect(row).toBeVisible();
        let form = row.locator('[data-testid="refund-command-form"]');
        await form.locator('input[name="qty_minor"]').fill('1');
        await form.locator('input[name="reason"]').fill('R43 browser refund');
        await form.locator('input[name="idempotency_key"]').fill(seed.refund_idempotency_key);
        await submitWorkbenchForm(page, form, '/weline_order/backend/refund/index');

        await expect.poll(
          () => tradeFixture('inspect', ownedToken).data.refund_case_count,
          { timeout: 20000 },
        ).toBe(1);
        let inspected = tradeFixture('inspect', ownedToken).data;
        expect(inspected.payment_refund_count).toBe(1);
        expect(inspected.refund_provider_outbox_count).toBe(1);
        expect(inspected.refund_provider_queue_count).toBe(1);
        expect(Number(inspected.refund_case.amount_minor)).toBe(500);
        expect(inspected.refund_case.idempotency_key).toBe(seed.refund_idempotency_key);
        expect(inspected.payment_refund.status).toBe('requested');
        expect(inspected.payment_refund.channel_status).toBe('not_submitted');

        row = page.locator(`[data-testid="refund-candidate"][data-item-uuid="${seed.item_uuid}"]`);
        form = row.locator('[data-testid="refund-command-form"]');
        await form.locator('input[name="qty_minor"]').fill('1');
        await form.locator('input[name="reason"]').fill('R43 browser refund');
        await form.locator('input[name="idempotency_key"]').fill(seed.refund_idempotency_key);
        await submitWorkbenchForm(page, form, '/weline_order/backend/refund/index');
        inspected = tradeFixture('inspect', ownedToken).data;
        expect(inspected.refund_case_count).toBe(1);
        expect(inspected.payment_refund_count).toBe(1);
        expect(inspected.refund_provider_outbox_count).toBe(1);
        guards.assertClean();
      } finally {
        expectExactCleanup(tradeFixture('cleanup', ownedToken).data);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ORDER-104' },
    '从发票菜单处理 Payment effect 并验证一单一票与幂等重放',
    async ({ page }) => {
      test.setTimeout(120000);
      const ownedToken = token();
      const seed = tradeFixture('prepare', ownedToken).fixture;
      const guards = installBackendBrowserGuards(page);
      try {
        await openMenu(page, {
          source: 'Weline_Order::invoice_manage',
          title: '订单发票管理',
          parent: 'Weline_Backend::order_group',
          url: '/weline_order/backend/invoice/index',
          anchor: '[data-testid="order-invoice-management"]',
        }, guards, seed.admin);
        let row = page.locator(`[data-testid="invoice-candidate"][data-outbox-code="${seed.invoice_outbox_code}"]`);
        await expect(row).toBeVisible();
        await submitWorkbenchForm(
          page,
          row.locator('[data-testid="invoice-command-form"]'),
          '/weline_order/backend/invoice/index',
        );
        await expect.poll(
          () => tradeFixture('inspect', ownedToken).data.invoice_count,
          { timeout: 20000 },
        ).toBe(1);
        let inspected = tradeFixture('inspect', ownedToken).data;
        expect(inspected.invoice_outbox_status).toBe('done');
        expect(inspected.invoice.status).toBe('issued');
        expect(Number(inspected.invoice.amount_minor)).toBe(1000);
        expect(inspected.invoice.effect_key).toBe(seed.invoice_effect_key);

        row = page.locator(`[data-testid="invoice-candidate"][data-outbox-code="${seed.invoice_outbox_code}"]`);
        await submitWorkbenchForm(
          page,
          row.locator('[data-testid="invoice-command-form"]'),
          '/weline_order/backend/invoice/index',
        );
        inspected = tradeFixture('inspect', ownedToken).data;
        expect(inspected.invoice_count).toBe(1);
        expect(inspected.invoice_outbox_status).toBe('done');
        guards.assertClean();
      } finally {
        expectExactCleanup(tradeFixture('cleanup', ownedToken).data);
      }
    },
  );
});
