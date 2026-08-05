/** @weline-e2e-spec { module: Weline_Search, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Search';
const PARENT = 'Weline_Search::commerce:tax-search:control-center';
const FIXTURE = path.join(__dirname, 'Weline_Search-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['config', '搜索配置', 'CK-R43-SEARCH-001'],
  ['generations', '索引代次', 'CK-R43-SEARCH-002'],
  ['incremental', '增量状态', 'CK-R43-SEARCH-003'],
  ['degraded', '降级状态', 'CK-R43-SEARCH-004'],
  ['migration', '迁移状态', 'CK-R43-SEARCH-005'],
];

moduleDescribe(test, MODULE, 'R4.3 搜索后台菜单', () => {
  for (const [code, title, caseId] of ITEMS) {
    moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
      const guards = installBackendBrowserGuards(page);
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, `Weline_Search::commerce:tax-search:${code}`, {
        parentSources: [PARENT], title, pageAnchor: `[data-testid="search-${code}-management"]`,
      });
      await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
      guards.assertClean();
    });
  }

  moduleCase(test, { module: MODULE, id: 'CK-R43-SEARCH-WRITE-001' }, '搜索配置通过菜单切换安全运行模式并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const guards = installBackendBrowserGuards(page);
    const snapshot = runFixture({ action: 'prepare' });
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, 'Weline_Search::commerce:tax-search:config', {
        parentSources: [PARENT], title: '搜索配置', pageAnchor: '[data-testid="search-config-management"]',
      });
      await page.getByTestId('search-rollout-mode').selectOption(snapshot.target_mode);
      await page.getByTestId('search-config-submit').click();
      await page.waitForLoadState('domcontentloaded');
      await expect(page.getByTestId('search-rollout-mode')).toHaveValue(snapshot.target_mode);
      const persisted = runFixture({ action: 'inspect', expected_mode: snapshot.target_mode });
      expect(persisted.actual_mode).toBe(snapshot.target_mode);
      expect(persisted.allowlist_count).toBe(0);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup', original_mode: snapshot.original_mode, original_subjects: snapshot.original_subjects });
    }
  });
});

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], {
    cwd: REPO_ROOT,
    input: JSON.stringify(payload),
    encoding: 'utf8',
  });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) {
    throw new Error(`Search fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  }
  return decoded;
}

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 Search write cases require WELINE_E2E_ISOLATED_DB=1');
  }
}
