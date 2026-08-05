/** @weline-e2e-spec { module: Weline_Seo, type: flow, layer: backend } */
const path = require('path');
const { spawnSync } = require('child_process');
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Seo';
const PARENT = 'Weline_Backend::seo_group';
const FIXTURE = path.join(__dirname, 'Weline_Seo-r43-write-fixture.php');
const REPO_ROOT = path.resolve(__dirname, '../../../../../../../');
const ITEMS = [
  ['Weline_Seo::seo_dashboard', 'SEO总览', 'seo-dashboard-management', 'CK-R43-SEO-001'],
  ['Weline_Seo::seo_embed', '主体管理', 'seo-subject-management', 'CK-R43-SEO-002'],
  ['Weline_Seo::seo_account', 'SEO账户', 'seo-account-management', 'CK-R43-SEO-003'],
  ['Weline_Seo::website_account', '站点账户绑定', 'seo-website-account-management', 'CK-R43-SEO-004'],
  ['Weline_Seo::sitemap_management', 'Sitemap管理', 'seo-sitemap-management', 'CK-R43-SEO-005'],
];
moduleDescribe(test, MODULE, 'R4.3 SEO 后台菜单', () => {
  for (const [source, title, anchor, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
    guards.assertClean();
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-SEO-WRITE-002' }, '主体管理通过菜单创建 SEO 主体并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_subject' });
    const guards = installBackendBrowserGuards(page);
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[1][0], { parentSources: [PARENT], title: ITEMS[1][1], pageAnchor: `[data-testid="${ITEMS[1][2]}"]` });
      await page.getByRole('button', { name: '添加主体', exact: true }).click();
      const form = page.locator('#addSubjectForm');
      await expect(form).toBeVisible();
      await form.locator('[name="title"]').fill(fixture.title);
      await form.locator('[name="url"]').fill(fixture.url);
      await form.locator('[name="description"]').fill(`R43 browser mutation ${fixture.token}`);
      await form.locator('[name="subject_type"]').selectOption('page');
      await form.locator('[name="status"]').selectOption('1');
      await form.locator('[type="submit"]').click();
      await expect(page.locator('body')).toContainText(fixture.title, { timeout: 10000 });
      const persisted = runFixture({ action: 'inspect_subject', token: fixture.token });
      expect(persisted.subject_id).toBeGreaterThan(0);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_subject', token: fixture.token });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-SEO-WRITE-003' }, 'SEO 账户通过菜单创建本地配置并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_account' });
    const guards = installBackendBrowserGuards(page);
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[2][0], { parentSources: [PARENT], title: ITEMS[2][1], pageAnchor: `[data-testid="${ITEMS[2][2]}"]` });
      await page.getByRole('link', { name: '新增账户', exact: true }).click();
      const form = page.locator('[data-seo-account-form]');
      await expect(form).toBeVisible();
      await form.locator('[name="name"]').fill(fixture.name);
      await form.locator('[name="platform"]').selectOption(fixture.platform);
      await form.locator('[name="scope"]').fill('r43');
      await form.locator('[name="description"]').fill(`R43 browser account ${fixture.token}`);
      await form.locator('[name="config_json"]').fill('{}');
      await form.locator('[name="is_active"]').check();
      for (const name of ['enable_cron_sitemap', 'enable_cron_push_urls']) {
        const checkbox = form.locator(`[name="${name}"]`);
        if (await checkbox.isChecked()) await checkbox.uncheck();
      }
      await form.locator('[type="submit"]').click();
      await expect(page.locator('body')).toContainText(fixture.name, { timeout: 12000 });
      const persisted = runFixture({ action: 'inspect_account', token: fixture.token, platform: fixture.platform });
      expect(persisted.account_id).toBeGreaterThan(0);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_account', token: fixture.token });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-SEO-WRITE-004' }, '站点账户页通过菜单绑定隔离 SEO 账户并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_binding' });
    const guards = installBackendBrowserGuards(page);
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[3][0], { parentSources: [PARENT], title: ITEMS[3][1], pageAnchor: `[data-testid="${ITEMS[3][2]}"]` });
      const websiteRow = page.locator('tbody tr').filter({ hasText: fixture.website_name }).first();
      await expect(websiteRow).toBeVisible();
      await websiteRow.getByRole('link', { name: '管理绑定', exact: true }).click();
      const widget = page.locator('[data-seo-website-account-widget]');
      await expect(widget).toBeVisible();
      const card = widget.locator(`[data-account-card="${fixture.account_id}"]`);
      await expect(card).toContainText(fixture.name);
      await card.locator('[data-account-checkbox]').check();
      await card.locator('select[name*="[sitemap_frequency]"]').selectOption('daily');
      await card.locator('select[name*="[crawl_frequency]"]').selectOption('weekly');
      await card.locator('input[name*="[priority]"]').fill('0.7');
      const autoSubmit = card.locator('input[type="checkbox"][name*="[is_auto_submit]"]');
      if (await autoSubmit.isChecked()) await autoSubmit.uncheck();
      const urlPush = card.locator('input[type="checkbox"][name*="[enable_url_push]"]');
      if (await urlPush.isEnabled() && await urlPush.isChecked()) await urlPush.uncheck();
      await widget.locator('[data-role="seo-submit"]').click();
      await expect(widget.locator('[data-role="seo-message"]')).toContainText('绑定配置保存成功', { timeout: 10000 });
      const persisted = runFixture({ action: 'inspect_binding', account_id: fixture.account_id, website_id: fixture.website_id });
      expect(persisted.binding_id).toBeGreaterThan(0);
      expect(persisted.priority).toBe(0.7);
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_binding', account_id: fixture.account_id });
    }
  });

  moduleCase(test, { module: MODULE, id: 'CK-R43-SEO-WRITE-005' }, 'Sitemap 通过菜单编辑隔离 URL 并持久化', async ({ page }) => {
    requireIsolatedDatabase();
    const fixture = runFixture({ action: 'prepare_sitemap' });
    const guards = installBackendBrowserGuards(page);
    try {
      await loginAsAdmin(page);
      await openBackendMenuBySource(page, ITEMS[4][0], { parentSources: [PARENT], title: ITEMS[4][1], pageAnchor: `[data-testid="${ITEMS[4][2]}"]` });
      const site = page.locator(`[data-seo-site][data-website-id="${fixture.website_id}"]`);
      await site.locator('[data-seo-manage-urls]').click();
      const row = page.locator(`[data-seo-url-tbody] tr[data-url-id="${fixture.url_id}"]`);
      await expect(row).toContainText(fixture.url, { timeout: 10000 });
      await row.locator('[data-seo-url-changefreq]').selectOption('daily');
      await row.locator('[data-seo-url-priority]').fill('0.8');
      await row.locator('[data-seo-url-save]').click();
      await expect(row.locator('[data-seo-url-priority]')).toHaveValue('0.8', { timeout: 10000 });
      const persisted = runFixture({ action: 'inspect_sitemap', url_id: fixture.url_id });
      expect(persisted.priority).toBe('0.8');
      expect(persisted.changefreq).toBe('daily');
      guards.assertClean();
    } finally {
      runFixture({ action: 'cleanup_sitemap', token: fixture.token, url_id: fixture.url_id });
    }
  });
});

function requireIsolatedDatabase() {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') throw new Error('R4.3 SEO write cases require WELINE_E2E_ISOLATED_DB=1');
}

function runFixture(payload) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', [FIXTURE], { cwd: REPO_ROOT, input: JSON.stringify(payload), encoding: 'utf8' });
  const lines = String(result.stdout || '').trim().split(/\r?\n/).filter(Boolean);
  let decoded = null;
  try { decoded = JSON.parse(lines[lines.length - 1] || '{}'); } catch (_) {}
  if (result.status !== 0 || !decoded || decoded.ok !== true) throw new Error(`SEO fixture failed: ${decoded?.error || result.stderr || result.stdout}`);
  return decoded;
}
