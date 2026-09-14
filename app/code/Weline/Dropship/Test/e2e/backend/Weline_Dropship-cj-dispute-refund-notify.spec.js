/**
 * 自造 CJ 沙盒订单 + 官方纠纷/退款/补款 webhook 通路
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
const PROBE = path.join(__dirname, 'fixtures', 'cj-dispute-refund-notify-probe.php');
const MARKER = path.join(REPO_ROOT, 'var', 'cj-dispute-refund-notify.pass');

function runProbe({ reuseOk = false } = {}) {
  if (reuseOk) {
    try {
      const fs = require('fs');
      const st = fs.statSync(MARKER);
      const ageMs = Date.now() - st.mtimeMs;
      if (ageMs >= 0 && ageMs < 20 * 60 * 1000) {
        const text = fs.readFileSync(MARKER, 'utf8');
        if (/PASS cj-dispute-refund-notify/.test(text)) {
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
      fs.mkdirSync(path.dirname(MARKER), { recursive: true });
      fs.writeFileSync(MARKER, String(result.stdout || ''), 'utf8');
    } catch (_) {}
  }
  return result;
}

moduleDescribe(test, MODULE, 'CJ 纠纷退款通知通路', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CJ-DISPUTE-REFUND-001' },
    '自造沙盒单注入 DISPUTE/MAKEUP/REFUND 通知投影履约',
    async () => {
      const result = runProbe();
      const out = String(result.stdout || '');
      const err = String(result.stderr || '');
      expect(result.status, `probe failed: ${err || out}`).toBe(0);
      expect(out).toMatch(/PASS cj-dispute-refund-notify/);
      expect(out).toMatch(/final=REFUND_COMPLETE/);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-CJ-DISPUTE-REFUND-PLAN-001' },
    '计划链路：CJ 纠纷退款通知',
    async () => {
      const result = runProbe({ reuseOk: true });
      expect(result.status, String(result.stderr || result.stdout || '')).toBe(0);
      expect(String(result.stdout || '')).toMatch(/PASS cj-dispute-refund-notify/);
    }
  );
});
