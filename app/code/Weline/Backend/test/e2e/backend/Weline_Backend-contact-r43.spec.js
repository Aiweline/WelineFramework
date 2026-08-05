/**
 * @weline-e2e-spec { module: Weline_Backend, type: flow, layer: backend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  installBackendBrowserGuards,
  loginAsAdmin,
  moduleCase,
  moduleDescribe,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Backend';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'commerce-r43-contact-fixture.php');

function fixture(action, name) {
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, name }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const result = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).at(-1) || '{}');
  if (!result.ok) throw new Error(result.error || output);
  return result;
}

moduleDescribe(test, MODULE, 'R4.3 客户联系人真实写入', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-CONTACT-001' },
    '从客户管理菜单创建联系人并由 PostgreSQL 断言持久化',
    async ({ page }) => {
      if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
        throw new Error('CK-R43-CONTACT-001 requires WELINE_E2E_ISOLATED_DB=1');
      }
      const name = `R43 联系人 ${Date.now()} ${process.pid}`;
      fixture('cleanup', name);
      const guards = installBackendBrowserGuards(page);

      try {
        await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
        await openBackendMenuBySource(page, 'Weline_Backend::contact', {
          parentSources: ['Weline_Backend::business_operations', 'Weline_Backend::customer_group'],
          title: '联系人',
          urlIncludes: '/system/backend/contact/index',
          pageAnchor: '[data-testid="contact-management"]',
        });

        await page.locator('[data-contact-action="add-contact"]').first().click();
        await expect(page.locator('#contactNameModal')).toBeVisible();
        await page.locator('#contactNameInput').fill(name);
        await page.locator('[data-contact-action="save-contact"]').click();
        await expect(page.getByText(name, { exact: true }).first()).toBeVisible({ timeout: 30000 });

        const persisted = fixture('assert', name);
        expect(persisted.count).toBe(1);
        expect(persisted.ids[0]).toBeGreaterThan(0);
        guards.assertClean();
      } finally {
        fixture('cleanup', name);
      }
    }
  );
});
