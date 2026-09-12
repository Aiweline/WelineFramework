/**
 * Catalog bulk archive chain (published→disabled→archived) + idempotent + failed counts.
 *
 * @weline-e2e-spec { module: Weline_Product, type: feature, layer: backend }
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
const FIXTURE_SCRIPT = path.resolve(__dirname, 'catalog-bulk-archive-chain-fixture.php');

function runFixture() {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  return JSON.parse(lines[lines.length - 1] || '{}');
}

moduleDescribe(test, MODULE, '商品目录批量删除归档链路', () => {
  moduleCase(test, { module: MODULE, id: 'CK-PRODUCT-BULK-ARCHIVE-CHAIN-001' }, 'fixture：链式归档/幂等/JS failed', async () => {
    const result = runFixture();
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.wired_chain).toBe(true);
    expect(result.wired_js).toBe(true);
    expect(result.wired_hide_archived).toBe(true);
    expect(result.wired_uuid_purge).toBe(true);
    expect(result.unit_ok).toBe(true);
  });
});
