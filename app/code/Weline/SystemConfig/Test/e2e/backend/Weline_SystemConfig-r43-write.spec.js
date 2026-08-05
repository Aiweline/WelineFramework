/**
 * R4.3 SystemConfig write acceptance. The browser performs filter, edit and
 * submit; the fixture only snapshots/asserts/restores PostgreSQL state.
 *
 * @weline-e2e-spec { module: Weline_SystemConfig, type: flow, layer: backend }
 */
const path = require('path');
const { spawnSync } = require('child_process');
const {
  test,
  expect,
  installBackendBrowserGuards,
  loginAsAdmin,
  moduleCase,
  moduleDescribe,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_SystemConfig';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'commerce-r43-system-config-write-fixture.php');
const GRANT_FIXTURE = path.join(__dirname, 'plan-sec05-grant-fixture.php');

function phpFixture(file, label, action, payload = {}) {
  const result = spawnSync('php', [file], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  if (result.error) throw result.error;
  const output = String(result.stdout || '');
  const parsed = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).at(-1) || '{}');
  if (result.status !== 0 || !parsed.ok) {
    throw new Error(`R4.3 ${label} fixture ${action} failed: ${parsed.error || result.stderr || output}`);
  }
  return parsed;
}
function fixture(action, payload = {}) { return phpFixture(FIXTURE, 'SystemConfig', action, payload); }
function grantFixture(action, payload = {}) { return phpFixture(GRANT_FIXTURE, 'SystemConfig ACL', action, payload); }

moduleDescribe(test, MODULE, 'R4.3 SystemConfig 真实 WebUI 写操作', () => {
  test.setTimeout(180000);
  test.beforeEach(() => {
    if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
      throw new Error('R4.3 SystemConfig write cases require WELINE_E2E_ISOLATED_DB=1');
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-SYSTEMCONFIG-WRITE-101' },
    '从配置中心菜单筛选、修改、保存并验证 PostgreSQL',
    async ({ page }) => {
      const data = fixture('prepare');
      const actor = grantFixture('prepare');
      const guards = installBackendBrowserGuards(page);
      let persisted = false;
      try {
        await loginAsAdmin(page, {
          username: actor.username,
          password: actor.password,
          timeout: 90000,
          settleMs: 800,
          useProxy: false,
        });
        await openBackendMenuBySource(page, 'Weline_SystemConfig::config_center', {
          urlIncludes: '/weline_systemconfig/backend/config',
          pageAnchor: '[data-testid="system-config-management"]',
        });

        const filter = page.locator('#wsc-filter-form');
        await filter.locator('[name="search"]').fill(data.key);
        await Promise.all([
          page.waitForLoadState('domcontentloaded'),
          filter.locator('button[type="submit"]').click(),
        ]);

        const row = page.locator(`[data-testid="system-config-field"][data-config-key="${data.key}"]`);
        await expect(row).toBeVisible({ timeout: 30000 });
        const form = row.locator('xpath=ancestor::form[1]');
        const input = row.locator(`[name="values[${data.key}]"]`);
        await input.fill(data.value);
        await form.locator('[name="reason"]').fill(data.reason);
        await Promise.all([
          page.waitForLoadState('domcontentloaded'),
          form.locator('button[type="submit"]').click(),
        ]);
        await expect(page.locator('body')).toContainText(/配置已保存|saved/i, { timeout: 30000 });
        const result = fixture('assert', data);
        expect(result.value).toBe(data.value);
        expect(result.version).toBeGreaterThan(0);
        persisted = true;
        guards.assertClean();
      } finally {
        const cleanupFailures = [];
        try {
          const cleanup = fixture('cleanup', data);
          if (persisted) expect(cleanup.deleted_version_ids.length).toBeGreaterThan(0);
        } catch (error) {
          cleanupFailures.push(`config:${error && (error.stack || error.message || error)}`);
        }
        try {
          grantFixture('cleanup', { role_id: actor.role_id, user_id: actor.user_id });
        } catch (error) {
          cleanupFailures.push(`actor:${error && (error.stack || error.message || error)}`);
        }
        expect(cleanupFailures, cleanupFailures.join('\n')).toEqual([]);
      }
    }
  );
});
