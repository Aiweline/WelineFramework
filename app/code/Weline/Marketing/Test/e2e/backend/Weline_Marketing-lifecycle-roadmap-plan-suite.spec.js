/**
 * 营销能力分期路线图 — 计划收口套件（e2e-plan-suite）
 *
 * @weline-e2e-spec { module: Weline_Marketing, type: plan-suite, layer: backend, feature: marketing-lifecycle-roadmap }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Marketing';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const CART_PROGRESS_FIXTURE = path.resolve(__dirname, '../frontend/cart-progress-fixture.php');

function runCartProgressFixture(action, payload = {}) {
  const stdout = execFileSync('php', [CART_PROGRESS_FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 60000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`cart-progress fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function open(page, ...parts) {
  const route = buildModuleBackendRoute(MODULE, ...parts);
  await gotoBackend(page, route, { timeout: 90000, settleMs: 700 });
  await waitForBackendShellReady(page);
  const shell = page.locator(CONTENT_SHELL).first();
  await expect(shell).toBeVisible({ timeout: 20000 });
  await expect(shell).not.toContainText(FATAL);
}

moduleDescribe(test, MODULE, '营销路线图计划收口套件', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-plan-suite' },
    '完整功能通路：挽回/生命周期/分群/看板/Ch5进度部件可达',
    async ({ page }) => {
      await loginAsAdmin(page, { allowPasswordFallback: true, timeout: 90000 });

      await open(page, 'winback', 'index');
      await expect(page.locator('[data-testid="marketing-winback-management"]').first()).toBeVisible({ timeout: 20000 });

      await open(page, 'winback', 'add');
      const typeValues = await page
        .locator('select[name="type"] option')
        .evaluateAll((els) => els.map((e) => e.value));
      expect(typeValues).toEqual(
        expect.arrayContaining(['unpaid_order_reminder', 'checkout_abandon_reminder', 'cart_abandon_reminder']),
      );
      await expect(page.locator('input[name="incentive_rule_id"]').first()).toBeVisible();
      await expect(page.locator('input[name="segment_id"]').first()).toBeVisible();

      await open(page, 'lifecycle', 'index');
      await expect(page.locator('[data-testid="marketing-lifecycle-management"]').first()).toBeVisible({ timeout: 20000 });

      await open(page, 'segment', 'index');
      await expect(page.locator('[data-testid="marketing-segment-management"]').first()).toBeVisible({ timeout: 20000 });

      await open(page, 'winbackdashboard', 'index');
      await expect(page.locator('[data-testid="marketing-winback-dashboard"]').first()).toBeVisible({ timeout: 20000 });
      await expect(page.getByText(/不含.*打开率|不含.*点击率/).first()).toBeVisible();
      const headers = await page.locator('[data-testid="marketing-winback-dashboard-table"] thead').innerText();
      expect(/打开率|点击率/i.test(headers)).toBeFalsy();

      const reg = runCartProgressFixture('registry');
      expect(reg.generated_has_cart_progress).toBeTruthy();
      expect(reg.cart_progress_testid).toBeTruthy();
      const math = runCartProgressFixture('build_progress', { threshold: 99, current: 40 });
      expect(math.build.enabled).toBeTruthy();
      expect(math.build.percent).toBeGreaterThan(0);
      const rendered = runCartProgressFixture('render_cart_progress', { threshold: 99 });
      expect(rendered.has_testid).toBeTruthy();
      expect(rendered.has_welcome).toBeTruthy();
    },
  );
});
