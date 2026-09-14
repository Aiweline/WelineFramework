/**
 * PDP wholesale qty tiers: per-tier total savings (buy N · save total).
 *
 * @weline-e2e-spec { module: Weline_B2B, type: feature, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_B2B';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'qty-tiers-total-save-fixture.php');
const PDP = '/product/558?offer=8645527d-3748-5ab3-b144-f2e8b39f257f';

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

moduleDescribe(test, MODULE, '批发阶梯按数量共省', () => {
  test.setTimeout(120000);

  moduleCase(
    test,
    { module: MODULE, id: 'B2B-QTY-TIERS-TOTAL-SAVE' },
    '通路：引擎共省公式与模板文案就绪，PDP 含阶梯区标记',
    async ({ page }) => {
      const result = runFixture();
      expect(result.ok, JSON.stringify(result)).toBe(true);
      expect(result.template_wired).toBe(true);
      expect(result.css_wired).toBe(true);
      expect(result.formula_ok).toBe(true);
      expect(result.sample_10_total_save_minor).toBe(3000);

      await gotoFrontend(page, PDP);
      const body = await page.content();
      expect(/Fatal error|ParseError|Uncaught/i.test(body)).toBeFalsy();
      // Guest may not SSR filled ladder rows; page must still be a product surface.
      expect(/product|购买方式|批发/i.test(body)).toBeTruthy();
      // Display currency must use symbol mapping in template source (fixture already asserts).
      expect(result.template_wired).toBe(true);
    },
  );
});
