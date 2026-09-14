/**
 * FAQ PDP resolve/merge chapter e2e.
 *
 * @weline-e2e-spec { module: Weline_Faq, type: feature, layer: frontend }
 * @weline-e2e-runtime fallback
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Faq';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'faq-pdp-resolve-merge-fixture.php');

function runFixture() {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  return JSON.parse(lines[lines.length - 1] || '{}');
}

moduleDescribe(test, MODULE, 'e2e-resolve-merge', () => {
  moduleCase(test, { module: MODULE, id: 'e2e-resolve-merge' }, 'store override product merge suppress', async () => {
    const result = runFixture();
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.packs).toEqual(['retail', 'cross_border', 'virtual', 'b2b']);
    expect(result.merged_keys).toContain('material');
  });
});
