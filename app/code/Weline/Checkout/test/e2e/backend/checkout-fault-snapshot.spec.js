/**
 * Checkout session error snapshot: filter errors-only and show missing_weight.
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: feature, layer: backend }
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
  BACKEND_FATAL_PATTERN,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Checkout';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'checkout-fault-snapshot-fixture.php');

function runFixture(payload) {
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify(payload),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const result = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).pop() || '{}');
  if (!result.ok) {
    throw new Error(result.error || output);
  }
  return result;
}

moduleDescribe(test, MODULE, 'Checkout session fault snapshot', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-CHECKOUT-FAULT-SNAPSHOT-001' },
    '后台结账会话仅异常能筛到缺重快照 token',
    async ({ page }) => {
      const seeded = runFixture({ action: 'prepare' });
      try {
        await loginAsAdmin(page, { timeout: 90000, settleMs: 800, bootstrapModes: ['wls', 'fpm'] });
        await gotoBackend(page, '/checkout/backend/session/index?error=1', {
          timeout: 60000,
          settleMs: 800,
        });
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
        const root = page.locator('[data-testid="checkout-session-management"]');
        await expect(root).toBeVisible();
        const row = root.locator('[data-testid="checkout-session-row"]').filter({
          hasText: seeded.quote_token,
        }).first();
        await expect(row).toBeVisible();
        await expect(row).toHaveAttribute('data-error-code', 'missing_weight');
        await expect(row.getByText('商品缺少重量')).toBeVisible();
        const details = row.locator('xpath=following-sibling::tr[1]').locator('[data-testid="checkout-session-error-snapshot"]');
        await details.getByText('技术明细').click();
        await expect(details.locator('[data-testid="checkout-session-error-json"]')).toContainText('missing_weight');
        await expect(details.locator('[data-testid="checkout-session-error-json"]')).toContainText('weight_minor');
      } finally {
        runFixture({ action: 'cleanup', quote_token: seeded.quote_token });
      }
    },
  );
});
