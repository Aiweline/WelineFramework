/** @weline-e2e-spec { module: Weline_Payment, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Payment';
const PARENT = 'Weline_Backend::payment_group';
const FIXTURE = path.join(__dirname, 'Weline_Payment-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ACL_FIXTURE = path.resolve(
  __dirname,
  '../../../../Backend/test/e2e/backend/commerce-r43-acl-fixture.php'
);
const ITEMS = [
  ['Weline_Payment::payment_dashboard', '支付诊断', 'payment-diagnostics-management', 'CK-R43-PAYMENT-001'],
  ['Weline_Payment::payment_method', '支付方式', 'payment-method-management', 'CK-R43-PAYMENT-002'],
  ['Weline_Payment::payment_transaction', '交易记录', 'payment-transaction-management', 'CK-R43-PAYMENT-003'],
  ['Weline_Payment::payment_webhook', 'Webhook诊断', 'payment-webhooks-management', 'CK-R43-PAYMENT-004'],
  ['Weline_Payment::payment_reconciliation', '支付对账', 'payment-reconciliation-management', 'CK-R43-PAYMENT-005'],
  ['Weline_Payment::payment_refund_reconciliation', '退款对账', 'payment-refund-reconciliation-management', 'CK-R43-PAYMENT-006'],
];
moduleDescribe(test, MODULE, 'R4.3 支付后台菜单', () => {
  test.setTimeout(180000);
  for (const [source, title, anchor, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-PAYMENT-WRITE-002' }, '支付方式通过菜单同步沙箱提供商并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const actor = runAclFixture({ action: 'prepare', token: actorToken('method') });
    let fixture = null;
    try {
      fixture = runFixture({ action: 'prepare_method' });
      await loginAsAdmin(page, {
        username: actor.full.username,
        password: actor.full.password,
        timeout: 90000,
        settleMs: 800,
        useProxy: false,
      });
      await openBackendMenuBySource(page, ITEMS[1][0], { parentSources: [PARENT], title: ITEMS[1][1], pageAnchor: `[data-testid="${ITEMS[1][2]}"]` });
      const sync = page.getByTestId('payment-method-sync-providers');
      await expect(sync).toBeVisible();
      await sync.click();
      await expect(sync).toHaveAttribute('data-state', 'success');
      const persisted = runFixture({ action: 'inspect_method' });
      expect(persisted.code).toBe('fake_card');
      expect(persisted.provider_class).toContain('FakeProvider');
      guards.assertClean();
    } finally {
      const cleanupFailures = [];
      if (fixture) {
        try { runFixture({ action: 'cleanup_method', snapshot_token: fixture.snapshot_token }); }
        catch (error) { cleanupFailures.push(`method:${error && (error.stack || error.message || error)}`); }
      }
      try { cleanupActor(actor); }
      catch (error) { cleanupFailures.push(`actor:${error && (error.stack || error.message || error)}`); }
      expect(cleanupFailures, cleanupFailures.join('\n')).toEqual([]);
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-PAYMENT-WRITE-003' }, '支付交易通过菜单查询沙箱状态并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const actor = runAclFixture({ action: 'prepare', token: actorToken('transaction') });
    let fixture = null;
    try {
      fixture = runFixture({ action: 'prepare_transaction' });
      await loginAsAdmin(page, {
        username: actor.full.username,
        password: actor.full.password,
        timeout: 90000,
        settleMs: 800,
        useProxy: false,
      });
      await openBackendMenuBySource(page, ITEMS[2][0], { parentSources: [PARENT], title: ITEMS[2][1], pageAnchor: `[data-testid="${ITEMS[2][2]}"]` });
      const row = page.locator('tbody tr').filter({ hasText: fixture.transaction_no });
      await expect(row).toBeVisible();
      const queryStatus = row.locator('.weline-payment-query-status');
      await expect(queryStatus).toBeVisible();
      await queryStatus.click();
      await expect(row).toContainText('支付成功', { timeout: 15000 });
      const persisted = runFixture({ action: 'inspect_transaction', transaction_no: fixture.transaction_no });
      expect(persisted.status).toBe('success');
      expect(persisted.paid_at_present).toBe(true);
      guards.assertClean();
    } finally {
      const cleanupFailures = [];
      if (fixture) {
        try {
          runFixture({ action: 'cleanup_transaction', snapshot_token: fixture.snapshot_token, transaction_no: fixture.transaction_no });
        } catch (error) {
          cleanupFailures.push(`transaction:${error && (error.stack || error.message || error)}`);
        }
      }
      try { cleanupActor(actor); }
      catch (error) { cleanupFailures.push(`actor:${error && (error.stack || error.message || error)}`); }
      expect(cleanupFailures, cleanupFailures.join('\n')).toEqual([]);
    }
  });
});

function runPhpFixture(file, label, payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [file], {
    cwd: REPO_ROOT,
    input: JSON.stringify(payload),
    encoding: 'utf8',
  });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) {
    throw new Error(`${label} fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  }
  return decoded;
}

function runFixture(payload) { return runPhpFixture(FIXTURE, 'Payment', payload); }
function runAclFixture(payload) { return runPhpFixture(ACL_FIXTURE, 'Payment ACL', payload); }
function actorToken(suffix) { return `payment_${suffix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`; }
function cleanupActor(actor) {
  return runAclFixture({
    action: 'cleanup',
    acl_state_hash: actor.acl_state_hash,
    full: actor.full,
    partial: actor.partial,
    denied: actor.denied,
  });
}

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 Payment write cases require WELINE_E2E_ISOLATED_DB=1');
  }
}
