/**
 * 第2章通路：偏远加价 + 地址/危品门禁（真报价 harness）
 * acceptance: ship-ch2-e2e
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend, acceptance_id: ship-ch2-e2e }
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
  getRuntimeInfo,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'anti-undercharge-quote-fixture.php');
const EVIDENCE = path.join(ROOT_DIR, 'app/code/Weline/Shipping/doc/evidence/ch2');

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

async function gotoCheckoutShell(page) {
  const runtime = getRuntimeInfo({ useProxy: false });
  const origin = String(
    process.env.PLAYWRIGHT_TARGET_ORIGIN
      || runtime.runtime?.target_origin
      || '',
  ).replace(/\/$/, '');
  expect(origin).not.toBe('');
  let lastError;
  for (let i = 0; i < 3; i++) {
    try {
      const res = await page.goto(`${origin}/checkout`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
      });
      expect(res && res.status()).toBeLessThan(500);
      return;
    } catch (err) {
      lastError = err;
      await page.waitForTimeout(1500 * (i + 1));
    }
  }
  throw lastError;
}

moduleDescribe(test, MODULE, '第2章门禁与加价真行为通路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH2-GATES-SURCHARGE-001', acceptance_id: 'ship-ch2-e2e' },
    'UC 真报价：新疆加价+POBox拒+危品拒+外运不叠+免邮保留偏远',
    async () => {
      test.setTimeout(180000);
      const xj = runHarness('ch2_cn_xj_surcharge');
      expect(xj.assertions.surcharge_found).toBeTruthy();
      expect(xj.assertions.surcharge_sum).toBeGreaterThanOrEqual(2500);

      const pobox = runHarness('ch2_pobox_refuse');
      expect(pobox.assertions.gate_pobox).toBe(false);
      expect(pobox.assertions.conflict).toBeTruthy();

      const hazard = runHarness('ch2_hazard_refuse');
      expect(hazard.assertions.refused || hazard.assertions.conflict).toBeTruthy();

      const external = runHarness('ch2_external_no_shop_surcharge');
      expect(external.assertions.local_has_surcharge).toBeTruthy();
      expect(external.assertions.manager_no_direct_surcharge).toBeTruthy();

      const free = runHarness('ch2_free_keeps_remote');
      expect(free.assertions.has_free_reason).toBeTruthy();
      expect(free.assertions.surcharge_found).toBeTruthy();
      expect(free.assertions.amount_minor).toBeGreaterThanOrEqual(2500);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH2-WB-CHECKOUT-002', acceptance_id: 'ship-ch2-wb-checkout' },
    'WB 同源：结账透传 delivery_point_type / 危品 + 打开结账壳',
    async ({ page }) => {
      test.setTimeout(180000);
      fs.mkdirSync(EVIDENCE, { recursive: true });
      const checkoutRoot = path.join(ROOT_DIR, 'app/code/Weline/Checkout');
      const checkout = fs.readFileSync(
        path.join(
          checkoutRoot,
          'extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        ),
        'utf8',
      );
      const ctx = fs.readFileSync(
        path.join(checkoutRoot, 'Service/CheckoutDeliveryContextService.php'),
        'utf8',
      );
      expect(checkout).toContain('delivery_point_type');
      expect(checkout).toContain('shipping_hazard_class');
      expect(ctx).toContain('delivery_point_type');

      await gotoCheckoutShell(page);
      await page.screenshot({
        path: path.join(EVIDENCE, 'wb-checkout.png'),
        fullPage: true,
      });
    },
  );
});
