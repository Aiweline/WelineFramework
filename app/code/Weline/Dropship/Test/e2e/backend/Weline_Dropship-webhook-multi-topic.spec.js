/**
 * 多 topic webhook 完整前后端通路：官方样例解析 → inbox → processOne
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
const PROBE = path.join(__dirname, 'fixtures', 'webhook-multi-topic-probe.php');

function runProbe() {
  return spawnSync(process.env.PHP_BINARY || 'php', [PROBE], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    timeout: 120000,
  });
}

moduleDescribe(test, MODULE, '多 topic Webhook 通路', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-WEBHOOK-MULTI-TOPIC-001' },
    'ORDER/PRODUCT/STOCK/LOGISTIC/MAKEUP/PRIVATE/DISPUTE 投影',
    async () => {
      const result = runProbe();
      const out = String(result.stdout || '');
      const err = String(result.stderr || '');
      expect(result.status, `probe failed: ${err || out}`).toBe(0);
      expect(out).toMatch(/PASS webhook-multi-topic/);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-DROPSHIP-WEBHOOK-MULTI-TOPIC-PLAN-001' },
    '计划链路：多 topic webhook e2e 组',
    async () => {
      const result = runProbe();
      expect(result.status, String(result.stderr || result.stdout || '')).toBe(0);
      expect(String(result.stdout || '')).toMatch(/PASS webhook-multi-topic/);
    }
  );
});
