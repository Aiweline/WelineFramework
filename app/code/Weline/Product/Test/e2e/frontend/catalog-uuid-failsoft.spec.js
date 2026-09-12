/**
 * Catalog admin search fail-soft on corrupt global_product_uuid.
 *
 * @weline-e2e-spec { module: Weline_Product, type: feature, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'catalog-uuid-failsoft-fixture.php');

function runFixture() {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  return JSON.parse(lines[lines.length - 1] || '{}');
}

moduleDescribe(MODULE, () => {
  moduleCase('admin catalog search fails soft on corrupt UUID', () => {
    test('fixture matrix', async () => {
      const result = runFixture();
      expect(result.ok, JSON.stringify(result)).toBe(true);
      expect(result.wired_bulk_permissive).toBe(true);
      expect(result.unit_ok).toBe(true);
    });
  });
});
