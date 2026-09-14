/**
 * 第4章通路：履约周边（退货 / DDP / COD / 分批发）真行为
 * acceptance: ship-ch4-e2e
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend, acceptance_id: ship-ch4-e2e }
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
const EVIDENCE = path.join(ROOT_DIR, 'app/code/Weline/Shipping/doc/evidence/ch4');

function runHarness(action) {
  fs.mkdirSync(EVIDENCE, { recursive: true });
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, evidence_dir: EVIDENCE }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 180000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`harness ${action} failed: ${JSON.stringify(parsed)}`);
  }
  return parsed;
}

moduleDescribe(test, MODULE, '第4章履约周边真行为通路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH4-COMMERCE-EDGES-001', acceptance_id: 'ship-ch4-e2e' },
    'UC：DDP不改价 + 退货买卖家 + COD进总额 + 分批发两种策略',
    async () => {
      test.setTimeout(300000);
      const ddp = runHarness('ch4_ddp_notice');
      expect(ddp.assertions.amount_equal).toBeTruthy();
      expect(ddp.assertions.incoterm).toBe('ddp');
      expect(String(ddp.assertions.duty_notice || '').length).toBeGreaterThan(0);

      const buyer = runHarness('ch4_return_buyer');
      expect(buyer.assertions.amount_minor).toBeGreaterThan(0);

      const seller = runHarness('ch4_return_seller');
      expect(seller.assertions.amount_minor).toBe(0);

      const cod = runHarness('ch4_cod_in_grand_total');
      expect(cod.assertions.cod_fee_amount_minor).toBe(500);
      expect(cod.assertions.grand_total_minor).toBe(12000);
      expect(cod.assertions.money_grand).toBe(12000);

      const firstOnly = runHarness('ch4_split_first_only');
      expect(firstOnly.assertions.second_amount_minor).toBe(0);

      const each = runHarness('ch4_split_each_shipment');
      expect(each.assertions.second_amount_minor).toBeGreaterThan(0);
      expect(each.assertions.strategy).toBe('each_shipment');
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH4-WB-CHECKOUT-002', acceptance_id: 'ship-ch4-wb-checkout' },
    'WB：成功页 totals 含 COD 行键；后台模板同源',
    async ({ page }) => {
      test.setTimeout(180000);
      fs.mkdirSync(EVIDENCE, { recursive: true });
      const successTpl = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Checkout/view/frontend/checkout/success.phtml'),
        'utf8',
      );
      expect(successTpl).toContain('cod_fee_amount_minor');
      expect(successTpl).toContain('货到付款手续费');
      const presenter = fs.readFileSync(
        path.join(ROOT_DIR, 'app/code/Weline/Order/Service/BackendOrderTotalsPresenter.php'),
        'utf8',
      );
      expect(presenter).toContain('货到付款手续费');
      expect(presenter).toContain('cod_fee_amount_minor');

      await loginAsAdmin(page);
      await gotoBackend(page, 'shipping/backend/ratetemplate/index');
      await waitForBackendShellReady(page);
      await page.screenshot({
        path: path.join(EVIDENCE, 'wb-ch4-rate-template.png'),
        fullPage: true,
      });
    },
  );
});
