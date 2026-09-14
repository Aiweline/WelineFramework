/**
 * 防漏收计划组套件（第1–4章关键通路串联）
 * acceptance: ship-anti-undercharge-plan-suite
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend, acceptance_id: ship-anti-undercharge-plan-suite }
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'anti-undercharge-quote-fixture.php');
const EVIDENCE = path.join(ROOT_DIR, 'app/code/Weline/Shipping/doc/evidence');

function runHarness(action, chapter) {
  const dir = path.join(EVIDENCE, chapter);
  fs.mkdirSync(dir, { recursive: true });
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, evidence_dir: dir }),
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

moduleDescribe(test, MODULE, '防漏收计划组套件 Ch1-4', () => {
  moduleCase(
    test,
    {
      module: MODULE,
      id: 'SHIP-ANTI-UNDERCHARGE-PLAN-SUITE-001',
      acceptance_id: 'ship-anti-undercharge-plan-suite',
    },
    '串联关键 UC：Ch1–3 + Ch4 COD/分批发/DDP',
    async () => {
      test.setTimeout(360000);
      const suite = runHarness('suite_critical', 'ch1');
      expect(suite.ok).toBeTruthy();
      expect(Array.isArray(suite.parts)).toBeTruthy();
      expect(suite.parts.length).toBeGreaterThanOrEqual(8);
      for (const part of suite.parts) {
        expect(part.ok, JSON.stringify(part.scenario || part)).toBeTruthy();
      }
      expect(runHarness('ch1_general_35kg_refuse', 'ch1').ok).toBeTruthy();
      expect(runHarness('ch2_pobox_refuse', 'ch2').ok).toBeTruthy();
      expect(runHarness('ch3_seasonal_on', 'ch3').ok).toBeTruthy();
      expect(runHarness('ch4_return_seller', 'ch4').ok).toBeTruthy();
      expect(runHarness('ch4_split_first_only', 'ch4').ok).toBeTruthy();
    },
  );
});
