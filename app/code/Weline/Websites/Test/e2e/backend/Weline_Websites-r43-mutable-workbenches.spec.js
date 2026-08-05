/**
 * R4.3 Websites mutable control-plane WebUI writes.
 *
 * @weline-e2e-spec { module: Weline_Websites, type: flow, layer: backend }
 */
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  openBackendMenuBySource,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'Weline_Websites-r43-mutable-workbenches-fixture.php');
const PARENT = 'Weline_Websites::website_service';
const CAPABILITIES = {
  website: {
    sourceId: 'Weline_Websites::website',
    parentSource: PARENT,
    urlIncludes: '/websites/admin/website/index',
    pageAnchor: '[data-testid="website-management"]',
  },
  domain: {
    sourceId: 'Weline_Websites::domain_service',
    parentSource: PARENT,
    urlIncludes: '/websites/admin/domain/index',
    pageAnchor: '[data-testid="domain-management"]',
  },
  site_builder: {
    sourceId: 'Weline_Websites::site_builder_agent',
    parentSource: PARENT,
    urlIncludes: '/websites/backend/site-builder-agent/index',
    pageAnchor: '[data-testid="site-builder-management"]',
  },
  maintenance: {
    sourceId: 'Weline_Websites::website_maintenance',
    parentSource: PARENT,
    urlIncludes: '/websites/backend/maintenance',
    pageAnchor: '[data-testid="website-maintenance-management"]',
  },
  backup: {
    sourceId: 'Weline_Websites::website_backup',
    parentSource: PARENT,
    urlIncludes: '/websites/backend/backup',
    pageAnchor: '[data-testid="website-backup-management"]',
  },
};

function fixture(action, payload = {}) {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 Websites mutable fixture requires WELINE_E2E_ISOLATED_DB=1');
  }
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const parsed = JSON.parse(String(output).trim().split(/\n/).filter(Boolean).at(-1) || '{}');
  if (!parsed.ok) {
    throw new Error('Websites mutable fixture ' + action + ' failed: ' + (parsed.error || output));
  }
  return parsed;
}

function token(prefix) {
  return prefix + Date.now().toString(36);
}

async function openCapability(page, capability) {
  await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
  await openBackendMenuBySource(page, capability.sourceId, capability);
  await waitForBackendShellReady(page);
  await expect(page.locator(capability.pageAnchor)).toBeVisible({ timeout: 30000 });
}

async function waitForReload(page, action, timeout = 120000) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout }),
    action(),
  ]);
  await waitForBackendShellReady(page);
}

function publicIdFromUrl(url) {
  return String(new URL(url).searchParams.get('public_id') || '');
}

moduleDescribe(test, MODULE, 'R4.3 Websites 可变工作台真实 WebUI 写入', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-WEBSITES-WEBSITE-001' },
    '从网站管理菜单通过表单创建 Website 并验证 PostgreSQL',
    async ({ page }) => {
      const data = fixture('prepare', { kind: 'website', token: token('w') });
      const guards = installBackendBrowserGuards(page);
      try {
        await openCapability(page, CAPABILITIES.website);
        const addButton = page.locator(
          'a[data-bs-target*="websites_add_website"], button[data-bs-target*="websites_add_website"], a[data-bs-toggle="offcanvas"].btn.btn-primary, button[data-bs-toggle="offcanvas"].btn.btn-primary'
        ).first();
        await expect(addButton).toBeVisible({ timeout: 15000 });
        await addButton.click({ force: true });
        await expect(page.locator('.offcanvas.show iframe').first()).toBeVisible({ timeout: 15000 });

        const frame = page.frameLocator('.offcanvas.show iframe').first();
        await expect(frame.locator('body')).toBeVisible({ timeout: 15000 });
        const poolInput = frame.locator('#website_domain_select_value, input[name="pool_ids"]').first();
        await poolInput.evaluate((input, poolId) => {
          input.value = String(poolId);
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }, data.pool_id);
        await frame.locator('input[name="name"], input[id*="name"]').first().fill(data.website_name);
        await frame.locator('input[name="code"], input[id*="code"]').first().fill(data.website_code);

        const saveButton = page.locator(
          '.offcanvas.show button[id*="Save"], .offcanvas.show button[type="submit"]'
        ).first();
        await expect(saveButton).toBeVisible({ timeout: 15000 });
        await saveButton.click({ force: true });

        await expect.poll(
          () => fixture('inspect', data).websites.length,
          { timeout: 60000, intervals: [300, 600, 1000] }
        ).toBe(1);
        const persisted = fixture('inspect', data);
        expect(persisted.websites[0].code).toBe(data.website_code);
        expect(persisted.websites[0].name).toBe(data.website_name);
        expect(persisted.website_domains.map((row) => row.domain)).toContain(data.domain);
      } finally {
        try {
          const cleanup = fixture('cleanup', data);
          expect(cleanup.remaining.websites).toEqual([]);
          expect(cleanup.remaining.website_domains).toEqual([]);
          expect(cleanup.remaining.pools).toEqual([]);
          expect(cleanup.remaining.root_domains).toEqual([]);
        } finally {
          guards.assertClean();
        }
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-WEBSITES-DOMAIN-001' },
    '从域名管理菜单手工创建隔离 .test 域名且不触发购买、DNS 或 HTTPS',
    async ({ page }) => {
      const data = fixture('prepare', { kind: 'domain', token: token('d') });
      const guards = installBackendBrowserGuards(page);
      try {
        await openCapability(page, CAPABILITIES.domain);
        await page.locator('[data-domain-list-action="showManualCreateDomainOffcanvas"]').click();
        await expect(page.locator('#weline-manual-domain')).toBeVisible({ timeout: 15000 });
        await page.locator('#weline-manual-domain').fill(data.domain);
        await page.locator('#weline-manual-description').fill(data.description);
        await page.locator('#weline-manual-https-mode').selectOption('none');
        await page.locator('#weline-manual-domain-submit').click();

        await expect.poll(
          () => fixture('inspect', data).pools.length,
          { timeout: 60000, intervals: [300, 600, 1000] }
        ).toBe(1);
        const persisted = fixture('inspect', data);
        expect(persisted.root_domains).toHaveLength(1);
        expect(persisted.pools).toHaveLength(1);
        expect(Number(persisted.root_domains[0].dns_account_id || 0)).toBe(0);
        expect(Number(persisted.root_domains[0].cdn_account_id || 0)).toBe(0);
        expect(persisted.pools[0].dns_status).toBe('ready');
        expect(persisted.pools[0].cdn_status).toBe('ready');
        expect(persisted.pools[0].https_status).toBe('none');
      } finally {
        try {
          const cleanup = fixture('cleanup', data);
          expect(cleanup.remaining.root_domains).toEqual([]);
          expect(cleanup.remaining.pools).toEqual([]);
          expect(cleanup.remaining.flow_logs).toEqual([]);
        } finally {
          guards.assertClean();
        }
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-WEBSITES-SITE-BUILDER-001' },
    '从 AI 建站菜单以本地 fake 方案创建工作区并验证 PostgreSQL 会话',
    async ({ page }) => {
      test.setTimeout(240000);
      const data = fixture('prepare', { kind: 'site_builder', token: token('a') });
      const guards = installBackendBrowserGuards(page);
      try {
        await openCapability(page, CAPABILITIES.site_builder);

        const fakeUrl = new URL(page.url());
        fakeUrl.searchParams.set('fake_mode', '1');
        await page.goto(fakeUrl.toString(), { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForBackendShellReady(page);
        await expect(page.locator(CAPABILITIES.site_builder.pageAnchor)).toBeVisible({ timeout: 30000 });

        await page.locator('#sbv1-desc').fill(data.description);
        await page.locator('#sbv1-generate').click();
        await expect(page.locator('#sbv1-plan-modal')).toHaveClass(/open/, { timeout: 30000 });
        await expect(page.locator('#sbv1-confirm-plan')).toBeEnabled({ timeout: 120000 });
        await page.locator('#sbv1-confirm-plan').click();

        await expect(page.locator('#sbv1-domain-modal')).toHaveClass(/open/, { timeout: 30000 });
        await page.locator('[data-pane-btn="manual"]').click();
        await page.locator('#sbv1-manual-domain').fill(data.domain);
        await page.locator('#sbv1-use-manual-domain').click();
        await expect(page.locator('#sbv1-domain-summary')).toContainText(data.domain, { timeout: 30000 });

        await page.locator('#sbv1-create-session').click();
        await page.waitForURL(/site-builder-agent\/workspace\?public_id=/, {
          timeout: 120000,
          waitUntil: 'domcontentloaded',
        });
        data.public_id = publicIdFromUrl(page.url());
        expect(data.public_id).toMatch(/^[a-zA-Z0-9_-]{8,}$/);

        await expect.poll(
          () => fixture('inspect', data).sessions.length,
          { timeout: 30000, intervals: [300, 600, 1000] }
        ).toBe(1);
        const persisted = fixture('inspect', data);
        expect(persisted.sessions[0].public_id).toBe(data.public_id);
        expect(persisted.sessions[0].provider_code).toBe('websites_default');
        expect(JSON.stringify(persisted.sessions[0].scope)).toContain(data.token);
        expect(persisted.event_count).toBeGreaterThan(0);
        expect(persisted.plan_draft_count).toBe(1);
        expect(persisted.plan_version_count).toBeGreaterThan(0);
      } finally {
        try {
          const cleanup = fixture('cleanup', data);
          expect(cleanup.remaining.sessions).toEqual([]);
          expect(cleanup.remaining.event_count).toBe(0);
          expect(cleanup.remaining.message_count).toBe(0);
          expect(cleanup.remaining.artifact_count).toBe(0);
          expect(cleanup.remaining.plan_draft_count).toBe(0);
          expect(cleanup.remaining.plan_version_count).toBe(0);
        } finally {
          guards.assertClean();
        }
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-WEBSITES-MAINTENANCE-001' },
    '从维护管理菜单切换 task-owned Website 并由 UI 恢复原状态',
    async ({ page }) => {
      const data = fixture('prepare', { kind: 'maintenance', token: token('m') });
      const guards = installBackendBrowserGuards(page);
      try {
        await openCapability(page, CAPABILITIES.maintenance);
        let toggle = page.locator(
          '[data-maintenance-action="toggle"][data-website-id="' + data.website_id + '"]'
        );
        await expect(toggle).toHaveAttribute('data-maintenance-enabled', '1');
        await waitForReload(page, () => toggle.click());

        await expect.poll(
          () => fixture('inspect', data).enabled,
          { timeout: 30000, intervals: [300, 600, 1000] }
        ).toBe(true);
        toggle = page.locator(
          '[data-maintenance-action="toggle"][data-website-id="' + data.website_id + '"]'
        );
        await expect(toggle).toHaveAttribute('data-maintenance-enabled', '0');
        await waitForReload(page, () => toggle.click());

        await expect.poll(
          () => fixture('inspect', data).enabled,
          { timeout: 30000, intervals: [300, 600, 1000] }
        ).toBe(false);
        const restored = fixture('inspect', data);
        expect(restored.enabled).toBe(data.initial_enabled);
        expect(restored.audit_count).toBeGreaterThanOrEqual(2);
      } finally {
        try {
          const cleanup = fixture('cleanup', data);
          expect(cleanup.remaining.websites).toEqual([]);
          expect(cleanup.remaining.states).toEqual([]);
          expect(cleanup.remaining.audit_count).toBe(0);
        } finally {
          guards.assertClean();
        }
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-WEBSITES-BACKUP-001' },
    '从备份管理菜单创建 files 归档并验证文件后精确清理',
    async ({ page }) => {
      test.setTimeout(180000);
      const data = fixture('prepare', { kind: 'backup', token: token('b') });
      const guards = installBackendBrowserGuards(page);
      let createdBackup = false;
      try {
        await openCapability(page, CAPABILITIES.backup);
        await page.locator('#website_id').selectOption(String(data.website_id));
        await page.locator('#backup_type').selectOption('files');
        const submit = page.locator('[data-backup-submit]');
        await submit.click();
        await expect.poll(
          () => submit.getAttribute('data-backup-state'),
          { timeout: 150000, intervals: [250, 500, 1000] }
        ).toMatch(/^(created|failed)$/);
        const backupState = await submit.getAttribute('data-backup-state');
        const backupError = await submit.getAttribute('data-backup-error');
        expect(backupState, backupError || 'website backup did not reach created state').toBe('created');
        createdBackup = true;
        await expect(submit).toHaveAttribute('data-backup-filename', /website-.*-files-/);

        await expect.poll(
          () => fixture('inspect', data).backups.length,
          { timeout: 60000, intervals: [500, 1000, 1500] }
        ).toBe(1);
        const persisted = fixture('inspect', data);
        expect(persisted.backups[0].filename).toContain('website-' + data.website_id + '-');
        expect(persisted.backups[0].filename).toContain('-files-');
        expect(persisted.backups[0].exists).toBe(true);
        expect(Number(persisted.backups[0].size)).toBeGreaterThan(0);
        expect(persisted.backups[0].sha256).toMatch(/^[a-f0-9]{64}$/);
      } finally {
        try {
          const beforeCleanup = fixture('inspect', data);
          const cleanup = fixture('cleanup', data);
          expect(cleanup.deleted_backups).toBe(beforeCleanup.backups.length);
          expect(cleanup.remaining.backups).toEqual([]);
          expect(cleanup.remaining.websites).toEqual([]);
        } finally {
          guards.assertClean();
        }
      }
    }
  );
});
