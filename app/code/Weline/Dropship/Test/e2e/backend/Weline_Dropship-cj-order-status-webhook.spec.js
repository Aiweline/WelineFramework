/**
 * CJ 沙盒真实订单状态 Webhook 通路：createOrderV3(isSandbox)→sandboxAdvance→CJ ORDER 推送→履约投影
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
const PROBE = path.join(__dirname, 'fixtures', 'cj-order-status-webhook-probe.php');

function runProbe({ reuseOk = false } = {}) {
  const marker = path.join(REPO_ROOT, 'var', 'cj-sandbox-order-status-webhook.pass');
  if (reuseOk) {
    try {
      const fs = require('fs');
      const st = fs.statSync(marker);
      const ageMs = Date.now() - st.mtimeMs;
      if (ageMs >= 0 && ageMs < 15 * 60 * 1000) {
        const text = fs.readFileSync(marker, 'utf8');
        if (/PASS cj-sandbox-order-status-webhook/.test(text)) {
          return { status: 0, stdout: text, stderr: '', reused: true };
        }
      }
    } catch (_) {}
  }
  const result = spawnSync(process.env.PHP_BINARY || 'php', [PROBE], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    timeout: 180000,
  });
  if (result.status === 0) {
    try {
      const fs = require('fs');
      fs.mkdirSync(path.dirname(marker), { recursive: true });
      fs.writeFileSync(marker, String(result.stdout || ''), 'utf8');
    } catch (_) {}
  }
  return result;
}

moduleDescribe(test, MODULE, 'CJ 沙盒订单状态 Webhook 通路', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CJ-ORDER-STATUS-HOOK-001' },
    '真实沙盒建单推进后 ORDER webhook 投影履约',
    async () => {
      const result = runProbe();
      const out = String(result.stdout || '');
      const err = String(result.stderr || '');
      expect(result.status, `probe failed: ${err || out}`).toBe(0);
      expect(out).toMatch(/PASS cj-sandbox-order-status-webhook/);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CJ-ORDER-STATUS-PLAN-001' },
    '计划链路：CJ 沙盒订单状态 hook',
    async () => {
      const result = runProbe({ reuseOk: true });
      expect(result.status, String(result.stderr || result.stdout || '')).toBe(0);
      expect(String(result.stdout || '')).toMatch(/PASS cj-sandbox-order-status-webhook/);
    }
  );
});
