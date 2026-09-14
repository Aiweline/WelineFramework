/**
 * 结账货源运费试算覆盖通路（Fake + Cj 映射；不打公网）
 *
 * @weline-e2e-spec { module: Weline_Dropship, type: flow, layer: backend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const path = require('path');
const { spawnSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Dropship';
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const PROBE = path.join(__dirname, 'fixtures', 'checkout-dropship-freight-overlay-probe.php');

function runProbe() {
  return spawnSync(process.env.PHP_BINARY || 'php', [PROBE], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    timeout: 120000,
  });
}

moduleDescribe(test, MODULE, '结账货源运费 overlay', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CHECKOUT-FREIGHT-001' },
    'Fake 试算覆盖 + Cj 请求映射 + Checkout overlay 挂点',
    async () => {
      const result = runProbe();
      const out = String(result.stdout || '');
      const err = String(result.stderr || '');
      expect(result.status, `probe failed: ${err || out}`).toBe(0);
      expect(out).toMatch(/PASS checkout-dropship-freight-overlay/);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CHECKOUT-FREIGHT-PLAN-001' },
    '计划链路：结账货源运费 e2e 组',
    async () => {
      const result = runProbe();
      expect(result.status, String(result.stderr || result.stdout || '')).toBe(0);
      expect(String(result.stdout || '')).toMatch(/PASS checkout-dropship-freight-overlay/);
    }
  );
});
