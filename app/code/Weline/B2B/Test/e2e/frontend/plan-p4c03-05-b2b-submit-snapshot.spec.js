/**
 * TASK-P4C-002: Channel isolation, stale quote conflict and frozen snapshot.
 *
 * Mutating setup stays out of band. The browser verifies the official
 * Customer layout and that the public B2B provider remains read-only.
 *
 * @weline-e2e-spec { module: Weline_B2B, type: plan, layer: frontend }
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
const FIXTURE = path.resolve(__dirname, 'plan-p4c03-05-b2b-submit-snapshot-fixture.php');
const DIRECT = { useProxy: false };
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function runFixture() {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  return JSON.parse(lines[lines.length - 1] || '{}');
}

async function ensureApi(page) {
  await page.waitForFunction(
    () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
    undefined,
    { timeout: 30000 },
  );
}

moduleDescribe(test, MODULE, '计划 P4C-03/04/05 B2B 提交与快照', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P4C-03-05' },
    'Channel 隔离、旧 quote 冲突、旧 Order 快照冻结且浏览器 API 只读',
    async ({ page }) => {
      const evidence = runFixture();
      expect(evidence.ok, JSON.stringify(evidence)).toBeTruthy();
      expect(evidence.channel_a_amount).toBe(700);
      expect(evidence.channel_a_list).toBe('pl-e2e-a');
      expect(evidence.channel_b_amount).toBe(720);
      expect(evidence.channel_b_list).toBe('pl-e2e-b');
      expect(evidence.accepted).toBeTruthy();
      expect(evidence.accepted_count).toBe(1);
      expect(evidence.stale_rejected).toBeTruthy();
      expect(evidence.stale_error).toBe(evidence.expected_stale_error);
      expect(evidence.frozen_amount).toBe(800);
      expect(evidence.frozen_version).toBe(1);
      expect(evidence.frozen_hash_unchanged).toBeTruthy();

      await gotoFrontend(page, '/customer/account/login', DIRECT);
      await expect(page.locator('body')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
      await ensureApi(page);

      const operations = await page.evaluate(async () => {
        const b2b = await window.Weline.Api.resource('b2b');
        const forbidden = {};
        for (const operation of ['configureHarness', 'clearHarness', 'issueQuote', 'submit']) {
          try {
            const result = await b2b[operation]({});
            forbidden[operation] = {
              rejected: !!(result && result.success === false),
              result,
            };
          } catch (error) {
            forbidden[operation] = {
              rejected: true,
              message: String(error && (error.message || error)),
            };
          }
        }
        return {
          resolve: typeof (b2b && b2b.resolve),
          forbidden,
        };
      });
      expect(operations.resolve).toBe('function');
      for (const operation of ['configureHarness', 'clearHarness', 'issueQuote', 'submit']) {
        expect(
          operations.forbidden[operation].rejected,
          `${operation}: ${JSON.stringify(operations.forbidden[operation])}`,
        ).toBeTruthy();
      }
    },
  );
});
