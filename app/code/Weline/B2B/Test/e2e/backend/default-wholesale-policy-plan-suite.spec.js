/**
 * Plan suite: config page + inherit/guard fixture pathway.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: plan, layer: backend }
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
  installBackendBrowserGuards,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const CONFIG = 'b2b/backend/config';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'default-wholesale-policy-inherit-fixture.php');

function runFixture() {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  return JSON.parse(last);
}

moduleDescribe(test, MODULE, '计划组套件 · 默认批发模板全链路', () => {
  test.setTimeout(300000);

  moduleCase(test, { module: MODULE, id: 'CK-B2B-DEFAULT-POLICY-SUITE-001' }, '配置页+继承计价+护栏拒写组测', async ({ page }) => {
    const fixture = runFixture();
    expect(fixture.ok, JSON.stringify(fixture)).toBeTruthy();
    expect(fixture.inherit_source).toBe('b2b_default_policy');
    expect(fixture.guard_rejected).toBeTruthy();

    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 800, useProxy: false });
    await gotoBackend(page, CONFIG + '?website_id=0', {
      waitUntil: 'domcontentloaded',
      useProxy: false,
      timeout: 90000,
    });
    await waitForBackendShellReady(page);

    await expect(page.getByTestId('b2b-selling-mode-config')).toBeVisible({ timeout: 60000 });
    await expect(page.getByTestId('b2b-default-tier-policy-table')).toBeVisible();
    await expect(page.locator('[data-testid^="b2b-default-tier-row-"]')).toHaveCount(13);
    await expect(page.getByTestId('b2b-default-policy-no-rebrush')).toContainText(/不回刷|历史订单/);

    await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
    const residual = (guards.failures || []).filter((msg) => !/query-bin|403/i.test(String(msg)));
    expect(residual, '后台页面存在未处理浏览器错误').toEqual([]);
  });
});
