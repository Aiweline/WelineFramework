/** @weline-e2e-spec { module: Weline_Tax, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Tax';
const PARENT = 'Weline_Tax::commerce:tax-search:control-center';
const FIXTURE = path.join(__dirname, 'Weline_Tax-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['classes', '税类', 'CK-R43-TAX-001'],
  ['rates', '税率', 'CK-R43-TAX-007'],
  ['rules', '税务规则', 'CK-R43-TAX-002'],
  ['engine', '税引擎状态', 'CK-R43-TAX-003'],
  ['shadow', '影子验证', 'CK-R43-TAX-004'],
  ['lkg', '已验证 LKG', 'CK-R43-TAX-005'],
  ['migration', '迁移状态', 'CK-R43-TAX-006'],
];

moduleDescribe(test, MODULE, 'R4.3 税务后台菜单', () => {
  for (const [code, title, caseId] of ITEMS) {
    moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
      const guards = installBackendBrowserGuards(page);
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, `Weline_Tax::commerce:tax-search:${code}`, {
        parentSources: [PARENT], title, pageAnchor: `[data-testid="tax-${code}-management"]`,
      });
      await expect(page.locator('body')).not.toContainText(/WLS Runtime Error|Fatal error|ParseError/i);
      guards.assertClean();
    });
  }

  for (const [kind, sourceIndex, caseId] of [
    ['class', 0, 'CK-R43-TAX-WRITE-001'],
    ['rate', 1, 'CK-R43-TAX-WRITE-007'],
    ['rule', 2, 'CK-R43-TAX-WRITE-002'],
  ]) {
    moduleCase(test, { module: MODULE, id: caseId }, `${ITEMS[sourceIndex][1]}通过菜单创建配置并持久化`, async ({ page }) => {
      requireIsolatedDatabase();
      const fixture = runFixture({ action: `prepare_${kind}` });
      const guards = installBackendBrowserGuards(page);
      try {
        const [code, title] = ITEMS[sourceIndex];
        await loginAsAdmin(page);
        await openBackendMenuBySource(page, `Weline_Tax::commerce:tax-search:${code}`, {
          parentSources: [PARENT], title, pageAnchor: `[data-testid="tax-${code}-management"]`,
        });
        const form = page.getByTestId(`tax-${code}-create-form`);
        await form.locator('[name="website_id"]').fill('0');
        await form.locator('[name="class_code"]').fill(fixture.class_code);
        if (kind === 'class') {
          await form.locator('[name="name"]').fill(fixture.class_name);
        } else {
          await form.locator('[name="jurisdiction_key"]').fill(fixture.jurisdiction_key);
          await form.locator('[name="rate_bps"]').fill(kind === 'rule' ? '825' : '725');
          if (kind === 'rule') await form.locator('[name="rule_version"]').fill('7');
        }
        await page.getByTestId(`tax-${code}-submit`).click();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(fixture.class_code);
        const persisted = runFixture({ action: `inspect_${kind}`, token: fixture.token });
        expect(kind === 'class' ? persisted.tax_class_id : persisted.tax_rule_id).toBeGreaterThan(0);
        guards.assertClean();
      } finally {
        runFixture({ action: 'cleanup', token: fixture.token });
      }
    });
  }
});

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 Tax write cases require WELINE_E2E_ISOLATED_DB=1');
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`Tax fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}
