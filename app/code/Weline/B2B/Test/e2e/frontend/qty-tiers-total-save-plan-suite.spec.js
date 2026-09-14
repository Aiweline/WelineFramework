/**
 * Plan-suite: wholesale qty-tier total-save pathway regression.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'qty-tiers-total-save-fixture.php');

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

moduleDescribe(test, MODULE, '批发阶梯共省计划链路', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-QTY-TIERS-TOTAL-SAVE-SUITE' },
    '计划链路e2e组：共省公式与模板契约回归',
    async () => {
      const result = runFixture();
      expect(result.ok, JSON.stringify(result)).toBe(true);
      expect(result.template_wired).toBe(true);
      expect(Array.isArray(result.rows)).toBe(true);
      expect(result.rows.length).toBe(4);
    },
  );
});
