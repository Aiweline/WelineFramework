/**
 * Wholesale display gate: enabled + tiers required; else retail sell / tob cart without MOQ.
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
const FIXTURE_SCRIPT = path.resolve(__dirname, 'wholesale-display-gate-fixture.php');

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

moduleDescribe(MODULE, () => {
  moduleCase('wholesale display requires tob flag + active tiers; else retail qty in tob cart', () => {
    test('fixture gate matrix', async () => {
      const result = runFixture();
      expect(result.ok, JSON.stringify(result)).toBe(true);
      expect(result.eligible_display).toBe(true);
      expect(result.flag_off_display).toBe(false);
      expect(result.no_tiers_display).toBe(false);
      expect(result.ineligible_qty_ok).toBe(true);
      expect(result.eligible_qty_ok).toBe(false);
      expect(result.templates_wired).toBe(true);
    });
  });
});
