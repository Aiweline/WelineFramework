/**
 * R4.3 ACL control-plane writes: every decisive mutation is made in WebUI.
 * Fixtures only inspect PostgreSQL and restore the exact pre-test state.
 *
 * @weline-e2e-spec { module: Weline_Acl, type: flow, layer: backend }
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

const MODULE = 'Weline_Acl';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'commerce-r43-acl-write-fixture.php');

function fixture(action, payload = {}) {
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const parsed = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).at(-1) || '{}');
  if (!parsed.ok) throw new Error(`R4.3 ACL fixture ${action} failed: ${parsed.error || output}`);
  return parsed;
}

async function openMenu(page, sourceId, urlIncludes, pageAnchor) {
  await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
  await openBackendMenuBySource(page, sourceId, { urlIncludes, pageAnchor });
}

moduleDescribe(test, MODULE, 'R4.3 ACL 真实 WebUI 写操作', () => {
  test.setTimeout(180000);
  test.beforeEach(() => {
    if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
      throw new Error('R4.3 ACL write cases require WELINE_E2E_ISOLATED_DB=1');
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-WRITE-101' },
    '从权限角色菜单创建角色并验证 PostgreSQL',
    async ({ page }) => {
      const data = fixture('prepare');
      const guards = installBackendBrowserGuards(page);
      let persisted = false;
      try {
        await openMenu(page, 'Weline_Acl::acl_role', '/acl/backend/acl/role', '[data-testid="acl-role-management"]');
        await page.locator('[data-testid="acl-role-add"]').click();
        const form = page.locator('#roleAddForm');
        await expect(form).toBeVisible();
        await form.locator('[name="role_name"]').fill(data.role_name);
        await form.locator('[name="role_description"]').fill(data.role_description);
        await form.locator('button[type="submit"]').click();
        await expect(page.locator(`[data-testid="acl-role-row"][data-role-name="${data.role_name}"]`))
          .toBeVisible({ timeout: 30000 });
        const result = fixture('assert', { kind: 'role', ...data });
        expect(result.role_id).toBeGreaterThan(1);
        persisted = true;
        guards.assertClean();
      } finally {
        const cleanup = fixture('cleanup', data);
        if (persisted) expect(cleanup.deleted_role).toBe(1);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-WRITE-102' },
    '从权限标签菜单修改元数据并精确恢复 preimage',
    async ({ page }) => {
      const data = fixture('prepare');
      const guards = installBackendBrowserGuards(page);
      let persisted = false;
      try {
        await openMenu(page, 'Weline_Acl::acl_tag', '/acl/backend/acl/tag', '[data-testid="acl-tag-management"]');
        const row = page.locator(`[data-testid="acl-tag-row"][data-tag="${data.tag}"]`);
        await expect(row).toBeVisible();
        await row.locator('[name="display_name"]').fill(data.tag_display_name);
        await row.locator('[name="description"]').fill(data.tag_description);
        await row.locator('[name="color"]').fill(data.tag_color);
        await row.locator('[name="sort_order"]').fill(String(data.tag_sort_order));
        await Promise.all([
          page.waitForLoadState('domcontentloaded'),
          row.locator('.weline-acl-tag-btn-save').click(),
        ]);
        const persisted = fixture('assert', { kind: 'tag', ...data });
        expect(persisted.tag).toBe(data.tag);
        guards.assertClean();
      } finally {
        const cleanup = fixture('cleanup', data);
        expect(cleanup.tag_restored).toBeTruthy();
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-ACL-WRITE-103' },
    '从 IP 白名单菜单创建禁用态规则并验证 PostgreSQL',
    async ({ page }) => {
      const data = fixture('prepare');
      const guards = installBackendBrowserGuards(page);
      let persisted = false;
      try {
        await openMenu(page, 'Weline_Acl::ip_whitelist', '/acl/backend/ip-whitelist', '[data-testid="acl-ip-whitelist-management"]');
        await page.locator('[data-ip-whitelist-action="add"]').click();
        const form = page.locator('#ipWhitelistForm');
        await expect(form).toBeVisible();
        await form.locator('[name="ip"]').fill(data.ip);
        await form.locator('[name="description"]').fill(data.ip_description);
        await form.locator('#is_active').uncheck();
        await form.locator('button[type="submit"]').click();
        await expect.poll(async () => {
          if (await page.locator(`[data-testid="acl-ip-whitelist-row"][data-ip="${data.ip}"]`).count()) {
            return 'created';
          }
          const state = await form.getAttribute('data-ip-whitelist-state').catch(() => null);
          const error = await form.getAttribute('data-ip-whitelist-error').catch(() => null);
          return state === 'failed' ? `failed:${error || 'unknown'}` : (state || 'idle');
        }, {
          message: 'IP whitelist WebUI submission must reach a terminal state',
          timeout: 20000,
        }).toBe('created');
        await expect(page.locator(`[data-testid="acl-ip-whitelist-row"][data-ip="${data.ip}"]`))
          .toBeVisible({ timeout: 30000 });
        const result = fixture('assert', { kind: 'ip', ...data });
        expect(result.is_active).toBe(0);
        persisted = true;
        guards.assertClean();
      } finally {
        const cleanup = fixture('cleanup', data);
        if (persisted) expect(cleanup.deleted_ip).toBe(1);
      }
    }
  );
});
