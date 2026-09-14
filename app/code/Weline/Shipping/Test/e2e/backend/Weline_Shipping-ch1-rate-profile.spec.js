/**
 * 第1章通路：阶梯表 + Profile + 计费重（真报价 harness）
 * acceptance: ship-ch1-e2e
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend, acceptance_id: ship-ch1-e2e }
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
  loginAsAdmin,
  gotoBackend,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'anti-undercharge-quote-fixture.php');
const EVIDENCE = path.join(ROOT_DIR, 'app/code/Weline/Shipping/doc/evidence/ch1');
const BACKEND_ROUTE = 'shipping/backend/ratetemplate/index';

function runHarness(action) {
  fs.mkdirSync(EVIDENCE, { recursive: true });
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, evidence_dir: EVIDENCE }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 120000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`harness ${action} failed: ${JSON.stringify(parsed)}`);
  }
  return parsed;
}

moduleDescribe(test, MODULE, '第1章阶梯表与Profile真行为通路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH1-RATE-PROFILE-001', acceptance_id: 'ship-ch1-e2e' },
    'UC 真报价：种子+美洲2kg+General拒35kg+Heavy出价+缺重拒表',
    async () => {
      test.setTimeout(180000);
      const seed = runHarness('ch1_seed_profiles');
      expect(seed.assertions.templates_ok).toBeTruthy();
      expect(seed.assertions.profiles_ok).toBeTruthy();

      const americas = runHarness('ch1_americas_2kg');
      expect(americas.assertions.amount_match).toBeTruthy();
      expect(americas.assertions.amount_minor).toBe(11500);

      const refuse = runHarness('ch1_general_35kg_refuse');
      expect(refuse.assertions.refused || refuse.assertions.missing_service).toBeTruthy();

      const heavy = runHarness('ch1_heavy_35kg_quote');
      expect(heavy.assertions.min_amount_ok).toBeTruthy();
      expect(heavy.assertions.gt_americas_2kg).toBeTruthy();

      const missing = runHarness('ch1_missing_dims_refuse');
      expect(missing.assertions.missing_weight).toBeTruthy();
      expect(missing.assertions.missing_dims).toBeTruthy();
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH1-WB-ADMIN-002', acceptance_id: 'ship-ch1-wb-admin' },
    'WB：后台费用模板可见 max_weight / weight_table',
    async ({ page }) => {
      test.setTimeout(180000);
      fs.mkdirSync(EVIDENCE, { recursive: true });
      const adminTpl = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Shipping/view/templates/Backend/RateTemplate/index.phtml'),
        'utf8',
      );
      expect(adminTpl).toMatch(/max_weight_kg/);
      expect(adminTpl).toMatch(/weight_table|rate_brackets/);

      await loginAsAdmin(page, { useProxy: false });
      await gotoBackend(page, BACKEND_ROUTE, {
        waitUntil: 'domcontentloaded',
        useProxy: false,
      });
      await waitForBackendShellReady(page);
      const body = await page.locator('body').innerHTML();
      expect(body).toMatch(/max_weight_kg|最大可寄重量/);
      expect(body).toMatch(/weight_table|重量阶梯|rate_brackets/);
      await page.screenshot({
        path: path.join(EVIDENCE, 'wb-admin.png'),
        fullPage: true,
      });
    },
  );
});
