/**
 * 第3章通路：同仓分箱 + 签名保价 + 旺季燃油（真报价 harness）
 * acceptance: ship-ch3-e2e
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend, acceptance_id: ship-ch3-e2e }
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
const EVIDENCE = path.join(ROOT_DIR, 'app/code/Weline/Shipping/doc/evidence/ch3');

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

moduleDescribe(test, MODULE, '第3章分箱与附加费真行为通路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH3-PACK-ADDON-001', acceptance_id: 'ship-ch3-e2e' },
    'UC 真报价：拆箱相加+签名加价+旺季加价',
    async () => {
      test.setTimeout(180000);
      const split = runHarness('ch3_split_boxes');
      expect(split.assertions.package_count).toBeGreaterThanOrEqual(2);
      expect(split.assertions.split_ge_single).toBeTruthy();

      const sig = runHarness('ch3_signature_addon');
      expect(sig.assertions.amount_increased).toBeTruthy();
      expect(sig.assertions.has_signature_addon).toBeTruthy();

      const seasonal = runHarness('ch3_seasonal_on');
      expect(seasonal.assertions.amount_increased).toBeTruthy();
      expect(seasonal.assertions.has_seasonal).toBeTruthy();

      const warehouse = runHarness('ch3_split_after_warehouse');
      expect(warehouse.assertions.package_count).toBeGreaterThanOrEqual(2);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SHIP-CH3-WB-CHECKOUT-002', acceptance_id: 'ship-ch3-wb-checkout' },
    'WB：报价请求支持 addons 透传 + 结账壳',
    async ({ page }) => {
      test.setTimeout(180000);
      fs.mkdirSync(EVIDENCE, { recursive: true });
      const root = path.join(ROOT_DIR, 'app/code/Weline/Shipping');
      const mgr = fs.readFileSync(path.join(root, 'Service/ShippingServiceManager.php'), 'utf8');
      const provider = fs.readFileSync(
        path.join(
          root,
          'extends/module/Weline_Shipping/ShippingProvider/LocalRateTemplateProvider.php',
        ),
        'utf8',
      );
      expect(mgr).toContain("address['addons']");
      expect(provider).toContain('request->addons');

      await gotoCheckoutShell(page);
      await page.screenshot({
        path: path.join(EVIDENCE, 'wb-checkout.png'),
        fullPage: true,
      });
    },
  );
});
